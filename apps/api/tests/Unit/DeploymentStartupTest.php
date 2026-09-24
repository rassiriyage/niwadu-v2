<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DeploymentStartupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/niwadu-startup-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/storage/app/private', 0700, true);
        $this->directory = realpath($this->directory);
        mkdir($this->directory.'/bin', 0700);
        touch($this->directory.'/artisan');
        copy(dirname(__DIR__, 2).'/verify-entrypoint.sh', $this->directory.'/verify-entrypoint.sh');
        mkdir($this->directory.'/public', 0700);
        copy(dirname(__DIR__, 2).'/public/index.php', $this->directory.'/public/index.php');
        file_put_contents($this->directory.'/bin/php', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$COMMAND_LOG"
case "$*" in
    *"${FAIL_COMMAND:-never-match}"*) exit 9 ;;
esac
SH);
        file_put_contents($this->directory.'/bin/docker-php-entrypoint', <<<'SH'
#!/bin/sh
printf 'server %s\n' "$*" >> "$COMMAND_LOG"
SH);
        chmod($this->directory.'/bin/php', 0700);
        chmod($this->directory.'/bin/docker-php-entrypoint', 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_missing_key_or_database_configuration_stops_before_artisan_without_exposing_secrets(): void
    {
        foreach (['APP_KEY' => '', 'DB_URL' => '', 'DB_CONNECTION' => 'sqlite'] as $name => $value) {
            $process = $this->start([$name => $value]);
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString($name, $process->getErrorOutput());
            $this->assertStringNotContainsString('secret-fixture', $process->getOutput().$process->getErrorOutput());
            $this->assertFileDoesNotExist($this->directory.'/commands');
        }
    }

    public function test_missing_or_wrong_photo_volume_stops_before_artisan(): void
    {
        foreach (['', '/wrong/volume'] as $path) {
            $process = $this->start(['RAILWAY_VOLUME_MOUNT_PATH' => $path]);
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('photo volume', $process->getErrorOutput());
            $this->assertFileDoesNotExist($this->directory.'/commands');
        }
        rmdir($this->directory.'/storage/app/private');
        $this->assertFalse($this->start()->isSuccessful());
        $this->assertFileDoesNotExist($this->directory.'/commands');
    }

    public function test_valid_startup_preserves_photo_and_only_prepares_caches_before_server(): void
    {
        $photo = $this->directory.'/storage/app/private/existing-photo';
        file_put_contents($photo, 'retained');
        $process = $this->start();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('retained', file_get_contents($photo));
        $commands = file_get_contents($this->directory.'/commands');
        $this->assertStringContainsString('artisan config:cache', $commands);
        $this->assertStringEndsWith("server --config /Caddyfile --adapter caddyfile\n", $commands);
        foreach (['migrate', 'seed', 'key:generate', 'storage:link', 'optimize:clear', 'cache:clear'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $commands);
        }
    }

    public function test_cache_failure_prevents_server_start(): void
    {
        $process = $this->start(['FAIL_COMMAND' => 'config:cache']);
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString('server ', file_get_contents($this->directory.'/commands'));
    }

    public function test_pre_deploy_requires_postgresql_but_not_the_runtime_volume(): void
    {
        $process = $this->start(['DB_URL' => ''], 'pre-deploy.sh');
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('DB_URL', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/commands');
        $process = $this->start(['RAILWAY_VOLUME_MOUNT_PATH' => ''], 'pre-deploy.sh');
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame("artisan config:clear --no-interaction\nartisan niwadu:check-deployment-key --no-interaction\nartisan niwadu:check-deployment-database --no-interaction\nartisan migrate --force --no-interaction\n", file_get_contents($this->directory.'/commands'));
    }

    public function test_effective_driver_rejection_prevents_migrations_and_server_start(): void
    {
        foreach (['start-container.sh', 'pre-deploy.sh'] as $script) {
            $process = $this->start(['FAIL_COMMAND' => 'niwadu:check-deployment-database'], $script);
            $this->assertFalse($process->isSuccessful());
            $commands = file_get_contents($this->directory.'/commands');
            $this->assertStringNotContainsString('artisan migrate', $commands);
            $this->assertStringNotContainsString('server ', $commands);
        }
    }

    public function test_invalid_key_stops_before_database_migrations_and_server(): void
    {
        foreach (['start-container.sh', 'pre-deploy.sh'] as $script) {
            $process = $this->start(['FAIL_COMMAND' => 'niwadu:check-deployment-key'], $script);
            $this->assertFalse($process->isSuccessful());
            $commands = file_get_contents($this->directory.'/commands');
            $this->assertStringNotContainsString('check-deployment-database', $commands);
            $this->assertStringNotContainsString('artisan migrate', $commands);
            $this->assertStringNotContainsString('server ', $commands);
        }
    }

    public function test_placeholder_or_missing_entrypoint_prevents_startup(): void
    {
        file_put_contents($this->directory.'/public/index.php', '<title>FrankenPHP | Welcome!</title>');
        $process = $this->start();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Laravel entrypoint', $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory.'/commands');
        unlink($this->directory.'/public/index.php');
        $process = $this->start();
        $this->assertFalse($process->isSuccessful());
        $this->assertFileDoesNotExist($this->directory.'/commands');
    }

    private function start(array $overrides = [], string $script = 'start-container.sh'): Process
    {
        $process = new Process(['sh', dirname(__DIR__, 2).'/'.$script], $this->directory, array_merge([
            'PATH' => $this->directory.'/bin:'.getenv('PATH'),
            'APP_KEY' => 'secret-fixture',
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => 'postgresql://secret-fixture@unreachable/unused',
            'RAILWAY_VOLUME_MOUNT_PATH' => $this->directory.'/storage/app/private',
            'COMMAND_LOG' => $this->directory.'/commands',
            'FAIL_COMMAND' => '',
        ], $overrides));
        $process->run();

        return $process;
    }
}
