<?php

use App\ManualInventoryHoldService;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__).'/postgres-bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$command = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
$now = $command['now'] ?? '2026-10-01T00:00:00Z';
Carbon::setTestNow($now);
CarbonImmutable::setTestNow($now);
$service = $app->make(ManualInventoryHoldService::class);
echo "READY\n";
try {
    $data = match ($argv[2]) {
        'acquire' => $service->acquire(User::findOrFail($command['owner_id']), $command['intent_id'], new DateTimeImmutable('2026-10-01T00:05:00Z')),
        'release' => $service->release(User::findOrFail($command['owner_id']), $command['hold_id']),
        'expire' => $service->expireDue(),
    };
    echo json_encode(['status' => 200, 'data' => $data], JSON_THROW_ON_ERROR);
} catch (HttpException $exception) {
    echo json_encode(['status' => $exception->getStatusCode(), 'reason' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
