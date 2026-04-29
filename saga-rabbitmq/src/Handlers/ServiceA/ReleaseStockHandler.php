<?php

declare(strict_types=1);

namespace App\Handlers\ServiceA;

final class ReleaseStockHandler
{
    public function __invoke(array $payload): array
    {
        $delay = (int) ($_ENV['SLOW_COMPENSATION'] ?? 0);
        if ($delay > 0) {
            echo "  ← ReleaseStock: sleeping {$delay}s\n";
            sleep($delay);
        }
        echo "  ← ReleaseStock: reservation={$payload['reservation_id']}\n";
        return [];
    }
}
