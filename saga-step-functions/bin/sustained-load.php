<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Aws\Sfn\SfnClient;
use Ramsey\Uuid\Uuid;

$durationSec = (int) ($argv[1] ?? 300);
$ratePerSec = (int) ($argv[2] ?? 10);

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

$intervalUs = (int) (1_000_000 / $ratePerSec);
$end = microtime(true) + $durationSec;
$count = 0;

while (microtime(true) < $end) {
    $execName = 'saga-' . Uuid::uuid4()->toString();
    $sfn->startExecution([
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
    $count++;
    usleep($intervalUs);
}
echo "fired={$count} executions over {$durationSec}s\n";
