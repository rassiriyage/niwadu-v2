<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__).'/postgres-bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Auth::setUser(User::findOrFail((int) $argv[1]));
echo "READY\n";
$request = Request::create('/api/v1/me/coverage', 'PUT', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
], $argv[2]);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo $response->getStatusCode()."\n";
