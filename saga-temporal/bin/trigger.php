<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Sagas\ActivateStoreSaga;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;

$address = $_ENV['TEMPORAL_ADDRESS'] ?? 'temporal:7233';
$client = WorkflowClient::create(ServiceClient::create($address));

$workflow = $client->newWorkflowStub(
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

$run = $client->start($workflow, $payload);
echo "started workflow_id={$run->getExecution()->getID()}\n";
echo "         run_id={$run->getExecution()->getRunID()}\n";

try {
    $result = $run->getResult();
    echo "result: " . json_encode($result) . "\n";
} catch (\Throwable $e) {
    echo "exception: {$e->getMessage()}\n";
}
