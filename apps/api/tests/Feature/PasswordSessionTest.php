<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordSessionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('protectedPaths')]
    public function test_reset_revokes_an_existing_login_on_its_next_request(string $path): void
    {
        $user = User::factory()->create(['password' => 'initial-test-password']);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'initial-test-password'])->assertOk();
        $oldSession = session()->all();
        $this->assertArrayHasKey('password_hash_web', $oldSession);
        $oldRememberToken = $user->fresh()->remember_token;

        $this->newRequestSession();
        $payload = ['email' => $user->email, 'token' => Password::createToken($user), 'password' => 'replacement-test-password', 'password_confirmation' => 'replacement-test-password'];
        $this->postJson('/api/v1/password/setup', $payload)->assertOk();
        $this->assertNotSame($oldRememberToken, $user->fresh()->remember_token);
        $this->postJson('/api/v1/password/setup', $payload)->assertUnprocessable();

        $this->newRequestSession($oldSession);
        $this->getJson($path)->assertUnauthorized();
        $this->assertGuest();
        $this->getJson('/api/v1/hotels')->assertUnauthorized();
        $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('user', null);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'initial-test-password'])->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'replacement-test-password'])->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public static function protectedPaths(): array
    {
        return ['session introspection' => ['/api/v1/session'], 'hotel data' => ['/api/v1/hotels']];
    }

    public function test_reset_does_not_sign_out_an_unrelated_user(): void
    {
        $operator = User::factory()->create(['password' => 'operator-test-password']);
        $user = User::factory()->create();
        $this->postJson('/api/v1/login', ['email' => $operator->email, 'password' => 'operator-test-password'])->assertOk();
        $payload = ['email' => $user->email, 'token' => Password::createToken($user), 'password' => 'replacement-test-password', 'password_confirmation' => 'replacement-test-password'];
        $this->postJson('/api/v1/password/setup', $payload)->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('user.id', $operator->id);
    }

    public function test_old_remember_cookie_cannot_reauthenticate_after_reset(): void
    {
        $user = User::factory()->create();
        $guard = Auth::guard();
        $name = $guard->getRecallerName();
        $cookie = $user->id.'|'.$user->remember_token.'|'.$guard->hashPasswordForCookie($user->password);
        $payload = ['email' => $user->email, 'token' => Password::createToken($user), 'password' => 'replacement-test-password', 'password_confirmation' => 'replacement-test-password'];
        $this->postJson('/api/v1/password/setup', $payload)->assertOk();
        $this->newRequestSession();
        $this->withCookie($name, $cookie)->getJson('/api/v1/session')->assertOk()->assertJsonPath('user', null);
        $this->getJson('/api/v1/hotels')->assertUnauthorized();
    }

    public function test_login_does_not_enable_remember_me_from_untrusted_input(): void
    {
        $user = User::factory()->create(['password' => 'initial-test-password']);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'initial-test-password', 'remember' => true])
            ->assertOk()->assertCookieMissing(Auth::guard()->getRecallerName());
    }

    private function newRequestSession(array $data = []): void
    {
        session()->flush();
        Auth::forgetGuards();
        $this->withSession($data);
    }
}
