<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Sagas\ActivateStoreSaga;
use Mobilestock\Saga\AmqpTransport;
use Mobilestock\Saga\SagaOrchestrator;
use Mobilestock\Saga\SagaStateRepository;

$transport = new AmqpTransport(
    host: $_ENV['AMQP_HOST'] ?? 'localhost',
    port: (int) ($_ENV['AMQP_PORT'] ?? 5672),
    user: $_ENV['AMQP_USER'] ?? 'guest',
    pass: $_ENV['AMQP_PASS'] ?? 'guest',
);

$repo = new SagaStateRepository($_ENV['SAGA_DB'] ?? __DIR__ . '/../storage/saga.sqlite');
$saga = new ActivateStoreSaga();
$orchestrator = new SagaOrchestrator($saga, $transport, $repo);

$autoStart = $_ENV['AUTO_START'] ?? '1';
if ($autoStart === '1') {
    sleep(2);
    $sagaId = $orchestrator->start([
        'product_id' => 'p_' . random_int(100, 999),
        'quantity' => 2,
        'user_id' => 'u_' . random_int(100, 999),
        'amount' => 199.90,
    ]);
    echo "[orchestrator] started saga={$sagaId}\n";
}

$orchestrator->run('saga.events');
