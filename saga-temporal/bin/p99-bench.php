<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Sagas\ActivateStoreSaga;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;

$count = (int) ($argv[1] ?? 1000);

$address = $_ENV['TEMPORAL_ADDRESS'] ?? 'temporal:7233';
$client = WorkflowClient::create(ServiceClient::create($address));

$latencies = [];
for ($i = 0; $i < $count; $i++) {
    $start = microtime(true);
    $stub = $client->newWorkflowStub(
        ActivateStoreSaga::class,
        WorkflowOptions::new()
            ->withTaskQueue('saga-orchestrator')
            ->withWorkflowExecutionTimeout(60),
    );
    $run = $client->start($stub, [
        'product_id' => 'p_' . random_int(100, 999),
        'quantity' => 2,
        'user_id' => 'u_' . random_int(100, 999),
        'amount' => 199.90,
    ]);
    try {
        $run->getResult(null, 30);
        $latencies[] = (microtime(true) - $start) * 1000;
    } catch (\Throwable $e) {
        // skip failed
    }
}

sort($latencies);
$n = count($latencies);
$p = fn(float $q) => $latencies[(int) min($n - 1, floor($q * $n))];
echo "n={$n}\n";
echo sprintf("p50=%.1fms p95=%.1fms p99=%.1fms max=%.1fms\n", $p(0.50), $p(0.95), $p(0.99), end($latencies));
echo sprintf("avg=%.1fms\n", array_sum($latencies) / max(1, $n));
