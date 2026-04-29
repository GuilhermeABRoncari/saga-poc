<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Aws\Sfn\SfnClient;
use Ramsey\Uuid\Uuid;

$count = (int) ($argv[1] ?? 100);

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

$arns = [];
$tStart = microtime(true);
for ($i = 0; $i < $count; $i++) {
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
    $arns[] = $result['executionArn'];
}
$tFired = microtime(true);
echo "fired={$count} executions in " . number_format(($tFired - $tStart) * 1000, 0) . "ms\n";

$completed = 0;
$failed = 0;
$deadline = microtime(true) + 180;
$pending = $arns;
while ($pending && microtime(true) < $deadline) {
    foreach ($pending as $idx => $arn) {
        $desc = $sfn->describeExecution(['executionArn' => $arn]);
        if ($desc['status'] === 'RUNNING') {
            continue;
        }
        if ($desc['status'] === 'SUCCEEDED') {
            $completed++;
        } else {
            $failed++;
        }
        unset($pending[$idx]);
    }
    if ($pending) {
        usleep(100000);
    }
}
$tDone = microtime(true);
echo "all_done elapsed_total_ms=" . number_format(($tDone - $tStart) * 1000, 0) . "\n";
echo "elapsed_after_fire_ms=" . number_format(($tDone - $tFired) * 1000, 0) . "\n";
echo "  SUCCEEDED={$completed}\n";
echo "  FAILED={$failed}\n";
echo "  PENDING=" . count($pending) . "\n";
