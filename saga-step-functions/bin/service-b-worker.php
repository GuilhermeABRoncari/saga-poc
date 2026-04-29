<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\ActivityWorker;

$sfnConfig = [
    'region' => $_ENV['AWS_REGION'] ?? 'us-east-1',
    'version' => 'latest',
    'endpoint' => $_ENV['AWS_ENDPOINT_URL'] ?? 'http://localstack:4566',
    'credentials' => [
        'key' => $_ENV['AWS_ACCESS_KEY_ID'] ?? 'test',
        'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? 'test',
    ],
];

$config = json_decode(file_get_contents('/app/storage/sfn-config.json'), true, 512, JSON_THROW_ON_ERROR);
$arns = $config['activities'];

$chargeCredit = function (array $payload): array {
    $userId = $payload['user_id'] ?? '?';
    $amount = $payload['amount'] ?? 0;
    $chargeId = 'chg_' . bin2hex(random_bytes(4));
    echo "  → ChargeCredit: user={$userId} amount={$amount} → {$chargeId}\n";
    return ['charge_id' => $chargeId];
};

$refundCredit = function (array $payload): array {
    if (($_ENV['FAIL_COMPENSATION'] ?? '') === 'refund') {
        throw new \RuntimeException('forced failure on refund_credit (compensation)');
    }
    $delay = (int) ($_ENV['SLOW_COMPENSATION'] ?? 0);
    if ($delay > 0) {
        echo "  ← RefundCredit: sleeping {$delay}s\n";
        sleep($delay);
    }
    $chargeId = $payload['charge']['charge_id'] ?? $payload['charge_id'] ?? '?';
    echo "  ← RefundCredit: charge={$chargeId}\n";
    return [];
};

(new ActivityWorker($sfnConfig, 'service-b'))
    ->register($arns['saga-charge-credit'], $chargeCredit)
    ->register($arns['saga-refund-credit'], $refundCredit)
    ->run();
