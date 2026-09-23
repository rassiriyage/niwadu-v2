<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckDeploymentDatabase extends Command
{
    protected $signature = 'niwadu:check-deployment-database';

    protected $description = 'Validate the effective PostgreSQL driver without connecting';

    public function handle(): int
    {
        try {
            if (DB::connection()->getDriverName() === 'pgsql') {
                return self::SUCCESS;
            }
        } catch (Throwable) {
            // Invalid connection configuration must not disclose credentials.
        }

        $this->error('Deployment requires an effective PostgreSQL connection driver.');

        return self::FAILURE;
    }
}
