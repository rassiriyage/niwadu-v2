<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_classification_drafts_stay_private_until_reviewed_and_facets_use_released_facts(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $this->actingAs($admin);
        $destination = $this->postJson('/api/v1/catalog/destinations', ['name' => 'Synthetic Coast', 'slug' => 'synthetic-coast'])->assertCreated()->json('data.id');
        $hotel = Hotel::factory()->create(['description' => 'Synthetic fixture', 'onboarding_data' => ['property_type' => 'villa']]);
        $uri = '/api/v1/hotels/'.$hotel->id;
        $fields = ['destination_id' => $destination, 'district' => 'trincomalee', 'themes' => ['beach', 'adventure'], 'amenities' => ['wifi', 'pool'], 'editorial_rank' => 1, 'review_note' => 'Private verification reference'];
        $this->putJson($uri.'/classification', ['onboarding_version' => 0, 'fields' => $fields])->assertOk()->assertJsonPath('data.onboarding_version', 1);
        $this->getJson('/api/v1/public/hotels?sort=name&themes[]=beach')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson($uri.'/discovery', ['onboarding_version' => 0, 'discovery_version' => 0, 'slug' => 'synthetic-classified'])->assertConflict();
        $this->putJson($uri.'/discovery', ['onboarding_version' => 1, 'discovery_version' => 0, 'slug' => 'synthetic-classified'])->assertOk();
        $url = '/api/v1/public/hotels?sort=editorial&destination=synthetic-coast&district=trincomalee&region=north-east&themes[]=wild&themes[]=beach&amenities[]=wifi&amenities[]=pool';
        $result = $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.destination.slug', 'synthetic-coast')->assertJsonPath('data.0.star_classification', null);
        $this->assertStringNotContainsString('Private verification reference', $result->getContent());
        $this->assertStringNotContainsString('editorial_rank', $result->getContent());
        $this->getJson('/api/v1/public/hotels?sort=name&q=Synthetic%20Coast')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/public/hotels?sort=name&amenities[]=wifi&amenities[]=parking')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/discovery-options')->assertOk()->assertJsonPath('data.themes.0.key', 'beach')->assertJsonPath('data.destinations.0.key', 'synthetic-coast');
        $fields['themes'] = ['city'];
        $fields['editorial_rank'] = null;
        $this->putJson($uri.'/classification', ['onboarding_version' => 1, 'fields' => $fields])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson($uri.'/discovery', ['discovery_version' => 1])->assertOk();
        $this->getJson('/api/v1/public/hotels?sort=editorial')->assertUnprocessable();
        $this->getJson('/api/v1/public/hotels?sort=name&destination=synthetic-coast')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unknown_facets_and_unreviewed_editorial_cannot_broaden_search(): void
    {
        foreach (['themes[]=north', 'themes=beach', 'amenities[]=unknown', 'district=unknown', 'destination=unknown', 'region=unknown', 'min_stars=3', 'district=galle&district=kandy', 'themes[0]=beach'] as $query) {
            $this->getJson('/api/v1/public/hotels?sort=name&'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/v1/public/hotels?sort=name&themes[]=beach&district=galle')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/hotels?sort=editorial')->assertUnprocessable();
    }

    public function test_classification_writes_require_platform_review_and_never_accept_stars(): void
    {
        $hotel = Hotel::factory()->create();
        $user = User::factory()->create();
        $hotel->users()->attach($user, ['role' => 'hotel_manager']);
        $this->actingAs($user)->putJson('/api/v1/hotels/'.$hotel->id.'/classification', [])->assertForbidden();
        $other = Hotel::factory()->create();
        $this->getJson('/api/v1/hotels/'.$other->id.'/classification')->assertNotFound();
        $this->postJson('/api/v1/catalog/destinations', ['name' => 'Synthetic', 'slug' => 'synthetic'])->assertForbidden();
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $fields = ['destination_id' => null, 'district' => null, 'themes' => [], 'amenities' => [], 'editorial_rank' => null, 'review_note' => 'Reviewed explicit unknowns.'];
        $uri = '/api/v1/hotels/'.$hotel->id.'/classification';
        $this->putJson($uri, ['onboarding_version' => 0, 'fields' => $fields + ['star_classification' => 5]])->assertUnprocessable();
        $this->putJson($uri, ['onboarding_version' => 0, 'fields' => array_replace($fields, ['review_note' => null])])->assertUnprocessable();
        $this->putJson($uri, ['onboarding_version' => 0, 'fields' => $fields])->assertOk();
        $this->putJson($uri, ['onboarding_version' => 0, 'fields' => $fields])->assertConflict();
        $this->assertDatabaseHas('hotel_access_events', ['hotel_id' => $hotel->id, 'action' => 'catalog.classification_saved']);
    }

    public function test_editorial_order_is_numeric_null_last_and_stably_paginated(): void
    {
        $ids = [];
        foreach ([10, null, 2, 2] as $index => $rank) {
            $hotel = Hotel::factory()->create(['discovery_slug' => 'rank-'.$index, 'discovery_snapshot' => ['public' => [
                'name' => 'Same', 'description' => 'Synthetic', 'property_type' => 'hotel', 'city' => 'Galle', 'country' => 'LK', 'editorial_rank' => $rank,
            ]]]);
            $ids[] = $hotel->id;
        }
        $data = $this->getJson('/api/v1/public/hotels?sort=editorial')->assertOk()->json('data');
        $this->assertSame([$ids[2], $ids[3], $ids[0], $ids[1]], array_column($data, 'id'));
        $this->getJson('/api/v1/public/hotels?sort=editorial&page=2')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 4);
    }

    public function test_later_country_change_prevents_releasing_an_inconsistent_district(): void
    {
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $hotel = Hotel::factory()->create(['description' => 'Synthetic', 'onboarding_data' => ['property_type' => 'hotel'],
            'classification_draft' => ['district' => 'galle', 'themes' => [], 'amenities' => [], 'review_note' => 'Synthetic evidence.']]);
        $uri = '/api/v1/hotels/'.$hotel->id;
        $this->patchJson($uri, ['version' => 0, 'country' => 'GB'])->assertOk();
        $this->putJson($uri.'/discovery', ['onboarding_version' => 1, 'discovery_version' => 0, 'slug' => 'country-change'])->assertUnprocessable()->assertJsonValidationErrors('district');
        $this->assertNull($hotel->fresh()->discovery_snapshot);
    }
}
