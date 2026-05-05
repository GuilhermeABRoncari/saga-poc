<?php

declare(strict_types=1);

namespace App\Sagas\ServiceA;

use App\Handlers\ServiceA\ConfirmShippingHandler;
use App\Handlers\ServiceA\ReleaseStockCompensation;
use App\Handlers\ServiceA\ReserveStockHandler;
use Mobilestock\SagaCoreografada\SagaDefinition;
use Mobilestock\SagaCoreografada\SagaListener;

final class CreateOrderSaga extends SagaDefinition
{
    public function __construct(
        private readonly ReserveStockHandler $reserveStock,
        private readonly ConfirmShippingHandler $confirmShipping,
        private readonly ReleaseStockCompensation $releaseStock,
    ) {}

    public function register(SagaListener $listener): void
    {
        $listener
            ->react(
                event: 'saga.started.create_order',
                stepName: 'reserve_stock',
                emit: 'stock.reserved',
                handler: $this->reserveStock,
            )
            ->react(
                event: 'credit.charged',
                stepName: 'confirm_shipping',
                emit: 'saga.completed.create_order',
                handler: $this->confirmShipping,
            )
            ->compensate('reserve_stock', $this->releaseStock);
    }
}
