<?php

namespace Tests\Postgres;

use App\BookingWorkflow;
use App\ManualInventoryHoldService;
use App\Models\Hotel;
use App\Models\InventoryPool;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualHoldConcurrencyTest extends TestCase
{
    public function test_two_meal_currency_offers_compete_for_one_shared_pool_without_overselling_any_night(): void
    {
        [$owner, $selection, $plan] = $this->offering();
        $first = $this->intent($owner, $selection);
        $other = User::factory()->create();
        $secondPlan = RatePlan::factory()->create(['hotel_id' => $plan->hotel_id, 'room_type_id' => $plan->room_type_id, 'status' => 'active', 'meal_plan' => 'HB', 'currency' => 'USD']);
        foreach (DB::table('rate_plan_nights')->where('rate_plan_id', $plan->id)->get() as $night) {
            $row = (array) $night;
            unset($row['id']);
            $row['rate_plan_id'] = $secondPlan->id;
            DB::table('rate_plan_nights')->insert($row);
        }
        $second = $this->intent($other, array_replace($selection, ['rate_plan_id' => $secondPlan->id]));
        $results = $this->whileHotelLocked(Hotel::findOrFail($plan->hotel_id), $owner, [
            ['acquire', ['owner_id' => $owner->id, 'intent_id' => $first['id']]],
            ['acquire', ['owner_id' => $other->id, 'intent_id' => $second['id']]],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('manual_inventory_holds', 1);
        $this->assertDatabaseCount('manual_inventory_hold_nights', 2);
        $this->assertSame([1, 1], DB::table('inventory_nights')->orderBy('stay_date')->pluck('held')->all());
        $this->assertSame(0, (int) DB::table('inventory_nights')->sum('sold'));
    }

    public function test_duplicate_acquisition_and_duplicate_release_have_one_counter_transition(): void
    {
        [$owner, $selection, $plan] = $this->offering();
        $intent = $this->intent($owner, $selection);
        $hotel = Hotel::findOrFail($plan->hotel_id);
        $command = ['acquire', ['owner_id' => $owner->id, 'intent_id' => $intent['id']]];
        $results = $this->whileHotelLocked($hotel, $owner, [$command, $command]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame($results[0], $results[1]);
        $this->assertDatabaseCount('manual_inventory_holds', 1);
        $command = ['release', ['owner_id' => $owner->id, 'hold_id' => $results[0]['data']['id']]];
        $released = $this->whileHotelLocked($hotel, $owner, [$command, $command]);
        $this->assertSame([200, 200], array_column($released, 'status'));
        $this->assertSame($released[0], $released[1]);
        $this->assertSame('released', $released[0]['data']['state']);
        $this->assertSame([0, 0], DB::table('inventory_nights')->orderBy('stay_date')->pluck('held')->all());
        $this->assertSame([3, 3], DB::table('inventory_nights')->orderBy('stay_date')->pluck('version')->all());
    }

    public function test_release_and_expiry_recovery_race_at_exact_deadline_decrements_once(): void
    {
        [$owner, $selection, $plan] = $this->offering();
        $intent = $this->intent($owner, $selection);
        $hold = app(ManualInventoryHoldService::class)->acquire($owner, $intent['id'], now()->addMinutes(5)->toDateTimeImmutable());
        $results = $this->whileHotelLocked(Hotel::findOrFail($plan->hotel_id), $owner, [
            ['release', ['owner_id' => $owner->id, 'hold_id' => $hold['id'], 'now' => '2026-10-01T00:05:00Z']],
            ['expire', ['now' => '2026-10-01T00:05:00Z']],
        ]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame('expired', $results[0]['data']['state']);
        $this->assertDatabaseHas('manual_inventory_holds', ['id' => $hold['id'], 'state' => 'expired']);
        $this->assertSame([0, 0], DB::table('inventory_nights')->orderBy('stay_date')->pluck('held')->all());
        $this->assertSame([3, 3], DB::table('inventory_nights')->orderBy('stay_date')->pluck('version')->all());
    }

    public function test_stock_batch_and_hold_serialize_without_partial_calendar_or_overselling(): void
    {
        [$owner, $selection, $plan] = $this->offering();
        $intent = $this->intent($owner, $selection);
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $results = $this->whileHotelLocked(Hotel::findOrFail($plan->hotel_id), $owner, [
            ['stock-batch', ['admin_id' => $admin->id, 'hotel_id' => $plan->hotel_id, 'room_id' => $plan->room_type_id,
                'nights' => [['stay_date' => '2026-10-10', 'version' => 1, 'capacity' => 0], ['stay_date' => '2026-10-11', 'version' => 1, 'capacity' => 0]]]],
            ['acquire', ['owner_id' => $owner->id, 'intent_id' => $intent['id']]],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $batchWon = $results[0]['status'] === 200;
        foreach (DB::table('inventory_nights')->orderBy('stay_date')->get() as $night) {
            $this->assertSame($batchWon ? 0 : 1, $night->capacity);
            $this->assertSame($batchWon ? 0 : 1, $night->held);
            $this->assertSame(0, $night->sold);
            $this->assertSame(2, $night->version);
        }
        $this->assertDatabaseCount('manual_inventory_holds', $batchWon ? 0 : 1);
        $this->assertDatabaseCount('manual_inventory_hold_nights', $batchWon ? 0 : 2);
        $this->assertSame($batchWon ? 2 : 0, DB::table('hotel_access_events')->where('action', 'inventory.stock_saved')->count());
    }

    private function intent(User $owner, array $selection): array
    {
        $workflow = app(BookingWorkflow::class);
        $quote = $workflow->requestQuote($owner, $selection)['quote'];

        return $workflow->createIntent($owner, ['quote_id' => $quote['id']], 'hold-test-intent');
    }

    private function offering(): array
    {
        require dirname(__DIR__).'/postgres-bootstrap.php';
        $this->assertSame('UTC', DB::selectOne('SHOW timezone')->TimeZone);
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->travelTo(new \DateTimeImmutable('2026-10-01T00:00:00Z'));
        $hotel = Hotel::factory()->create(['discovery_snapshot' => ['public' => ['name' => 'Synthetic']]]);
        $room = RoomType::factory()->create(['hotel_id' => $hotel->id, 'status' => 'active', 'max_occupancy' => 2]);
        $pool = InventoryPool::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'owner' => 'manual', 'sales_state' => 'open', 'timezone' => 'Asia/Colombo']);
        $plan = RatePlan::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'status' => 'active']);
        DB::table('inventory_nights')->insert(['inventory_pool_id' => $pool->id, 'stay_date' => '2026-10-10', 'capacity' => 1, 'held' => 0, 'sold' => 0, 'version' => 1]);
        DB::table('inventory_nights')->insert(['inventory_pool_id' => $pool->id, 'stay_date' => '2026-10-11', 'capacity' => 1, 'held' => 0, 'sold' => 0, 'version' => 1]);
        foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $date) {
            DB::table('rate_plan_nights')->insert(['rate_plan_id' => $plan->id, 'stay_date' => $date, 'base_minor' => 10000, 'tax_minor' => 1000, 'fee_minor' => 0, 'mandatory_charges_complete' => true, 'stop_sell' => false, 'min_stay' => 1, 'max_stay' => 30, 'closed_to_arrival' => false, 'closed_to_departure' => false, 'version' => 1]);
        }

        return [User::factory()->create(), ['hotel_id' => $hotel->id, 'rate_plan_id' => $plan->id, 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2], $plan];
    }

    private function whileHotelLocked(Hotel $hotel, User $owner, array $commands): array
    {
        $workers = [];
        DB::beginTransaction();
        try {
            Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            foreach ($commands as [$uri, $body]) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/manual-hold-worker.php'), (string) $owner->id,
                    $uri, json_encode($body, JSON_THROW_ON_ERROR)],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), $_ENV));
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 10);
                $workers[] = [$process, $pipes];
                $this->assertSame("READY\n", fgets($pipes[1]));
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity WHERE usename = current_user AND wait_event_type = 'Lock' AND query ILIKE '%hotels%' ")->count;
                if ((int) $waiting === count($workers)) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(count($workers), (int) $waiting, 'Workers must be blocked on the held row before releasing the barrier.');
            DB::commit();
            $statuses = [];
            foreach ($workers as [$process, $pipes]) {
                $output = trim(stream_get_contents($pipes[1]));
                $statuses[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $errors);
            }
            $workers = [];

            return $statuses;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ([1, 2] as $index) {
                    if (is_resource($pipes[$index])) {
                        fclose($pipes[$index]);
                    }
                }
                proc_close($process);
            }
        }
    }
}
