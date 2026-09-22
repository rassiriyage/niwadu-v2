<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_login_logout_and_private_response(): void
    {
        $user = User::factory()->create(['password' => 'a-long-test-password']);
        $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('user', null)->assertJsonStructure(['csrf_token']);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'a-long-test-password'])
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonMissingPath('user.password');
        $this->assertAuthenticatedAs($user);
        $this->assertStringContainsString('no-store', $this->getJson('/api/v1/session')->headers->get('Cache-Control'));
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->assertGuest();
        $this->getJson('/api/v1/hotels')->assertUnauthorized();
    }

    public function test_wrong_password_is_rejected_and_repeated_logins_are_limited(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_csrf_is_required_when_not_running_in_the_test_bypass(): void
    {
        $this->app->instance('env', 'local');
        $this->postJson('/api/v1/login', ['email' => 'staff@example.test', 'password' => 'wrong'])->assertStatus(419);
    }
}
