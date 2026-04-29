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
        echo "  ← RefundCredit: charge={$payload['charge_id']}\n";
        return [];
    }
}
