<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mobilestock\SagaCoreografada\EventBus;

$count = (int) ($argv[1] ?? 100);

$bus = new EventBus(
    host: $_ENV['AMQP_HOST'] ?? 'localhost',
    port: (int) ($_ENV['AMQP_PORT'] ?? 5672),
    user: $_ENV['AMQP_USER'] ?? 'guest',
    pass: $_ENV['AMQP_PASS'] ?? 'guest',
);

$start = microtime(true);
for ($i = 0; $i < $count; $i++) {
    $bus->startSaga('create_order', [
        'product_id' => 'p_' . random_int(100, 999),
        'quantity' => 2,
        'user_id' => 'u_' . random_int(100, 999),
        'amount' => 199.90,
    ]);
}
$elapsed = microtime(true) - $start;
printf("published %d sagas in %.2fs (%.0f sagas/s)\n", $count, $elapsed, $count / $elapsed);
$bus->close();
