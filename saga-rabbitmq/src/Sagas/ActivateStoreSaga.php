<?php

declare(strict_types=1);

namespace App\Sagas;

use Mobilestock\Saga\Saga;
use Mobilestock\Saga\Step;

final class ActivateStoreSaga extends Saga
{
    public function definition(): array
    {
        return [
            new Step(
                name: 'reserve_stock',
                target: 'service-a',
                command: 'reserve_stock',
                compensation: 'release_stock',
            ),
            new Step(
                name: 'charge_credit',
                target: 'service-b',
                command: 'charge_credit',
                compensation: 'refund_credit',
            ),
            new Step(
                name: 'confirm_shipping',
                target: 'service-a',
                command: 'confirm_shipping',
                compensation: null,
            ),
        ];
    }
}
