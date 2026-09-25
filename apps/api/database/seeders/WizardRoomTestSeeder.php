<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WizardRoomTestSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ManualCatalogTestSeeder::class);
        DB::transaction(function (): void {
            $hotel = Hotel::where('discovery_slug', 'qa-fixture-villa')->lockForUpdate()->firstOrFail();
            $legacy = RatePlan::where('hotel_id', $hotel->id)->where('name', 'Synthetic room only')->firstOrFail();
            foreach (['LKR' => 1100000, 'USD' => 12500] as $currency => $amount) {
                $plan = RatePlan::where('room_type_id', $legacy->room_type_id)->where('meal_plan', 'BB')->where('currency', $currency)->first() ?? new RatePlan;
                $plan->forceFill(['hotel_id' => $hotel->id, 'room_type_id' => $legacy->room_type_id, 'name' => 'Synthetic breakfast', 'meal_plan' => 'BB', 'currency' => $currency,
                    'status' => 'active', 'policy' => ['version' => 'fixture-1', 'text' => 'Synthetic breakfast offer; no refund.'], 'version' => ($plan->version ?? 0) + 1])->save();
                foreach (DB::table('rate_plan_nights')->where('rate_plan_id', $legacy->id)->get() as $night) {
                    $data = (array) $night;
                    unset($data['id']);
                    $identity = ['rate_plan_id' => $plan->id, 'stay_date' => $night->stay_date];
                    $data['rate_plan_id'] = $plan->id;
                    $data['base_minor'] = $amount;
                    $data['tax_minor'] = 0;
                    $data['fee_minor'] = 0;
                    $data['version'] = (DB::table('rate_plan_nights')->where($identity)->value('version') ?? 0) + 1;
                    DB::table('rate_plan_nights')->updateOrInsert($identity, $data);
                }
            }
            $employee = User::firstOrNew(['email' => 'wizard-employee@example.test']);
            $employee->forceFill(['name' => 'Synthetic wizard employee', 'password' => Hash::make(getenv('DISCOVERY_FIXTURE_PASSWORD')), 'platform_role' => 'onboarding'])->save();
            $draft = Hotel::where('created_by', $employee->id)->where('name', 'Synthetic wizard draft')->first();
            if (! $draft) {
                $draft = new Hotel;
                $draft->forceFill(['created_by' => $employee->id, 'name' => 'Synthetic wizard draft', 'country' => 'LK', 'city' => 'Galle', 'status' => 'draft',
                    'onboarding_data' => ['inventory_request' => 'manual', 'rooms' => [['name' => 'Legacy draft double', 'occupancy' => 2, 'quantity' => 3, 'rate' => 9999]]]])->save();
            }
            $this->command->info('Synthetic wizard draft hotel_id='.$draft->id.'; employee wizard-employee@example.test uses supplied fixture password.');
        });
    }
}
