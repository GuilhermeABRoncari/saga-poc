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
        $delay = (int) ($_ENV['SLOW_COMPENSATION'] ?? 0);
        if ($delay > 0) {
            error_log("  ← RefundCredit: sleeping {$delay}s");
            sleep($delay);
        }
        error_log("  ← RefundCredit: charge={$payload['charge_id']}");
        return [];
    }

    public function auditLog(array $payload): array
    {
        $entryId = 'audit_' . bin2hex(random_bytes(4));
        error_log("  → AuditLog: saga_id={$payload['saga_id']} → {$entryId}");
        return ['audit_entry_id' => $entryId];
    }
}
