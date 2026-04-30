<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Handlers\ServiceA\ConfirmShippingHandler;
use App\Handlers\ServiceA\ReleaseStockCompensation;
use App\Handlers\ServiceA\ReserveStockHandler;
use Mobilestock\SagaCoreografada\CompensationLog;
use Mobilestock\SagaCoreografada\EventBus;
use Mobilestock\SagaCoreografada\SagaListener;

$bus = new EventBus(
    host: $_ENV['AMQP_HOST'] ?? 'localhost',
    port: (int) ($_ENV['AMQP_PORT'] ?? 5672),
    user: $_ENV['AMQP_USER'] ?? 'guest',
    pass: $_ENV['AMQP_PASS'] ?? 'guest',
);

$log = new CompensationLog($_ENV['COMPENSATION_DB'] ?? __DIR__ . '/../storage/service-a.sqlite');

(new SagaListener('service-a', $bus, $log))
    ->react('saga.started', 'reserve_stock', 'stock.reserved', new ReserveStockHandler())
    ->react('credit.charged', 'confirm_shipping', 'saga.completed', new ConfirmShippingHandler())
    ->compensate('reserve_stock', new ReleaseStockCompensation())
    ->listen('service-a.saga');
