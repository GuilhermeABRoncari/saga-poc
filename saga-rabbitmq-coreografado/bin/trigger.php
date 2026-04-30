<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mobilestock\SagaCoreografada\EventBus;
use Ramsey\Uuid\Uuid;

$bus = new EventBus(
    host: $_ENV['AMQP_HOST'] ?? 'localhost',
    port: (int) ($_ENV['AMQP_PORT'] ?? 5672),
    user: $_ENV['AMQP_USER'] ?? 'guest',
    pass: $_ENV['AMQP_PASS'] ?? 'guest',
);

$sagaId = Uuid::uuid4()->toString();
$bus->publish('saga.started', $sagaId, [
    'product_id' => 'p_' . random_int(100, 999),
    'quantity' => 2,
    'user_id' => 'u_' . random_int(100, 999),
    'amount' => 199.90,
]);

echo "started saga={$sagaId}\n";
$bus->close();
