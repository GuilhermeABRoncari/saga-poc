<?php

declare(strict_types=1);

namespace App\Handlers\ServiceA;

final class ConfirmShippingHandler
{
    public function __invoke(string $sagaId, array $payload): array
    {
        if (($_ENV['FORCE_FAIL'] ?? '') === 'step3') {
            throw new \RuntimeException('forced failure on confirm_shipping');
        }
        $tracking = 'BR' . random_int(100000, 999999);
        echo "  → ConfirmShipping: reservation={$payload['reservation_id']} → {$tracking}\n";
        return [
            'tracking_code' => $tracking,
            'reservation_id' => $payload['reservation_id'],
            'charge_id' => $payload['charge_id'] ?? null,
        ];
    }
}
