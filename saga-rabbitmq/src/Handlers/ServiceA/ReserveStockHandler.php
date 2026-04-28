<?php

declare(strict_types=1);

namespace App\Handlers\ServiceA;

final class ReserveStockHandler
{
    public function __invoke(array $payload): array
    {
        $reservationId = 'res_' . bin2hex(random_bytes(4));
        echo "  → ReserveStock: produto={$payload['product_id']} qty={$payload['quantity']} → {$reservationId}\n";
        return ['reservation_id' => $reservationId];
    }
}
