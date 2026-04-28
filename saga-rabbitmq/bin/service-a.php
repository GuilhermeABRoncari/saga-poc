<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Handlers\ServiceA\ConfirmShippingHandler;
use App\Handlers\ServiceA\ReleaseStockHandler;
use App\Handlers\ServiceA\ReserveStockHandler;
use Mobilestock\Saga\AmqpTransport;
use Mobilestock\Saga\ServiceWorker;

function bootServiceA(bool $compensations): void
{
    $transport = new AmqpTransport(
        host: $_ENV['AMQP_HOST'] ?? 'localhost',
        port: (int) ($_ENV['AMQP_PORT'] ?? 5672),
        user: $_ENV['AMQP_USER'] ?? 'guest',
        pass: $_ENV['AMQP_PASS'] ?? 'guest',
    );

    $worker = (new ServiceWorker('service-a', $transport))
        ->on('reserve_stock', new ReserveStockHandler())
        ->on('release_stock', new ReleaseStockHandler())
        ->on('confirm_shipping', new ConfirmShippingHandler());

    if ($compensations) {
        $worker->runCompensations('saga.events');
    } else {
        $worker->run('saga.events');
    }
}

$pid = pcntl_fork();
if ($pid === 0) {
    bootServiceA(compensations: true);
} else {
    bootServiceA(compensations: false);
}
