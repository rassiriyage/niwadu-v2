<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OnboardingEmployeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_an_employee_with_a_hashed_password_and_no_email(): void
    {
        Mail::fake();
        Notification::fake();
        $this->artisan('niwadu:create-onboarding-employee EMPLOYEE@example.test')
            ->expectsQuestion('Name', 'Onboarding Employee')
            ->expectsQuestion('Password (at least 12 characters, at most 72 UTF-8 bytes)', 'local-test-password')
            ->assertSuccessful();

        $user = User::where('email', 'employee@example.test')->firstOrFail();
        $this->assertSame('Onboarding Employee', $user->name);
        $this->assertSame('onboarding', $user->platform_role);
        $this->assertTrue(Hash::check('local-test-password', $user->password));
        $this->assertDatabaseCount('hotel_user', 0);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_command_never_changes_an_existing_account(): void
    {
        foreach ([null, 'onboarding', 'administrator'] as $role) {
            $user = User::factory()->create(['platform_role' => $role]);
            $before = $user->fresh()->getAttributes();
            $this->artisan('niwadu:create-onboarding-employee', ['email' => strtoupper($user->email), '--name' => 'Replacement'])
                ->expectsOutput('An account with this email already exists. No changes were made.')
                ->assertFailed();
            $this->assertSame($before, $user->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('users', 3);
    }

    #[DataProvider('invalidInputs')]
    public function test_command_rejects_invalid_input(string $email, string $name, string $password): void
    {
        $this->artisan('niwadu:create-onboarding-employee', ['email' => $email, '--name' => $name])
            ->expectsQuestion('Password (at least 12 characters, at most 72 UTF-8 bytes)', $password)
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'invalid email' => ['not-an-email', 'Employee', 'local-test-password'],
            'blank name' => ['employee@example.test', ' ', 'local-test-password'],
            'long name' => ['employee@example.test', str_repeat('a', 256), 'local-test-password'],
            'empty password' => ['employee@example.test', 'Employee', ''],
            'short password' => ['employee@example.test', 'Employee', 'short'],
            'long password' => ['employee@example.test', 'Employee', str_repeat('a', 1025)],
        ];
    }

    public function test_provisioned_employee_can_create_and_resume_only_their_own_drafts(): void
    {
        $employee = $this->provisionEmployee();
        $other = Hotel::factory()->create();
        $published = Hotel::factory()->create(['created_by' => $employee->id, 'status' => 'published']);
        $this->actingAs($employee);
        $id = $this->postJson('/api/v1/hotels', ['name' => 'Employee draft'])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/hotels')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->patchJson('/api/v1/hotels/'.$id.'/onboarding', ['version' => 0, 'fields' => ['city' => 'Ella']])->assertOk();
        $this->getJson('/api/v1/hotels/'.$id.'/onboarding')->assertOk()->assertJsonPath('fields.city', 'Ella');
        foreach ([$other, $published] as $inaccessible) {
            $url = '/api/v1/hotels/'.$inaccessible->id;
            $this->getJson($url)->assertNotFound();
            $this->patchJson($url, ['version' => 0, 'city' => 'Forged'])->assertNotFound();
            $this->getJson($url.'/onboarding')->assertNotFound();
            $this->patchJson($url.'/onboarding', ['version' => 0, 'fields' => ['city' => 'Forged']])->assertNotFound();
            $this->getJson($url.'/staff')->assertNotFound();
        }
    }

    public function test_provisioned_employee_cannot_inject_platform_payment_or_pms_authority(): void
    {
        $employee = $this->provisionEmployee();
        $hotel = Hotel::factory()->create(['created_by' => $employee->id]);
        $url = '/api/v1/hotels/'.$hotel->id;
        $this->actingAs($employee);
        foreach (['platform_role', 'payment_gateway', 'pms_provider', 'inventory_mode', 'status', 'created_by'] as $field) {
            $this->patchJson($url, ['version' => 0, $field => 'administrator'])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->patchJson($url.'/onboarding', ['version' => 0, 'fields' => [$field => 'administrator']])->assertUnprocessable();
        }
        $this->postJson($url.'/staff', ['name' => 'Employee', 'email' => $employee->email, 'role' => 'administrator'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson($url.'/staff', ['name' => 'Employee', 'email' => $employee->email, 'role' => 'hotel_manager', 'platform_role' => 'administrator'])
            ->assertUnprocessable()->assertJsonValidationErrors('platform_role');
        $this->assertSame('onboarding', $employee->fresh()->platform_role);
        $this->assertSame('draft', $hotel->fresh()->status);
        $this->assertSame(0, $hotel->fresh()->onboarding_version);
        $this->assertDatabaseCount('hotel_user', 0);
    }

    private function provisionEmployee(): User
    {
        $this->artisan('niwadu:create-onboarding-employee employee@example.test --name=Employee')
            ->expectsQuestion('Password (at least 12 characters, at most 72 UTF-8 bytes)', 'local-test-password')
            ->assertSuccessful();

        return User::where('email', 'employee@example.test')->firstOrFail();
    }
}
