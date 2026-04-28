<?php

declare(strict_types=1);

namespace App\Handlers\ServiceB;

final class ChargeCreditHandler
{
    public function __invoke(array $payload): array
    {
        $chargeId = 'chg_' . bin2hex(random_bytes(4));
        echo "  → ChargeCredit: user={$payload['user_id']} amount={$payload['amount']} → {$chargeId}\n";
        return ['charge_id' => $chargeId];
    }
}
