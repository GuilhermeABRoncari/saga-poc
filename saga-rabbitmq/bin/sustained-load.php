<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Sagas\ActivateStoreSaga;
use Mobilestock\Saga\AmqpTransport;
use Mobilestock\Saga\SagaOrchestrator;
use Mobilestock\Saga\SagaStateRepository;

$durationSec = (int) ($argv[1] ?? 300);
$ratePerSec = (int) ($argv[2] ?? 10);

$transport = new AmqpTransport(
    host: $_ENV['AMQP_HOST'] ?? 'localhost',
    port: (int) ($_ENV['AMQP_PORT'] ?? 5672),
    user: $_ENV['AMQP_USER'] ?? 'guest',
    pass: $_ENV['AMQP_PASS'] ?? 'guest',
);
$repo = new SagaStateRepository($_ENV['SAGA_DB'] ?? __DIR__ . '/../storage/saga.sqlite');
$orchestrator = new SagaOrchestrator(new ActivateStoreSaga(), $transport, $repo);

$intervalUs = (int) (1_000_000 / $ratePerSec);
$end = microtime(true) + $durationSec;
$count = 0;

while (microtime(true) < $end) {
    $orchestrator->start([
        'product_id' => 'p_' . random_int(100, 999),
        'quantity' => 2,
        'user_id' => 'u_' . random_int(100, 999),
        'amount' => 199.90,
    ]);
    $count++;
    usleep($intervalUs);
}
echo "fired={$count} sagas over {$durationSec}s\n";
$transport->close();
