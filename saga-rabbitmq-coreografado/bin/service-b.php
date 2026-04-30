<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Handlers\ServiceB\ChargeCreditHandler;
use App\Handlers\ServiceB\RefundCreditCompensation;
use Mobilestock\SagaCoreografada\EventBus;
use Mobilestock\SagaCoreografada\SagaListener;
use Mobilestock\SagaCoreografada\SagaLog;

$bus = new EventBus(
    host: $_ENV['AMQP_HOST'] ?? 'localhost',
    port: (int) ($_ENV['AMQP_PORT'] ?? 5672),
    user: $_ENV['AMQP_USER'] ?? 'guest',
    pass: $_ENV['AMQP_PASS'] ?? 'guest',
);

$log = new SagaLog($_ENV['SAGA_DB'] ?? __DIR__ . '/../storage/service-b.sqlite');

(new SagaListener('service-b', $bus, $log))
    ->react('stock.reserved', 'charge_credit', 'credit.charged', new ChargeCreditHandler())
    ->compensate('charge_credit', new RefundCreditCompensation())
    ->listen('service-b.saga');
