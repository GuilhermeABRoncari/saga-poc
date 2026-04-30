<?php

declare(strict_types=1);

namespace App\Handlers\ServiceB;

final class ChargeCreditHandler
{
    public function __invoke(string $sagaId, array $payload): array
    {
        if (($_ENV['FORCE_FAIL'] ?? '') === 'step2') {
            throw new \RuntimeException('forced failure on charge_credit');
        }
        $chargeId = 'chg_' . bin2hex(random_bytes(4));
        echo "  → ChargeCredit: user={$payload['user_id']} amount={$payload['amount']} → {$chargeId}\n";
        return [
            'charge_id' => $chargeId,
            'reservation_id' => $payload['reservation_id'],
            'user_id' => $payload['user_id'],
            'amount' => $payload['amount'],
        ];
    }
}
