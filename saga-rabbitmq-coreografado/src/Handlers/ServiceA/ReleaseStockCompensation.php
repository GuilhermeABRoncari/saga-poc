<?php

declare(strict_types=1);

namespace App\Handlers\ServiceA;

/**
 * Compensação local do service-a para o step ReserveStock.
 *
 * Idempotência é responsabilidade da SagaListener via CompensationLog.
 * Aqui só executa o efeito (sem precisar checar "já foi feito").
 */
final class ReleaseStockCompensation
{
    public function __invoke(string $sagaId, array $failurePayload): void
    {
        $delay = (int) ($_ENV['SLOW_COMPENSATION'] ?? 0);
        if ($delay > 0) {
            sleep($delay);
        }
        echo "  ← ReleaseStock: saga={$sagaId} (failed_step={$failurePayload['failed_step']})\n";
    }
}
