<?php
// Isolated browser fixture only. Never imported by application code.
$api = getenv('PLANNING_API_PATH');
if (!$api || !str_starts_with(realpath($api), '/private/tmp/niwadu-planning-api.')) throw new RuntimeException('Use an isolated planning API archive.');
require $api.'/vendor/autoload.php';
$app = require $api.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.default') !== 'sqlite' || !str_starts_with(config('database.connections.sqlite.database'), $api)) throw new RuntimeException('Fixture database guard failed.');
if (($argv[1] ?? '') === 'clear-cache') { Illuminate\Support\Facades\Cache::flush(); exit; }
if (App\Models\User::where('email', 'planning@example.test')->exists()) exit;
App\Models\User::factory()->create(['name'=>'Planning Traveller','email'=>'planning@example.test','password'=>Illuminate\Support\Facades\Hash::make('planning-test-password')]);
App\Models\User::factory()->create(['name'=>'Other Traveller','email'=>'other-planning@example.test','password'=>Illuminate\Support\Facades\Hash::make('planning-test-password')]);
$hotel=App\Models\Hotel::factory()->create(['discovery_slug'=>'planning-fixture','discovery_snapshot'=>['public'=>['name'=>'Planning fixture','description'=>'Synthetic isolated planning fixture.','property_type'=>'hotel','city'=>'Test city','country'=>'LK']]]);
$room=App\Models\RoomType::factory()->create(['hotel_id'=>$hotel->id,'name'=>'Garden room','status'=>'active','max_occupancy'=>2]);
$pool=App\Models\InventoryPool::factory()->create(['hotel_id'=>$hotel->id,'room_type_id'=>$room->id,'owner'=>'manual','sales_state'=>'open','timezone'=>'Asia/Colombo']);
$plan=App\Models\RatePlan::factory()->create(['hotel_id'=>$hotel->id,'room_type_id'=>$room->id,'name'=>'Breakfast plan','status'=>'active','currency'=>'USD','meal_plan'=>'BB']);
foreach (['2030-01-10','2030-01-11','2030-01-12'] as $date) {
 Illuminate\Support\Facades\DB::table('inventory_nights')->insert(['inventory_pool_id'=>$pool->id,'stay_date'=>$date,'capacity'=>2,'held'=>0,'sold'=>0,'version'=>1]);
 Illuminate\Support\Facades\DB::table('rate_plan_nights')->insert(['rate_plan_id'=>$plan->id,'stay_date'=>$date,'base_minor'=>10000,'tax_minor'=>1000,'fee_minor'=>0,'mandatory_charges_complete'=>true,'stop_sell'=>false,'min_stay'=>1,'max_stay'=>30,'closed_to_arrival'=>false,'closed_to_departure'=>false,'version'=>1]);
}
