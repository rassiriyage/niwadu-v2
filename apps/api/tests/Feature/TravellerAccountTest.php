<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TravellerAccountTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_replace(['name' => 'Traveller', 'email' => 'TRAVELLER@example.test', 'password' => 'a-long-test-password', 'password_confirmation' => 'a-long-test-password'], $overrides);
    }

    public function test_registration_creates_unprivileged_authenticated_user_and_session(): void
    {
        $this->postJson('/api/v1/register', $this->registration())->assertCreated()
            ->assertJsonPath('user.email', 'traveller@example.test')->assertJsonPath('user.platform_role', null)
            ->assertJsonStructure(['csrf_token', 'user' => ['id', 'name', 'email', 'platform_role']])->assertJsonMissingPath('user.password');
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('a-long-test-password', $user->password));
        $this->assertDatabaseCount('hotel_user', 0);
        $this->getJson('/api/v1/session')->assertJsonPath('user.id', $user->id);
        $this->postJson('/api/v1/hotels', ['name' => 'Unauthorized'])->assertForbidden();
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'a-long-test-password'])->assertOk();
    }

    public function test_registration_rejects_privilege_inputs_and_existing_identity_without_mutation(): void
    {
        $this->postJson('/api/v1/register', $this->registration(['platform_role' => 'administrator', 'memberships' => []]))->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $user = User::factory()->create(['email' => 'traveller@example.test', 'platform_role' => 'administrator']);
        $before = $user->fresh()->getAttributes();
        $this->postJson('/api/v1/register', $this->registration())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertGuest();
    }

    public function test_registration_validation_and_authenticated_account_switch_are_rejected(): void
    {
        $this->postJson('/api/v1/register', $this->registration(['password' => 'short', 'password_confirmation' => 'different']))->assertUnprocessable();
        $this->postJson('/api/v1/register', $this->registration(['password' => "long-password\0invalid", 'password_confirmation' => "long-password\0invalid"]))->assertUnprocessable();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/register', $this->registration())->assertStatus(409);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_coverage_is_private_owned_versioned_and_survives_sessions(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create(['platform_role' => 'administrator']);
        $this->actingAs($owner)->getJson('/api/v1/me/coverage')->assertExactJson(['districts' => [], 'version' => 0]);
        $response = $this->putJson('/api/v1/me/coverage', ['districts' => ['kandy', 'galle'], 'version' => 0]);
        $response->assertOk()->assertExactJson(['districts' => ['galle', 'kandy'], 'version' => 1]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->putJson('/api/v1/me/coverage', ['districts' => [], 'version' => 0])->assertStatus(409);
        $this->actingAs($other)->getJson('/api/v1/me/coverage')->assertExactJson(['districts' => [], 'version' => 0]);
        $this->putJson('/api/v1/me/coverage', ['districts' => ['colombo'], 'version' => 0])->assertOk();
        $this->actingAs($owner)->getJson('/api/v1/me/coverage')->assertExactJson(['districts' => ['galle', 'kandy'], 'version' => 1]);
        $this->putJson('/api/v1/me/coverage', ['districts' => [], 'version' => 1])->assertExactJson(['districts' => [], 'version' => 2]);
    }

    public function test_coverage_rejects_invalid_districts_versions_and_owner_inputs(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([['districts' => ['unknown'], 'version' => 0], ['districts' => ['kandy', 'kandy'], 'version' => 0], ['districts' => [], 'version' => -1], ['districts' => [], 'version' => 0, 'user_id' => 2], ['districts' => ['named' => 'kandy'], 'version' => 0], ['districts' => [], 'version' => 0.5]] as $payload) {
            $this->putJson('/api/v1/me/coverage', $payload)->assertUnprocessable();
        }
        $this->assertDatabaseCount('user_coverage', 0);
    }

    public function test_coverage_requires_authentication_and_csrf(): void
    {
        $this->getJson('/api/v1/me/coverage')->assertUnauthorized();
        $this->putJson('/api/v1/me/coverage', ['districts' => [], 'version' => 0])->assertUnauthorized();
        $this->app->instance('env', 'local');
        $this->postJson('/api/v1/register', $this->registration())->assertStatus(419);
        $this->actingAs(User::factory()->create())->putJson('/api/v1/me/coverage', ['districts' => [], 'version' => 0])->assertStatus(419);
    }

    public function test_all_25_districts_are_supported_and_coverage_is_removed_with_owner(): void
    {
        $user = User::factory()->create();
        $districts = ['ampara', 'anuradhapura', 'badulla', 'batticaloa', 'colombo', 'galle', 'gampaha', 'hambantota', 'jaffna', 'kalutara', 'kandy', 'kegalle', 'kilinochchi', 'kurunegala', 'mannar', 'matale', 'matara', 'moneragala', 'mullaitivu', 'nuwaraeliya', 'polonnaruwa', 'puttalam', 'ratnapura', 'trincomalee', 'vavuniya'];
        $this->actingAs($user)->putJson('/api/v1/me/coverage', ['districts' => $districts, 'version' => 0])->assertExactJson(['districts' => $districts, 'version' => 1]);
        $user->delete();
        $this->assertDatabaseCount('user_coverage', 0);
    }

    public function test_registration_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/register', [])->assertTooManyRequests();
    }
}
