<?php

declare(strict_types=1);

namespace App\Sagas\ServiceB;

use App\Handlers\ServiceB\ChargeCreditHandler;
use App\Handlers\ServiceB\RefundCreditCompensation;
use Mobilestock\SagaCoreografada\SagaDefinition;
use Mobilestock\SagaCoreografada\SagaListener;

final class CreateOrderSaga extends SagaDefinition
{
    public function __construct(
        private readonly ChargeCreditHandler $chargeCredit,
        private readonly RefundCreditCompensation $refundCredit,
    ) {}

    public function register(SagaListener $listener): void
    {
        $listener
            ->react(
                event: 'stock.reserved',
                stepName: 'charge_credit',
                emit: 'credit.charged',
                handler: $this->chargeCredit,
            )
            ->compensate('charge_credit', $this->refundCredit);
    }
}
