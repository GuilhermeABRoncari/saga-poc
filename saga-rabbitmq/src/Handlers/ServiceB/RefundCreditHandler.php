<?php

declare(strict_types=1);

namespace App\Handlers\ServiceB;

final class RefundCreditHandler
{
    public function __invoke(array $payload): array
    {
        if (($_ENV['FAIL_COMPENSATION'] ?? '') === 'refund') {
            throw new \RuntimeException('forced failure on refund_credit (compensation)');
        }
        $delay = (int) ($_ENV['SLOW_COMPENSATION'] ?? 0);
        if ($delay > 0) {
            echo "  ← RefundCredit: sleeping {$delay}s\n";
            sleep($delay);
        }
        echo "  ← RefundCredit: charge={$payload['charge_id']}\n";
        return [];
    }
}
