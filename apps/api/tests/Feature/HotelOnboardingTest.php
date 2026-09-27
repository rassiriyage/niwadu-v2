<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HotelOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_save_partial_drafts_and_resume_without_publishing(): void
    {
        $employee = User::factory()->create(['platform_role' => 'onboarding']);
        $hotel = Hotel::factory()->create(['created_by' => $employee->id]);
        $url = '/api/v1/hotels/'.$hotel->id.'/onboarding';
        $this->actingAs($employee)->getJson($url)->assertOk()->assertJsonPath('version', 0);
        $this->patchJson($url, ['version' => 0, 'step' => 2, 'fields' => ['description' => 'A seaside hotel', 'amenities' => ['wifi']]])
            ->assertOk()->assertJsonPath('version', 1)->assertJsonPath('step', 2)->assertJsonPath('can_publish', false);
        $this->getJson($url)->assertJsonPath('fields.description', 'A seaside hotel')->assertJsonPath('fields.amenities.0', 'wifi');
        $this->assertSame('draft', $hotel->fresh()->status);
        $this->assertDatabaseHas('hotel_access_events', ['action' => 'onboarding.saved', 'hotel_id' => $hotel->id]);
    }

    public function test_phase_one_rejects_new_pms_requests_but_preserves_legacy_autosaves(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create();
        $url = '/api/v1/hotels/'.$hotel->id.'/onboarding';
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('pms_available', false);
        $this->patchJson($url, ['version' => 0, 'fields' => ['inventory_request' => 'pms', 'city' => 'Ella']])
            ->assertUnprocessable()->assertJsonValidationErrors('fields.inventory_request');
        $this->assertSame(0, $hotel->fresh()->onboarding_version);
        $hotel->forceFill(['onboarding_data' => ['inventory_request' => 'pms']])->save();
        $this->patchJson($url, ['version' => 0, 'fields' => ['inventory_request' => 'pms', 'city' => 'Ella']])
            ->assertOk()->assertJsonPath('fields.inventory_request', 'pms')->assertJsonPath('fields.city', 'Ella');
        $this->patchJson($url, ['version' => 1, 'fields' => ['description' => 'Existing draft']])
            ->assertOk()->assertJsonPath('fields.inventory_request', 'pms');
        $this->patchJson($url, ['version' => 2, 'fields' => ['inventory_request' => 'manual']])
            ->assertOk()->assertJsonPath('fields.inventory_request', 'manual');
        $this->assertDatabaseCount('inventory_pools', 0);
        $room = new RoomType(['name' => 'Legacy PMS room', 'max_occupancy' => 2]);
        $room->hotel_id = $hotel->id;
        $room->save();
        DB::table('inventory_pools')->insert(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'owner' => 'pms', 'sales_state' => 'closed']);
        $this->patchJson($url, ['version' => 3, 'fields' => ['inventory_request' => 'manual']])->assertOk();
        $this->assertDatabaseHas('inventory_pools', ['room_type_id' => $room->id, 'owner' => 'pms', 'sales_state' => 'closed']);
    }

    public function test_stale_updates_do_not_overwrite_newer_work_including_profile_edits(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create();
        $url = '/api/v1/hotels/'.$hotel->id.'/onboarding';
        $this->actingAs($admin)->patchJson($url, ['version' => 0, 'fields' => ['city' => 'Ella']])->assertOk();
        $this->patchJson($url, ['version' => 0, 'fields' => ['city' => 'Kandy']])->assertConflict();
        $this->assertSame('Ella', $hotel->fresh()->city);
        $this->patchJson('/api/v1/hotels/'.$hotel->id, ['city' => 'Galle', 'version' => 1])->assertOk();
        $this->patchJson($url, ['version' => 1, 'fields' => ['city' => 'Kandy']])->assertConflict();
        $this->patchJson('/api/v1/hotels/'.$hotel->id, ['city' => 'Kandy', 'version' => 1])->assertConflict();
        $this->assertSame('Galle', $hotel->fresh()->city);
    }

    public function test_onboarding_is_platform_only_and_scoped_to_owned_drafts(): void
    {
        $hotel = Hotel::factory()->create();
        $manager = User::factory()->create();
        $hotel->users()->attach($manager, ['role' => 'hotel_manager']);
        $url = '/api/v1/hotels/'.$hotel->id.'/onboarding';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($manager)->getJson($url)->assertForbidden();
        $this->patchJson($url, ['version' => 0, 'fields' => ['city' => 'Ella']])->assertForbidden();
        $employee = User::factory()->create(['platform_role' => 'onboarding']);
        $this->actingAs($employee)->getJson($url)->assertNotFound();
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel->status = 'published';
        $hotel->save();
        $this->actingAs($admin)->patchJson($url, ['version' => 0, 'fields' => ['city' => 'Ella']])->assertForbidden();
    }

    public function test_draft_fields_cannot_inject_configuration_status_or_invalid_room_data(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create();
        $url = '/api/v1/hotels/'.$hotel->id.'/onboarding';
        $this->actingAs($admin);
        foreach (['payment_gateway', 'pms_provider', 'status', 'created_by', 'can_publish'] as $field) {
            $this->patchJson($url, ['version' => 0, 'fields' => [$field => 'forged']])->assertUnprocessable();
        }
        $this->patchJson($url, ['version' => 0, 'fields' => ['rooms' => [['name' => 'Room', 'occupancy' => 0, 'quantity' => -1, 'rate' => -1]]]])->assertUnprocessable();
        $this->patchJson($url, ['version' => 0, 'fields' => ['rooms' => [['name' => 'Room', 'occupancy' => 2, 'quantity' => 3, 'rate' => 10000]], 'inventory_request' => 'manual']])->assertOk()->assertJsonPath('can_publish', false);
        $this->assertSame('draft', $hotel->fresh()->status);
    }
}
