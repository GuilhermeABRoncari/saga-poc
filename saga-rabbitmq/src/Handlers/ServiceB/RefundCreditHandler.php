<?php

declare(strict_types=1);

namespace App\Handlers\ServiceB;

final class RefundCreditHandler
{
    public function __invoke(array $payload): array
    {
        echo "  ← RefundCredit: charge={$payload['charge_id']}\n";
        return [];
    }
}
