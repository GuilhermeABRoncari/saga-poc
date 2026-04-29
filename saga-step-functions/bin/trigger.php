<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Aws\Sfn\SfnClient;
use Ramsey\Uuid\Uuid;

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

$execName = 'saga-' . Uuid::uuid4()->toString();
$payload = [
    'product_id' => 'p_' . random_int(100, 999),
    'quantity' => 2,
    'user_id' => 'u_' . random_int(100, 999),
    'amount' => 199.90,
    '_meta' => ['execution' => $execName],
];

$result = $sfn->startExecution([
    'stateMachineArn' => $stateMachineArn,
    'name' => $execName,
    'input' => json_encode($payload, JSON_THROW_ON_ERROR),
]);

echo "started execution={$execName}\n";
echo "         arn={$result['executionArn']}\n";

$deadline = microtime(true) + 60;
$lastStatus = '?';
while (microtime(true) < $deadline) {
    $desc = $sfn->describeExecution(['executionArn' => $result['executionArn']]);
    $status = (string) ($desc['status'] ?? '');
    $lastStatus = $status;
    if ($status !== '' && $status !== 'RUNNING') {
        echo "result: status={$status}\n";
        if (!empty($desc['output'])) {
            echo "        output={$desc['output']}\n";
        }
        if (!empty($desc['error'])) {
            echo "        error={$desc['error']} cause={$desc['cause']}\n";
        }
        exit(0);
    }
    usleep(200000);
}
echo "TIMEOUT after 60s lastStatus={$lastStatus}\n";
exit(1);
