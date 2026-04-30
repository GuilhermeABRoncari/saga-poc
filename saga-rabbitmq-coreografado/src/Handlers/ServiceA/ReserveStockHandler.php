<?php

declare(strict_types=1);

namespace App\Handlers\ServiceA;

final class ReserveStockHandler
{
    public function __invoke(string $sagaId, array $payload): array
    {
        if (($_ENV['FORCE_FAIL'] ?? '') === 'step1') {
            throw new \RuntimeException('forced failure on reserve_stock');
        }
        $delay = (int) ($_ENV['SLOW_RESERVE_STOCK'] ?? 0);
        if ($delay > 0) {
            sleep($delay);
        }
        $reservationId = 'res_' . bin2hex(random_bytes(4));
        echo "  → ReserveStock: produto={$payload['product_id']} qty={$payload['quantity']} → {$reservationId}\n";
        return [
            'reservation_id' => $reservationId,
            'product_id' => $payload['product_id'],
            'quantity' => $payload['quantity'],
            'user_id' => $payload['user_id'],
            'amount' => $payload['amount'],
        ];
    }
}
