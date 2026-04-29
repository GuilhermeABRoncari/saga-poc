<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Aws\Sfn\SfnClient;
use Ramsey\Uuid\Uuid;

$count = (int) ($argv[1] ?? 1000);

$sfn = new SfnClient([
    'region' => $_ENV['AWS_REGION'] ?? 'us-east-1',
    'version' => 'latest',
    'endpoint' => $_ENV['AWS_ENDPOINT_URL'] ?? 'http://localstack:4566',
    'credentials' => [
        'key' => $_ENV['AWS_ACCESS_KEY_ID'] ?? 'test',
        'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? 'test',
    ],
]);

$config = json_decode(file_get_contents('/app/storage/sfn-config.json'), true, 512, JSON_THROW_ON_ERROR);
$stateMachineArn = $config['state_machine_arn'];

$latencies = [];
for ($i = 0; $i < $count; $i++) {
    $start = microtime(true);
    $execName = 'saga-' . Uuid::uuid4()->toString();
    $result = $sfn->startExecution([
        'stateMachineArn' => $stateMachineArn,
        'name' => $execName,
        'input' => json_encode([
            'product_id' => 'p_' . random_int(100, 999),
            'quantity' => 2,
            'user_id' => 'u_' . random_int(100, 999),
            'amount' => 199.90,
            '_meta' => ['execution' => $execName],
        ], JSON_THROW_ON_ERROR),
    ]);
    $deadline = $start + 60;
    while (microtime(true) < $deadline) {
        $desc = $sfn->describeExecution(['executionArn' => $result['executionArn']]);
        if ($desc['status'] !== 'RUNNING') {
            $latencies[] = (microtime(true) - $start) * 1000;
            break;
        }
        usleep(50000);
    }
}

sort($latencies);
$n = count($latencies);
$p = fn(float $q) => $latencies[(int) min($n - 1, floor($q * $n))];
echo "n={$n}\n";
echo sprintf("p50=%.1fms p95=%.1fms p99=%.1fms max=%.1fms\n", $p(0.50), $p(0.95), $p(0.99), end($latencies));
echo sprintf("avg=%.1fms\n", array_sum($latencies) / max(1, $n));
