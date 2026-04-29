<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Sagas\ActivateStoreSaga;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;

$durationSec = (int) ($argv[1] ?? 300);
$ratePerSec = (int) ($argv[2] ?? 10);

$address = $_ENV['TEMPORAL_ADDRESS'] ?? 'temporal:7233';
$client = WorkflowClient::create(ServiceClient::create($address));

$intervalUs = (int) (1_000_000 / $ratePerSec);
$end = microtime(true) + $durationSec;
$count = 0;

while (microtime(true) < $end) {
    $stub = $client->newWorkflowStub(
        ActivateStoreSaga::class,
        WorkflowOptions::new()
            ->withTaskQueue('saga-orchestrator')
            ->withWorkflowExecutionTimeout(300),
    );
    $client->start($stub, [
        'product_id' => 'p_' . random_int(100, 999),
        'quantity' => 2,
        'user_id' => 'u_' . random_int(100, 999),
        'amount' => 199.90,
    ]);
    $count++;
    usleep($intervalUs);
}
echo "fired={$count} workflows over {$durationSec}s\n";
