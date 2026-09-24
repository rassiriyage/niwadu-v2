<?php

namespace Tests\Postgres;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscoveryConcurrencyTest extends TestCase
{
    public function test_two_reviewed_releases_have_one_winner_and_withdrawal_fences_a_delayed_release(): void
    {
        require dirname(__DIR__).'/postgres-bootstrap.php';
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $admin = User::factory()->create(['platform_role' => 'administrator']);
        $hotel = Hotel::factory()->create(['description' => 'Synthetic concurrent fixture.', 'onboarding_data' => ['property_type' => 'hotel']]);
        $input = ['onboarding_version' => 0, 'discovery_version' => 0, 'slug' => 'race-fixture'];
        $statuses = $this->whileHotelLocked($hotel, $admin, [['PUT', $input], ['PUT', $input]]);
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, $hotel->fresh()->discovery_version);
        $this->assertSame(1, DB::table('hotel_access_events')->where('action', 'discovery.released')->count());
        $input['discovery_version'] = 1;
        $statuses = $this->whileHotelLocked($hotel, $admin, [['PUT', $input]], true);
        $this->assertSame([409], $statuses);
        $this->assertNull($hotel->fresh()->discovery_snapshot);
        $this->assertSame(2, $hotel->fresh()->discovery_version);
    }

    private function whileHotelLocked(Hotel $hotel, User $admin, array $commands, bool $withdraw = false): array
    {
        $workers = [];
        DB::beginTransaction();
        try {
            Hotel::whereKey($hotel->id)->lockForUpdate()->firstOrFail();
            foreach ($commands as [$method, $body]) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/discovery-worker.php'), (string) $admin->id,
                    (string) $hotel->id, $method, json_encode($body, JSON_THROW_ON_ERROR)],
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
            if ($withdraw) {
                $this->actingAs($admin)->deleteJson('/api/v1/hotels/'.$hotel->id.'/discovery', ['discovery_version' => 1])->assertOk();
            }
            DB::commit();
            $statuses = [];
            foreach ($workers as [$process, $pipes]) {
                $statuses[] = (int) trim(stream_get_contents($pipes[1]));
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
