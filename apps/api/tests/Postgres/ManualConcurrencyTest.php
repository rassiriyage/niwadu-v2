<?php

namespace Tests\Postgres;

use App\Models\Hotel;
use App\Models\InventoryPool;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualConcurrencyTest extends TestCase
{
    public function test_competing_stock_edits_have_one_winner_and_database_guards_counts_and_ownership(): void
    {
        require dirname(__DIR__).'/postgres-bootstrap.php';
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create();
        $room = RoomType::factory()->create(['hotel_id' => $hotel->id]);
        $pool = InventoryPool::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'owner' => 'manual']);
        $uri = "/api/v1/hotels/{$hotel->id}/room-types/{$room->id}/inventory-nights/2026-10-10";
        $statuses = $this->whileHotelLocked($hotel, $admin, [[$uri, ['version' => 0, 'capacity' => 3]], [$uri, ['version' => 0, 'capacity' => 4]]]);
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('inventory_nights', 1);
        $this->assertDatabaseHas('inventory_nights', ['inventory_pool_id' => $pool->id, 'version' => 1]);
        try {
            DB::transaction(fn () => DB::table('inventory_nights')->where('inventory_pool_id', $pool->id)->update(['held' => 100]));
            $this->fail('Database accepted obligations beyond capacity.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        $other = Hotel::factory()->create();
        try {
            DB::transaction(fn () => RatePlan::factory()->create(['hotel_id' => $other->id, 'room_type_id' => $room->id]));
            $this->fail('Database accepted a cross-hotel rate plan.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }
    }

    public function test_quote_read_waits_for_atomic_dated_edits_and_observes_one_coherent_revision(): void
    {
        require dirname(__DIR__).'/postgres-bootstrap.php';
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create(['discovery_snapshot' => ['public' => ['name' => 'Synthetic']]]);
        $room = RoomType::factory()->create(['hotel_id' => $hotel->id, 'status' => 'active']);
        $pool = InventoryPool::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'owner' => 'manual', 'sales_state' => 'open', 'timezone' => 'Asia/Colombo']);
        $plan = RatePlan::factory()->create(['hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'status' => 'active']);
        foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $date) {
            DB::table('rate_plan_nights')->insert(['rate_plan_id' => $plan->id, 'stay_date' => $date, 'base_minor' => 100, 'tax_minor' => 0, 'fee_minor' => 0,
                'mandatory_charges_complete' => true, 'stop_sell' => false, 'min_stay' => 1, 'max_stay' => 30, 'closed_to_arrival' => false, 'closed_to_departure' => false, 'version' => 1]);
            DB::table('inventory_nights')->insert(['inventory_pool_id' => $pool->id, 'stay_date' => $date, 'capacity' => 3, 'version' => 1]);
        }
        $selection = ['hotel_id' => $hotel->id, 'rate_plan_id' => $plan->id, 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2];
        $results = $this->whileHotelLocked($hotel, $admin, [['resolve', $selection]], function () use ($plan, $pool): void {
            DB::table('rate_plan_nights')->where('rate_plan_id', $plan->id)->update(['base_minor' => 200, 'version' => 2]);
            DB::table('inventory_nights')->where('inventory_pool_id', $pool->id)->update(['capacity' => 2, 'version' => 2]);
        });
        $this->assertSame([200, 200], array_column($results[0]['input']['nightly'], 'base_minor'));
        $this->assertSame([2, 2], array_column($results[0]['input']['nightly_conditions'], 'available'));
        $this->assertSame([2, 2], array_column($results[0]['source_revision']['nights'], 'rate_version'));
        $this->assertSame(2, $results[0]['source_revision']['checkout_rate_version']);
    }

    private function whileHotelLocked(Hotel $hotel, User $admin, array $commands, ?\Closure $beforeCommit = null): array
    {
        $workers = [];
        DB::beginTransaction();
        try {
            Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            foreach ($commands as [$uri, $body]) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/manual-worker.php'), (string) $admin->id,
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
            $beforeCommit?->__invoke();
            DB::commit();
            $statuses = [];
            foreach ($workers as [$process, $pipes]) {
                $output = trim(stream_get_contents($pipes[1]));
                $statuses[] = $commands[0][0] === 'resolve' ? json_decode($output, true, flags: JSON_THROW_ON_ERROR) : (int) $output;
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
