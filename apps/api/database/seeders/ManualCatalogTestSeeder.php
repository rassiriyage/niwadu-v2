<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\InventoryPool;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ManualCatalogTestSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DiscoveryTestSeeder::class);
        DB::transaction(function (): void {
            $hotel = Hotel::where('discovery_slug', 'qa-fixture-villa')->lockForUpdate()->firstOrFail();
            $room = RoomType::where('hotel_id', $hotel->id)->where('name', 'Synthetic manual double')->first() ?? new RoomType;
            $room->forceFill(['hotel_id' => $hotel->id, 'name' => 'Synthetic manual double', 'max_occupancy' => 2,
                'status' => 'active', 'version' => ($room->version ?? 0) + 1])->save();
            $pool = InventoryPool::where('room_type_id', $room->id)->first() ?? new InventoryPool;
            abort_if($pool->exists && $pool->owner !== 'manual', 409);
            $pool->forceFill(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'owner' => 'manual', 'sales_state' => 'open',
                'timezone' => 'Asia/Colombo', 'version' => ($pool->version ?? 0) + 1, 'ownership_version' => 1])->save();
            $plan = RatePlan::where('room_type_id', $room->id)->where('name', 'Synthetic room only')->first() ?? new RatePlan;
            $plan->forceFill(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'name' => 'Synthetic room only', 'status' => 'active',
                'currency' => 'LKR', 'policy' => ['version' => 'fixture-1', 'text' => 'Synthetic QA policy: no refund.'],
                'version' => ($plan->version ?? 0) + 1])->save();
            $arrival = now('Asia/Colombo')->startOfDay()->addDays(7);
            for ($day = 0; $day <= 2; $day++) {
                $date = $arrival->copy()->addDays($day)->format('Y-m-d');
                $identity = ['rate_plan_id' => $plan->id, 'stay_date' => $date];
                $version = DB::table('rate_plan_nights')->where($identity)->value('version') ?? 0;
                DB::table('rate_plan_nights')->updateOrInsert($identity, ['base_minor' => 1000000, 'tax_minor' => 100000, 'fee_minor' => 0,
                    'mandatory_charges_complete' => true, 'stop_sell' => false, 'min_stay' => 1, 'max_stay' => 30,
                    'closed_to_arrival' => false, 'closed_to_departure' => false, 'version' => $version + 1]);
                if ($day < 2) {
                    $identity = ['inventory_pool_id' => $pool->id, 'stay_date' => $date];
                    $existing = DB::table('inventory_nights')->where($identity)->first();
                    abort_if($existing && ($existing->held > 0 || $existing->sold > 0), 409, 'Fixture has obligations; do not reset it.');
                    DB::table('inventory_nights')->updateOrInsert($identity, ['capacity' => 3, 'version' => ($existing->version ?? 0) + 1]);
                }
            }
            $this->command->info('Synthetic manual selection: hotel_id='.$hotel->id.' rate_plan_id='.$plan->id.' arrival='.$arrival->format('Y-m-d').' departure='.$arrival->copy()->addDays(2)->format('Y-m-d').' adults=2');
        });
    }
}
