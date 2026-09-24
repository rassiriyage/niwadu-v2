<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DiscoveryTestSeeder extends Seeder
{
    public function run(): void
    {
        require base_path('tests/postgres-bootstrap.php');
        abort_unless(app()->environment('testing') && config('database.default') === 'pgsql'
            && config('database.connections.pgsql.database') === ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE')), 403);
        $password = getenv('DISCOVERY_FIXTURE_PASSWORD');
        abort_unless(is_string($password) && strlen($password) >= 16, 422, 'Supply a local fixture password of at least 16 characters.');
        DB::transaction(function () use ($password): void {
            $admin = User::firstOrNew(['email' => 'discovery-admin@example.test']);
            $admin->forceFill(['name' => 'Synthetic discovery reviewer', 'password' => Hash::make($password), 'platform_role' => 'administrator'])->save();
            foreach (['villa' => 'Synthetic Villa', 'hotel' => 'Synthetic Hotel'] as $type => $name) {
                $hotel = Hotel::where('discovery_slug', 'qa-fixture-'.$type)->first() ?? new Hotel;
                $public = ['name' => $name, 'description' => 'Isolated QA fixture; not a real hotel.', 'property_type' => $type, 'city' => 'Galle', 'country' => 'LK'];
                $hotel->forceFill([
                    'discovery_slug' => 'qa-fixture-'.$type, 'name' => $name, 'description' => $public['description'], 'city' => 'Galle', 'country' => 'LK',
                    'created_by' => $admin->id, 'status' => 'draft', 'onboarding_data' => ['property_type' => $type],
                    'discovery_snapshot' => ['source_onboarding_version' => $hotel->onboarding_version ?? 0, 'public' => $public],
                    'discovery_version' => ($hotel->discovery_version ?? 0) + 1,
                    'discovery_approved_by' => $admin->id, 'discovery_approved_at' => now(),
                ])->save();
                $hotel->recordAccessEvent($admin, 'discovery.fixture');
            }
        });
    }
}
