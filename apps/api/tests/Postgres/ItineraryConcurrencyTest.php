<?php

namespace Tests\Postgres;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItineraryConcurrencyTest extends TestCase
{
    public function test_same_key_create_race_and_versioned_update_race_have_one_effect(): void
    {
        require dirname(__DIR__).'/postgres-bootstrap.php';
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $body = ['name' => 'Concurrent trip', 'stops' => [['slug' => 'ella', 'nights' => 2]]];
        $results = $this->whileLocked($user, [['POST', $body, $key], ['POST', $body, $key]]);
        $this->assertSame([201, 201], array_column($results, 'status'));
        $this->assertSame($results[0]['body'], $results[1]['body']);
        $this->assertSame(1, DB::table('itineraries')->count());
        $this->assertSame(1, DB::table('itinerary_creations')->count());
        $id = $results[0]['body']['data']['id'];
        $results = $this->whileLocked($user, [
            ['PUT', [...$body, 'name' => 'First edit', 'version' => 1], $key],
            ['PUT', [...$body, 'name' => 'Second edit', 'version' => 1], $key],
        ], $id);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(2, DB::table('itineraries')->where('id', $id)->value('version'));
    }

    private function whileLocked(User $user, array $commands, ?int $itinerary = null): array
    {
        $workers = [];
        DB::beginTransaction();
        try {
            if ($itinerary === null) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            } else {
                DB::table('itineraries')->where('id', $itinerary)->lockForUpdate()->first();
            }
            foreach ($commands as [$method, $body, $key]) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/itinerary-worker.php'), (string) $user->id, $method, (string) ($itinerary ?? ''), $key, json_encode($body, JSON_THROW_ON_ERROR)],
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
                $waiting = DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity WHERE usename = current_user AND wait_event_type = 'Lock' AND query ILIKE ? ", [$itinerary === null ? '%users%' : '%itineraries%'])->count;
                if ((int) $waiting === count($workers)) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(count($workers), (int) $waiting, 'Workers must be blocked on the held row before releasing the barrier.');
            DB::commit();
            $statuses = [];
            foreach ($workers as [$process, $pipes]) {
                $statuses[] = json_decode(trim(stream_get_contents($pipes[1])), true, flags: JSON_THROW_ON_ERROR);
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
