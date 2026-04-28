<?php

declare(strict_types=1);

namespace App\Activities\ServiceB;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: "ServiceB.")]
interface ServiceBActivitiesInterface
{
    #[ActivityMethod]
    public function chargeCredit(array $payload): array;

    #[ActivityMethod]
    public function refundCredit(array $payload): array;
}
