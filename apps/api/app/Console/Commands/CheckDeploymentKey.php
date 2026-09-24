<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

class CheckDeploymentKey extends Command
{
    protected $signature = 'niwadu:check-deployment-key';

    protected $description = 'Validate the configured encryption key and cipher without changing them';

    public function handle(): int
    {
        $key = config('app.key');
        $cipher = config('app.cipher');
        if (is_string($key) && str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }
        if (is_string($key) && is_string($cipher) && Encrypter::supported($key, $cipher)) {
            return self::SUCCESS;
        }
        $this->error('APP_KEY is invalid for the configured cipher. Check its format and length; preserve the existing key and its base64: prefix when applicable.');

        return self::FAILURE;
    }
}
