<?php

declare(strict_types=1);

namespace App\Sagas;

use App\Activities\ServiceA\ServiceAActivitiesInterface;
use App\Activities\ServiceB\ServiceBActivitiesInterface;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Temporal\Workflow\Saga;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
final class ActivateStoreSaga
{
    private ServiceAActivitiesInterface|ActivityProxy $serviceA;
    private ServiceBActivitiesInterface|ActivityProxy $serviceB;

    public function __construct()
    {
        $retry = RetryOptions::new()
            ->withMaximumAttempts(3)
            ->withInitialInterval(1);

        $this->serviceA = Workflow::newActivityStub(
            ServiceAActivitiesInterface::class,
            ActivityOptions::new()
                ->withTaskQueue('service-a')
                ->withStartToCloseTimeout(20)
                ->withRetryOptions($retry),
        );

        $this->serviceB = Workflow::newActivityStub(
            ServiceBActivitiesInterface::class,
            ActivityOptions::new()
                ->withTaskQueue('service-b')
                ->withStartToCloseTimeout(20)
                ->withRetryOptions($retry),
        );
    }

    #[WorkflowMethod]
    public function execute(array $payload)
    {
        $saga = new Saga();
        $saga->setParallelCompensation(false);

        try {
            $reserve = yield $this->serviceA->reserveStock($payload);
            $saga->addCompensation(
                fn() => yield $this->serviceA->releaseStock(array_merge($payload, $reserve)),
            );

            $charge = yield $this->serviceB->chargeCredit(array_merge($payload, $reserve));
            $saga->addCompensation(
                fn() => yield $this->serviceB->refundCredit(array_merge($payload, $reserve, $charge)),
            );

            $shipping = yield $this->serviceA->confirmShipping(array_merge($payload, $reserve, $charge));

            return [
                'status' => 'COMPLETED',
                'data' => array_merge($reserve, $charge, $shipping),
            ];
        } catch (\Throwable $e) {
            yield $saga->compensate();
            return [
                'status' => 'COMPENSATED',
                'error' => $e->getMessage(),
            ];
        }
    }
}
