<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeploymentDatabaseTest extends TestCase
{
    public function test_rejects_url_driver_overrides_without_exposing_connection_secrets(): void
    {
        config(['database.default' => 'pgsql']);
        foreach (['sqlite:///:memory:', 'mysql://fixture:secret@127.0.0.1:1/unused', 'postgresql://fixture:secret@127.0.0.1:1/unused?driver=sqlite'] as $url) {
            DB::purge('pgsql');
            config(['database.connections.pgsql.url' => $url]);
            $this->artisan('niwadu:check-deployment-database')
                ->expectsOutput('Deployment requires an effective PostgreSQL connection driver.')
                ->doesntExpectOutputToContain('secret')
                ->assertFailed();
        }
    }

    public function test_accepts_postgresql_without_opening_the_connection(): void
    {
        config(['database.default' => 'pgsql', 'database.connections.pgsql.url' => 'postgresql://fixture:secret@127.0.0.1:1/unused']);
        DB::purge('pgsql');
        $this->artisan('niwadu:check-deployment-database')->assertSuccessful();
        $this->assertInstanceOf(Closure::class, DB::connection()->getRawPdo());
    }
}
