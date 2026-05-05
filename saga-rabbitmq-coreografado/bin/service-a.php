<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Handlers\ServiceA\ConfirmShippingHandler;
use App\Handlers\ServiceA\ReleaseStockCompensation;
use App\Handlers\ServiceA\ReserveStockHandler;
use App\Sagas\ServiceA\CreateOrderSaga;
use Mobilestock\SagaCoreografada\EventBus;
use Mobilestock\SagaCoreografada\SagaListener;
use Mobilestock\SagaCoreografada\SagaLog;
use Mobilestock\SagaCoreografada\SagaRegistry;

$bus = new EventBus(
    host: $_ENV['AMQP_HOST'] ?? 'localhost',
    port: (int) ($_ENV['AMQP_PORT'] ?? 5672),
    user: $_ENV['AMQP_USER'] ?? 'guest',
    pass: $_ENV['AMQP_PASS'] ?? 'guest',
);

$log = new SagaLog($_ENV['SAGA_DB'] ?? __DIR__ . '/../storage/service-a.sqlite');

$registry = (new SagaRegistry())->add(
    new CreateOrderSaga(
        new ReserveStockHandler(),
        new ConfirmShippingHandler(),
        new ReleaseStockCompensation(),
    ),
);

$listener = new SagaListener('service-a', $bus, $log);
foreach ($registry->all() as $definition) {
    $definition->register($listener);
}
$listener->listen('service-a.saga');
