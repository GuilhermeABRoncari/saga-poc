<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Sagas\ActivateStoreSaga;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;

$count = (int) ($argv[1] ?? 100);

$address = $_ENV['TEMPORAL_ADDRESS'] ?? 'temporal:7233';
$client = WorkflowClient::create(ServiceClient::create($address));

$runs = [];
$tStart = microtime(true);
for ($i = 0; $i < $count; $i++) {
    $stub = $client->newWorkflowStub(
        ActivateStoreSaga::class,
        WorkflowOptions::new()
            ->withTaskQueue('saga-orchestrator')
            ->withWorkflowExecutionTimeout(300),
    );
    $payload = [
        'product_id' => 'p_' . random_int(100, 999),
        'quantity' => 2,
        'user_id' => 'u_' . random_int(100, 999),
        'amount' => 199.90,
    ];
    $runs[] = $client->start($stub, $payload);
}
$tFired = microtime(true);
echo "fired={$count} workflows in " . number_format(($tFired - $tStart) * 1000, 0) . "ms\n";

$completed = 0;
$failed = 0;
foreach ($runs as $run) {
    try {
        $run->getResult(null, 120);
        $completed++;
    } catch (\Throwable $e) {
        $failed++;
    }
}
$tDone = microtime(true);
echo "all_done elapsed_total_ms=" . number_format(($tDone - $tStart) * 1000, 0) . "\n";
echo "elapsed_after_fire_ms=" . number_format(($tDone - $tFired) * 1000, 0) . "\n";
echo "  COMPLETED={$completed}\n";
echo "  FAILED={$failed}\n";
