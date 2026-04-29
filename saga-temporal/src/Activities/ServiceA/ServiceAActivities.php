<?php

declare(strict_types=1);

namespace App\Activities\ServiceA;

final class ServiceAActivities implements ServiceAActivitiesInterface
{
    public function reserveStock(array $payload): array
    {
        if (($_ENV['FORCE_FAIL'] ?? '') === 'step1') {
            throw new \RuntimeException('forced failure on reserve_stock (step1)');
        }
        $delay = (int) ($_ENV['SLOW_RESERVE_STOCK'] ?? 0);
        if ($delay > 0) {
            error_log("  → ReserveStock: sleeping {$delay}s (resilience test)");
            sleep($delay);
        }
        $reservationId = 'res_' . bin2hex(random_bytes(4));
        error_log("  → ReserveStock: produto={$payload['product_id']} qty={$payload['quantity']} → {$reservationId}");
        return ['reservation_id' => $reservationId];
    }

    public function releaseStock(array $payload): array
    {
        $delay = (int) ($_ENV['SLOW_COMPENSATION'] ?? 0);
        if ($delay > 0) {
            error_log("  ← ReleaseStock: sleeping {$delay}s");
            sleep($delay);
        }
        error_log("  ← ReleaseStock: reservation={$payload['reservation_id']}");
        return [];
    }

    public function confirmShipping(array $payload): array
    {
        if (($_ENV['FORCE_FAIL'] ?? '') === 'step3') {
            throw new \RuntimeException('forced failure on confirm_shipping');
        }
        $tracking = 'BR' . random_int(100000, 999999);
        error_log("  → ConfirmShipping: reservation={$payload['reservation_id']} → {$tracking}");
        return ['tracking_code' => $tracking];
    }
}
