<?php

use App\ManualQuoteSource;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__).'/postgres-bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Auth::setUser(User::findOrFail((int) $argv[1]));
echo "READY\n";
if ($argv[2] === 'resolve') {
    echo json_encode($app->make(ManualQuoteSource::class)->resolve(json_decode($argv[3], true), new DateTimeImmutable('2026-10-01T00:00:00Z')), JSON_THROW_ON_ERROR);
    exit(0);
}
$request = Request::create($argv[2], 'PUT', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
], $argv[3]);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo $response->getStatusCode()."\n";
