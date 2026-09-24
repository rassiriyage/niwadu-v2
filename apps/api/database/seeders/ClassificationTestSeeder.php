<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClassificationTestSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DiscoveryTestSeeder::class);
        DB::transaction(function (): void {
            $admin = User::where('email', 'discovery-admin@example.test')->firstOrFail();
            foreach ([['villa', 'synthetic-coast', 'Synthetic Coast', 'galle', ['beach', 'adventure'], ['wifi', 'pool'], 1],
                ['hotel', 'synthetic-city', 'Synthetic City', 'trincomalee', ['city'], ['wifi', 'parking'], 10]] as [$type, $slug, $name, $district, $themes, $amenities, $rank]) {
                $destination = DB::table('catalog_destinations')->where('slug', $slug)->first();
                $id = $destination?->id ?? DB::table('catalog_destinations')->insertGetId(['slug' => $slug, 'name' => $name, 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
                $hotel = Hotel::where('discovery_slug', 'qa-fixture-'.$type)->lockForUpdate()->firstOrFail();
                $hotel->classification_draft = ['destination_id' => $id, 'district' => $district, 'themes' => $themes, 'amenities' => $amenities, 'editorial_rank' => $rank, 'review_note' => 'Private synthetic QA evidence.'];
                $hotel->onboarding_version++;
                $snapshot = $hotel->discovery_snapshot;
                $snapshot['source_onboarding_version'] = $hotel->onboarding_version;
                $snapshot['public'] = array_replace($snapshot['public'], ['destination' => ['id' => $id, 'slug' => $slug, 'name' => $name],
                    'district' => $district, 'city' => config('catalog.districts.'.$district), 'themes' => $themes, 'amenities' => $amenities, 'editorial_rank' => $rank]);
                $hotel->discovery_snapshot = $snapshot;
                $hotel->discovery_version++;
                $hotel->save();
                $hotel->recordAccessEvent($admin, 'catalog.classification_fixture');
            }
        });
    }
}
