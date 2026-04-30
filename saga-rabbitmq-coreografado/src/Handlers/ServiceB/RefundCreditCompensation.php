<?php

declare(strict_types=1);

namespace App\Handlers\ServiceB;

final class RefundCreditCompensation
{
    public function __invoke(string $sagaId, array $failurePayload): void
    {
        if (($_ENV['FAIL_COMPENSATION'] ?? '') === 'refund') {
            throw new \RuntimeException('forced failure on refund_credit');
        }
        $delay = (int) ($_ENV['SLOW_COMPENSATION'] ?? 0);
        if ($delay > 0) {
            sleep($delay);
        }
        echo "  ← RefundCredit: saga={$sagaId} (failed_step={$failurePayload['failed_step']})\n";
    }
}
