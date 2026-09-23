<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

class HotelAccessTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_ends_with(config('database.connections.sqlite.database'), '/niwadu-browser-tests.sqlite')) {
            throw new RuntimeException('Browser fixtures require the isolated Niwadu browser test database.');
        }
        $admin = User::firstOrNew(['email' => 'admin@example.test']);
        $admin->name = 'Test Administrator';
        $admin->password = 'browser-test-password';
        $admin->platform_role = 'administrator';
        $admin->save();
        $employee = User::firstOrNew(['email' => 'employee@example.test']);
        $employee->name = 'Test Onboarding Employee';
        $employee->password = 'browser-test-password';
        $employee->platform_role = 'onboarding';
        $employee->save();
        foreach (['conflict', 'validation', 'retry', 'signout-422', 'signout-409', 'signout-503', 'recovery', 'rooms-recovery'] as $scenario) {
            $tester = User::firstOrNew(['email' => $scenario.'@example.test']);
            $tester->name = 'Test Onboarding Employee';
            $tester->password = 'browser-test-password';
            $tester->platform_role = 'onboarding';
            $tester->save();
        }
        $manager = User::firstOrNew(['email' => 'manager@example.test']);
        $manager->name = 'Test Manager';
        $manager->password = 'browser-test-password';
        $manager->save();
        $harbour = Hotel::firstOrNew(['name' => 'Harbour Test Hotel']);
        $harbour->city = 'Galle';
        $harbour->created_by = $admin->id;
        $harbour->save();
        $hill = Hotel::firstOrNew(['name' => 'Hill Test Hotel']);
        $hill->city = 'Kandy';
        $hill->created_by = $admin->id;
        $hill->save();
        $harbour->users()->syncWithoutDetaching([$manager->id => ['role' => 'hotel_manager']]);
        $hill->users()->detach($manager->id);
        $invitee = User::firstOrNew(['email' => 'invitee@example.test']);
        $invitee->name = 'Test Invitee';
        $invitee->password = Str::random(64);
        $invitee->save();
        $harbour->users()->syncWithoutDetaching([$invitee->id => ['role' => 'viewer']]);
        $token = Password::createToken($invitee);
        file_put_contents(storage_path('framework/testing/browser-invitation.json'), json_encode(['email' => $invitee->email, 'token' => $token]));
    }
}
