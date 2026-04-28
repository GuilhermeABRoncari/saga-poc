<?php

declare(strict_types=1);

namespace App\Handlers\ServiceA;

final class ReleaseStockHandler
{
    public function __invoke(array $payload): array
    {
        echo "  ← ReleaseStock: reservation={$payload['reservation_id']}\n";
        return [];
    }
}
