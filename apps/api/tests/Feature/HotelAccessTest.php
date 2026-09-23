<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_list_or_create_hotels(): void
    {
        $this->getJson('/api/v1/hotels')->assertUnauthorized();
        $this->postJson('/api/v1/hotels', ['name' => 'Hotel A'])->assertUnauthorized();
    }

    public function test_administrator_can_create_a_draft_and_creation_is_audited(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $response = $this->actingAs($admin)->postJson('/api/v1/hotels', ['name' => 'Hotel A', 'city' => 'Galle']);
        $response->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('hotels', ['name' => 'Hotel A', 'created_by' => $admin->id]);
        $this->assertDatabaseHas('hotel_access_events', ['actor_id' => $admin->id, 'action' => 'hotel.created']);
    }

    public function test_hotel_staff_only_see_assigned_hotels_even_when_ids_are_guessed(): void
    {
        $staff = User::factory()->create();
        $own = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $own->users()->attach($staff, ['role' => 'hotel_manager']);
        $this->actingAs($staff)->getJson('/api/v1/hotels')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/hotels/'.$other->id)->assertNotFound();
        $this->patchJson('/api/v1/hotels/'.$other->id, ['name' => 'Hijacked'])->assertNotFound();
        $this->postJson('/api/v1/hotels', ['name' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseMissing('hotels', ['name' => 'Hijacked']);
    }

    public function test_manager_can_edit_profile_but_other_hotel_roles_cannot(): void
    {
        $hotel = Hotel::factory()->create();
        foreach (['hotel_manager' => 200, 'reservations' => 403, 'inventory_manager' => 403, 'viewer' => 403] as $role => $status) {
            $user = User::factory()->create();
            $hotel->users()->attach($user, ['role' => $role]);
            $this->actingAs($user)->patchJson('/api/v1/hotels/'.$hotel->id, ['city' => 'Kandy', 'version' => 0])->assertStatus($status);
        }
    }

    public function test_hotel_profile_rejects_configuration_and_privilege_injection(): void
    {
        $user = User::factory()->create();
        $hotel = Hotel::factory()->create();
        $hotel->users()->attach($user, ['role' => 'hotel_manager']);
        foreach (['platform_role', 'payment_gateway', 'pms_provider', 'inventory_mode', 'status', 'created_by'] as $field) {
            $this->actingAs($user)->patchJson('/api/v1/hotels/'.$hotel->id, [$field => 'attacker-value'])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertNull($user->fresh()->platform_role);
        $this->assertSame('draft', $hotel->fresh()->status);
    }

    public function test_onboarding_employee_can_only_access_their_own_drafts(): void
    {
        $employee = User::factory()->create(['platform_role' => 'onboarding']);
        $own = Hotel::factory()->create(['created_by' => $employee->id]);
        $other = Hotel::factory()->create();
        $published = Hotel::factory()->create(['created_by' => $employee->id, 'status' => 'published']);
        $this->actingAs($employee)->getJson('/api/v1/hotels')->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/hotels/'.$own->id, ['city' => 'Ella', 'version' => 0])->assertOk();
        $this->getJson('/api/v1/hotels/'.$other->id)->assertNotFound();
        $this->getJson('/api/v1/hotels/'.$published->id)->assertNotFound();
    }
}
