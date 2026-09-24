<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class OperationsBrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(HotelAccessTestSeeder::class);
        foreach (range(0, 7) as $index) {
            $user = User::firstOrNew(['email' => 'operations'.$index.'@example.test']);
            $user->name = 'Operations test administrator';
            $user->password = 'browser-test-password';
            $user->platform_role = 'administrator';
            $user->save();
        }
    }
}
