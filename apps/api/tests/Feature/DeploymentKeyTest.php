<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DeploymentKeyTest extends TestCase
{
    public function test_valid_raw_and_base64_keys_for_supported_ciphers_are_preserved(): void
    {
        foreach (['AES-128-CBC' => 16, 'AES-256-CBC' => 32, 'aes-256-gcm' => 32] as $cipher => $length) {
            foreach ([str_repeat('k', $length), 'base64:'.base64_encode(str_repeat('k', $length))] as $key) {
                config(['app.key' => $key, 'app.cipher' => $cipher]);
                $this->assertSame(0, Artisan::call('niwadu:check-deployment-key'));
                $this->assertSame($key, config('app.key'));
                $this->assertStringNotContainsString($key, Artisan::output());
            }
        }
    }

    public function test_invalid_effective_keys_fail_without_disclosing_them(): void
    {
        foreach (['', 'secret-fixture', base64_encode(str_repeat('k', 32)), 'base64:!!!secret-fixture', 'base64:'.base64_encode('short')] as $key) {
            config(['app.key' => $key, 'app.cipher' => 'AES-256-CBC']);
            $this->assertSame(1, Artisan::call('niwadu:check-deployment-key'));
            $this->assertStringContainsString('APP_KEY', Artisan::output());
            if ($key !== '') {
                $this->assertStringNotContainsString($key, Artisan::output());
            }
            $this->assertSame($key, config('app.key'));
        }
        config(['app.key' => str_repeat('k', 32), 'app.cipher' => 'unsupported']);
        $this->assertSame(1, Artisan::call('niwadu:check-deployment-key'));
    }
}
