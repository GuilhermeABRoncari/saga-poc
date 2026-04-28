<?php

declare(strict_types=1);

namespace App\Activities\ServiceA;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: "ServiceA.")]
interface ServiceAActivitiesInterface
{
    #[ActivityMethod]
    public function reserveStock(array $payload): array;

    #[ActivityMethod]
    public function releaseStock(array $payload): array;

    #[ActivityMethod]
    public function confirmShipping(array $payload): array;
}
