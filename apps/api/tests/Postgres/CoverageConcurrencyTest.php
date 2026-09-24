<?php

namespace Tests\Postgres;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoverageConcurrencyTest extends TestCase
{
    public function test_concurrent_first_saves_and_updates_have_only_one_winner(): void
    {
        require dirname(__DIR__).'/postgres-bootstrap.php';
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $user = User::factory()->create();
        foreach ([0, 1] as $version) {
            $statuses = $this->whileUserLocked($user, [
                ['districts' => ['kandy'], 'version' => $version],
                ['districts' => ['galle'], 'version' => $version],
            ]);
            sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $saved = DB::table('user_coverage')->where('user_id', $user->id)->first();
            $this->assertSame($version + 1, (int) $saved->version);
            $this->assertContains(json_decode($saved->districts, true), [['kandy'], ['galle']]);
            $this->assertSame(1, DB::table('user_coverage')->count());
        }
    }

    private function whileUserLocked(User $user, array $commands): array
    {
        $workers = [];
        DB::beginTransaction();
        try {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            foreach ($commands as $body) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/coverage-worker.php'), (string) $user->id, json_encode($body, JSON_THROW_ON_ERROR)],
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
                $waiting = DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity WHERE usename = current_user AND wait_event_type = 'Lock' AND query ILIKE '%users%' ")->count;
                if ((int) $waiting === count($workers)) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(count($workers), (int) $waiting, 'Workers must be blocked on the held row before releasing the barrier.');
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
