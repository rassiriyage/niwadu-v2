<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordByteLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_both_overlength_aliases_and_multibyte_overflow(): void
    {
        foreach ([str_repeat('a', 72).'x', str_repeat('a', 72).'y', str_repeat('é', 37)] as $password) {
            $this->postJson('/api/v1/register', ['name' => 'Traveller', 'email' => 'limit@example.test', 'password' => $password, 'password_confirmation' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->assertDatabaseCount('users', 0);
        $password = str_repeat('é', 36);
        $this->postJson('/api/v1/register', ['name' => 'Traveller', 'email' => 'limit@example.test', 'password' => $password, 'password_confirmation' => $password])->assertCreated();
    }

    public function test_reset_rejects_overlength_without_consuming_token_and_accepts_boundary(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;
        $token = Password::createToken($user);
        foreach ([str_repeat('a', 73), str_repeat('é', 37)] as $password) {
            $this->postJson('/api/v1/password/setup', ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
            $this->assertSame($hash, $user->fresh()->password);
        }
        $password = str_repeat('é', 36);
        $this->postJson('/api/v1/password/setup', ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password])->assertOk();
    }

    public function test_operator_commands_reject_overlength_passwords_without_creating_users(): void
    {
        foreach (['niwadu:create-administrator', 'niwadu:create-onboarding-employee'] as $command) {
            $this->artisan($command, ['email' => 'operator@example.test', '--name' => 'Operator'])
                ->expectsQuestion('Password (at least 12 characters, at most 72 UTF-8 bytes)', str_repeat('é', 37))->assertFailed();
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_legacy_overlength_password_still_logs_in_unchanged(): void
    {
        $password = str_repeat('a', 72).'legacy-suffix';
        $user = User::factory()->create(['password' => $password]);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => $password])->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
