<?php

declare(strict_types=1);

namespace App\Activities\ServiceB;

final class ServiceBActivities implements ServiceBActivitiesInterface
{
    public function chargeCredit(array $payload): array
    {
        $chargeId = 'chg_' . bin2hex(random_bytes(4));
        error_log("  → ChargeCredit: user={$payload['user_id']} amount={$payload['amount']} → {$chargeId}");
        return ['charge_id' => $chargeId];
    }

    public function refundCredit(array $payload): array
    {
        if (($_ENV['FAIL_COMPENSATION'] ?? '') === 'refund') {
            throw new \RuntimeException('forced failure on refund_credit (compensation)');
        }
        error_log("  ← RefundCredit: charge={$payload['charge_id']}");
        return [];
    }
}
