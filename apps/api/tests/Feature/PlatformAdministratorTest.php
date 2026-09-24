<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformAdministratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_an_admin_without_a_default_password(): void
    {
        $this->artisan('niwadu:create-administrator admin@example.test --name=Administrator')
            ->expectsQuestion('Password (at least 12 characters, at most 72 UTF-8 bytes)', 'local-test-password')
            ->assertSuccessful();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertSame('administrator', $user->platform_role);
        $this->assertTrue(Hash::check('local-test-password', $user->password));
    }

    public function test_command_will_not_promote_an_existing_account(): void
    {
        User::factory()->create(['email' => 'staff@example.test']);
        $this->artisan('niwadu:create-administrator staff@example.test --name=Staff')->assertFailed();
        $this->assertNull(User::first()->platform_role);
    }
}
