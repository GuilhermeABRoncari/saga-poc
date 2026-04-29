<?php

declare(strict_types=1);

namespace App\Handlers\ServiceA;

final class ReserveStockHandler
{
    public function __invoke(array $payload): array
    {
        if (($_ENV['FORCE_FAIL'] ?? '') === 'step1') {
            throw new \RuntimeException('forced failure on reserve_stock (step1)');
        }
        $delay = (int) ($_ENV['SLOW_RESERVE_STOCK'] ?? 0);
        if ($delay > 0) {
            echo "  → ReserveStock: sleeping {$delay}s (resilience test)\n";
            sleep($delay);
        }
        $reservationId = 'res_' . bin2hex(random_bytes(4));
        echo "  → ReserveStock: produto={$payload['product_id']} qty={$payload['quantity']} → {$reservationId}\n";
        return ['reservation_id' => $reservationId];
    }
}
