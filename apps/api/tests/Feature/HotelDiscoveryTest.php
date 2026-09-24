<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HotelDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function draft(array $fields = []): Hotel
    {
        return Hotel::factory()->create(array_replace([
            'name' => 'Fixture Villa', 'description' => 'A synthetic review fixture.',
            'city' => 'Galle', 'country' => 'LK', 'contact_email' => 'private@example.test',
            'onboarding_data' => ['property_type' => 'villa'],
        ], $fields));
    }

    private function release(Hotel $hotel, string $slug, int $source = 0, int $revision = 0): TestResponse
    {
        return $this->putJson("/api/v1/hotels/{$hotel->id}/discovery", [
            'onboarding_version' => $source, 'discovery_version' => $revision, 'slug' => $slug,
        ]);
    }

    public function test_review_release_private_edit_and_withdraw_preserve_the_public_boundary(): void
    {
        $hotel = $this->draft();
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $url = "/api/v1/hotels/{$hotel->id}";
        $this->getJson('/api/v1/public/hotels?sort=name')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson($url.'/discovery-review')->assertOk()
            ->assertJsonPath('data.proposed.name', 'Fixture Villa')->assertJsonPath('data.discovery_version', 0);
        $this->release($hotel, 'fixture-villa')->assertOk()->assertJsonPath('data.discovery_version', 1);
        $card = $this->getJson('/api/v1/public/hotels/fixture-villa')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertSame(['id', 'slug', 'name', 'description', 'property_type', 'city', 'country', 'photo'], array_keys($card));
        $this->assertNull($card['photo']);
        $this->assertSame('villa', $card['property_type']);
        $this->patchJson($url, ['version' => 0, 'name' => 'Changed private name'])->assertOk();
        $this->getJson('/api/v1/public/hotels/fixture-villa')->assertJsonPath('data.name', 'Fixture Villa');
        $this->release($hotel, 'fixture-villa', 0, 1)->assertConflict();
        $this->release($hotel, 'fixture-villa', 1, 1)->assertOk()->assertJsonPath('data.discovery_version', 2);
        $this->getJson('/api/v1/public/hotels/fixture-villa')->assertJsonPath('data.name', 'Changed private name');
        $this->deleteJson($url.'/discovery', ['discovery_version' => 1])->assertConflict();
        $this->deleteJson($url.'/discovery', ['discovery_version' => 2])->assertOk()->assertJsonPath('data.discovery_version', 3);
        $this->getJson('/api/v1/public/hotels/fixture-villa')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/public/hotels?sort=name')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/discovery-options')->assertJsonCount(0, 'data.property_types');
        $this->deleteJson($url.'/discovery', ['discovery_version' => 3])->assertOk()->assertJsonPath('data.discovery_version', 3);
        $this->release($hotel, 'fixture-villa', 1, 2)->assertConflict();
        $this->assertSame('fixture-villa', $hotel->fresh()->discovery_slug);
        $this->assertSame('draft', $hotel->fresh()->status);
        $this->getJson($url.'/onboarding')->assertJsonPath('can_publish', false);
        $this->assertSame(1, DB::table('hotel_access_events')->where('action', 'discovery.withdrawn')->count());
        $this->assertSame(2, DB::table('hotel_access_events')->where('action', 'discovery.released')->count());
    }

    public function test_only_platform_administrator_can_review_release_or_withdraw(): void
    {
        $employee = User::factory()->create(['platform_role' => 'onboarding']);
        $hotel = $this->draft(['created_by' => $employee->id]);
        $url = "/api/v1/hotels/{$hotel->id}";
        $this->getJson($url.'/discovery-review')->assertUnauthorized();
        $this->release($hotel, 'fixture')->assertUnauthorized();
        $this->deleteJson($url.'/discovery', ['discovery_version' => 0])->assertUnauthorized();
        $this->actingAs($employee)->getJson($url.'/discovery-review')->assertForbidden();
        $this->release($hotel, 'fixture')->assertForbidden();
        foreach (['hotel_manager', 'inventory_manager', 'reservations', 'viewer'] as $role) {
            $user = User::factory()->create();
            $hotel->users()->attach($user, ['role' => $role]);
            $this->actingAs($user)->getJson($url.'/discovery-review')->assertForbidden();
            $this->release($hotel, 'fixture')->assertForbidden();
            $this->deleteJson($url.'/discovery', ['discovery_version' => 0])->assertForbidden();
            $hotel->users()->detach($user);
            $this->getJson($url.'/discovery-review')->assertNotFound();
        }
        $this->actingAs(User::factory()->create());
        $this->release($hotel, 'fixture')->assertNotFound();
        $this->assertNull($hotel->fresh()->discovery_snapshot);
    }

    public function test_invalid_metadata_and_injected_content_cannot_be_released(): void
    {
        $hotel = $this->draft(['description' => null]);
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $this->getJson("/api/v1/hotels/{$hotel->id}/discovery-review")->assertOk()->assertJsonStructure(['data' => ['errors' => ['description']]]);
        $this->release($hotel, 'fixture')->assertUnprocessable()->assertJsonValidationErrors('description');
        $hotel->description = 'Fixture';
        $hotel->save();
        foreach (['discovery_snapshot', 'photo', 'discovery_approved_by', 'status', 'name'] as $field) {
            $this->putJson("/api/v1/hotels/{$hotel->id}/discovery", [
                'onboarding_version' => 0, 'discovery_version' => 0, 'slug' => 'fixture', $field => 'injected',
            ])->assertUnprocessable();
        }
        $this->release($hotel, 'Upper Case')->assertUnprocessable();
        $this->assertNull($hotel->fresh()->discovery_snapshot);
    }

    public function test_slug_is_unique_immutable_and_reserved_after_withdrawal(): void
    {
        $hotel = $this->draft();
        $other = $this->draft();
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $this->release($hotel, 'reserved')->assertOk();
        $this->release($other, 'reserved')->assertConflict();
        $this->release($hotel, 'renamed', 0, 1)->assertConflict();
        $this->deleteJson("/api/v1/hotels/{$hotel->id}/discovery", ['discovery_version' => 1])->assertOk();
        $this->release($other, 'reserved')->assertConflict();
        $this->assertNull($other->fresh()->discovery_snapshot);
    }

    public function test_public_query_uses_only_snapshots_with_literal_search_and_stable_paging(): void
    {
        $this->actingAs(User::factory()->create(['platform_role' => 'administrator']));
        $first = $this->draft(['name' => 'Same Name']);
        $this->release($first, 'first')->assertOk();
        foreach (range(1, 24) as $index) {
            $this->release($this->draft(['name' => 'Same Name']), 'fixture-'.$index)->assertOk();
        }
        $literal = $this->draft(['name' => '100%_Literal', 'onboarding_data' => ['property_type' => 'hotel']]);
        $this->release($literal, 'literal')->assertOk();
        $this->draft(['name' => 'PRIVATE SECRET']);
        $this->getJson('/api/v1/public/hotels?sort=name&property_types[]=villa&page=1')
            ->assertOk()->assertJsonCount(24, 'data')->assertJsonPath('meta.total', 25)->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/v1/public/hotels?sort=name&property_types[]=villa&page=2')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/public/hotels?sort=name&q=%25_')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $literal->id);
        $this->getJson('/api/v1/public/hotels?sort=name&q=private')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/hotels?sort=name&page=999')->assertJsonCount(0, 'data')->assertJsonPath('meta.current_page', 999);
        $this->getJson('/api/v1/public/discovery-options')->assertOk()
            ->assertJsonPath('data.capabilities.sorts', ['name'])->assertJsonPath('data.capabilities.availability_search', false)
            ->assertJsonCount(2, 'data.property_types');
    }

    public function test_queries_reject_unavailable_filters_and_raw_ambiguous_keys_without_fallback(): void
    {
        foreach (['', 'sort=editorial', 'sort=price-asc', 'sort=name&min=0', 'sort=name&children=1',
            'sort=name&check_in=2026-10-01', 'sort=name&property_types[]=bungalow', 'sort=name&page=100001',
            'sort=name&sort=name', 'sort=name&%73ort=editorial', 'sort[]=name', 'sort=name&property_types=villa',
            'sort=name&property_types[][]=villa', 'sort=name&page=1&page=2', 'sort=name&unknown=x',
        ] as $query) {
            $this->getJson('/api/v1/public/hotels?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/v1/public/hotels?sort=name&property_types[]=villa&property_types[]=villa')->assertOk()
            ->assertJsonPath('meta.applied_filters.property_types', ['villa']);
    }
}
