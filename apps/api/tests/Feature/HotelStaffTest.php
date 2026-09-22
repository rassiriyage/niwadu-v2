<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HotelStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_invite_staff_with_a_single_use_password_link(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create();
        $this->actingAs($admin)->postJson('/api/v1/hotels/'.$hotel->id.'/staff', [
            'name' => 'Reception', 'email' => 'reception@example.test', 'role' => 'reservations',
        ])->assertCreated()->assertJsonMissingPath('password')->assertJsonMissingPath('token');
        $staff = User::where('email', 'reception@example.test')->firstOrFail();
        $this->assertNull($staff->platform_role);
        $this->assertDatabaseHas('hotel_user', ['hotel_id' => $hotel->id, 'user_id' => $staff->id, 'role' => 'reservations']);
        $this->assertDatabaseHas('hotel_access_events', ['action' => 'staff.granted', 'subject_id' => $staff->id]);
        Notification::assertSentTo($staff, ResetPassword::class, function ($notification) use ($staff) {
            auth()->logout();
            $payload = ['email' => $staff->email, 'token' => $notification->token, 'password' => 'new-long-password', 'password_confirmation' => 'new-long-password'];
            $this->postJson('/api/v1/password/setup', $payload)->assertOk();
            $this->assertTrue(Hash::check('new-long-password', $staff->fresh()->password));
            $this->postJson('/api/v1/password/setup', $payload)->assertUnprocessable();

            return true;
        });
    }

    public function test_existing_staff_can_be_assigned_to_multiple_hotels_without_changing_password(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $staff = User::factory()->create();
        $hash = $staff->password;
        foreach (Hotel::factory()->count(2)->create() as $hotel) {
            $this->actingAs($admin)->postJson('/api/v1/hotels/'.$hotel->id.'/staff', ['name' => 'Ignored', 'email' => $staff->email, 'role' => 'viewer'])->assertCreated();
        }
        $this->assertSame($hash, $staff->fresh()->password);
        $this->actingAs($staff)->getJson('/api/v1/hotels')->assertJsonCount(2, 'data');
        Notification::assertNothingSent();
    }

    public function test_hotel_users_cannot_grant_revoke_or_escalate_access(): void
    {
        $hotel = Hotel::factory()->create();
        $manager = User::factory()->create();
        $hotel->users()->attach($manager, ['role' => 'hotel_manager']);
        $this->actingAs($manager)->postJson('/api/v1/hotels/'.$hotel->id.'/staff', ['name' => 'Intruder', 'email' => 'intruder@example.test', 'role' => 'administrator'])->assertForbidden();
        $this->deleteJson('/api/v1/hotels/'.$hotel->id.'/staff/'.$manager->id)->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.test']);
    }

    public function test_invalid_roles_are_rejected_even_for_administrators(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create();
        $this->actingAs($admin)->postJson('/api/v1/hotels/'.$hotel->id.'/staff', ['name' => 'Staff', 'email' => 'staff@example.test', 'role' => 'administrator'])->assertUnprocessable();
    }

    public function test_revoke_takes_effect_on_the_next_request_and_does_not_affect_other_hotels(): void
    {
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $staff = User::factory()->create();
        $hotels = Hotel::factory()->count(2)->create();
        foreach ($hotels as $hotel) {
            $hotel->users()->attach($staff, ['role' => 'viewer']);
        }
        $this->actingAs($staff)->getJson('/api/v1/hotels/'.$hotels[0]->id)->assertOk();
        $this->actingAs($admin)->deleteJson('/api/v1/hotels/'.$hotels[0]->id.'/staff/'.$staff->id)->assertNoContent();
        $this->actingAs($staff)->getJson('/api/v1/hotels/'.$hotels[0]->id)->assertNotFound();
        $this->getJson('/api/v1/hotels/'.$hotels[1]->id)->assertOk();
        $this->assertDatabaseHas('hotel_access_events', ['action' => 'staff.revoked', 'subject_id' => $staff->id]);
    }

    public function test_only_authorized_platform_staff_can_resend_a_scoped_password_link(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $member = User::factory()->create();
        $hotel = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $hotel->users()->attach($member, ['role' => 'hotel_manager']);
        $url = '/api/v1/hotels/'.$hotel->id.'/staff/'.$member->id.'/password-link';
        $this->actingAs($member)->postJson($url)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/v1/hotels/'.$other->id.'/staff/'.$member->id.'/password-link')->assertNotFound();
        Notification::assertNothingSent();
        $this->postJson($url)->assertOk()->assertJsonPath('password_setup_sent', true);
        Notification::assertSentTo($member, ResetPassword::class);
        $this->assertDatabaseHas('hotel_access_events', ['action' => 'staff.password_link_sent', 'subject_id' => $member->id]);
        $this->postJson($url)->assertStatus(429);
    }

    public function test_cross_hotel_staff_lists_are_not_accessible(): void
    {
        $manager = User::factory()->create();
        $own = Hotel::factory()->create();
        $other = Hotel::factory()->create();
        $own->users()->attach($manager, ['role' => 'hotel_manager']);
        $this->actingAs($manager)->getJson('/api/v1/hotels/'.$other->id.'/staff')->assertNotFound();
    }
}
