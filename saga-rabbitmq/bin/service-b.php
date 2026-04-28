<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Handlers\ServiceB\ChargeCreditHandler;
use App\Handlers\ServiceB\RefundCreditHandler;
use Mobilestock\Saga\AmqpTransport;
use Mobilestock\Saga\ServiceWorker;

function bootServiceB(bool $compensations): void
{
    $transport = new AmqpTransport(
        host: $_ENV['AMQP_HOST'] ?? 'localhost',
        port: (int) ($_ENV['AMQP_PORT'] ?? 5672),
        user: $_ENV['AMQP_USER'] ?? 'guest',
        pass: $_ENV['AMQP_PASS'] ?? 'guest',
    );

    $worker = (new ServiceWorker('service-b', $transport))
        ->on('charge_credit', new ChargeCreditHandler())
        ->on('refund_credit', new RefundCreditHandler());

    if ($compensations) {
        $worker->runCompensations('saga.events');
    } else {
        $worker->run('saga.events');
    }
}

$pid = pcntl_fork();
if ($pid === 0) {
    bootServiceB(compensations: true);
} else {
    bootServiceB(compensations: false);
}
