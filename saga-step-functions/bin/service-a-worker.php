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

$reserveStock = function (array $payload): array {
    if (($_ENV['FORCE_FAIL'] ?? '') === 'step1') {
        throw new \RuntimeException('forced failure on reserve_stock (step1)');
    }
    $delay = (int) ($_ENV['SLOW_RESERVE_STOCK'] ?? 0);
    if ($delay > 0) {
        echo "  → ReserveStock: sleeping {$delay}s (resilience test)\n";
        sleep($delay);
    }
    $reservationId = 'res_' . bin2hex(random_bytes(4));
    echo "  → ReserveStock: produto={$payload['product_id']} qty={$payload['quantity']} → {$reservationId}\n";
    return ['reservation_id' => $reservationId];
};

$releaseStock = function (array $payload): array {
    $delay = (int) ($_ENV['SLOW_COMPENSATION'] ?? 0);
    if ($delay > 0) {
        echo "  ← ReleaseStock: sleeping {$delay}s\n";
        sleep($delay);
    }
    $reservationId = $payload['reserve']['reservation_id'] ?? $payload['reservation_id'] ?? '?';
    echo "  ← ReleaseStock: reservation={$reservationId}\n";
    return [];
};

$confirmShipping = function (array $payload): array {
    if (($_ENV['FORCE_FAIL'] ?? '') === 'step3') {
        throw new \RuntimeException('forced failure on confirm_shipping');
    }
    $reservationId = $payload['reserve']['reservation_id'] ?? '?';
    $tracking = 'BR' . random_int(100000, 999999);
    echo "  → ConfirmShipping: reservation={$reservationId} → {$tracking}\n";
    return ['tracking_code' => $tracking];
};

(new ActivityWorker($sfnConfig, 'service-a'))
    ->register($arns['saga-reserve-stock'], $reserveStock)
    ->register($arns['saga-release-stock'], $releaseStock)
    ->register($arns['saga-confirm-shipping'], $confirmShipping)
    ->run();
