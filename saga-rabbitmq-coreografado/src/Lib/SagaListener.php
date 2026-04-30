<?php

declare(strict_types=1);

namespace Mobilestock\SagaCoreografada;

/**
 * Wrapper minimalista que cada serviço usa para reagir a eventos de domínio.
 *
 * Responsabilidade única: capturar exception em handler e republicar como
 * evento `saga.failed` (que cada serviço consome para rodar compensações).
 *
 * Sem state machine, sem definição central de saga. Cada serviço só conhece
 * os eventos que assina e os eventos que publica em resposta.
 */
final class SagaListener
{
    /** @var array<string, callable(string $sagaId, array $payload): array> */
    private array $reactions = [];

    /** @var array<string, callable(string $sagaId, array $payload): void> */
    private array $compensations = [];

    public function __construct(
        private readonly string $serviceName,
        private readonly EventBus $bus,
        private readonly CompensationLog $log,
    ) {}

    /**
     * @param string $event evento de entrada (ex: 'saga.started', 'stock.reserved')
     * @param string $stepName nome canônico do step (usado como dedup-key)
     * @param string $emit evento que será publicado em sucesso (ex: 'stock.reserved')
     * @param callable(string $sagaId, array $payload): array $handler retorna payload do evento de sucesso
     */
    public function react(string $event, string $stepName, string $emit, callable $handler): self
    {
        $this->reactions[$event] = function (string $sagaId, array $payload) use ($stepName, $emit, $handler): void {
            try {
                $result = $handler($sagaId, $payload);
                echo "[{$this->serviceName}] saga={$sagaId} step={$stepName} ok → {$emit}\n";
                $this->bus->publish($emit, $sagaId, $result + ['_step' => $stepName]);
            } catch (\Throwable $e) {
                echo "[{$this->serviceName}] saga={$sagaId} step={$stepName} FAILED: {$e->getMessage()}\n";
                $this->bus->publish('saga.failed', $sagaId, [
                    'failed_step' => $stepName,
                    'service' => $this->serviceName,
                    'error' => $e->getMessage(),
                    'original_payload' => $payload,
                ]);
            }
        };
        return $this;
    }

    /**
     * Registra compensação local desse serviço para uma saga falha.
     * O handler só roda se for a primeira vez (dedup via CompensationLog).
     *
     * @param callable(string $sagaId, array $payload): void $handler
     */
    public function compensate(string $stepName, callable $handler): self
    {
        $this->compensations[$stepName] = $handler;
        return $this;
    }

    public function listen(string $queue): void
    {
        $routingKeys = array_merge(array_keys($this->reactions), ['saga.failed']);
        echo "[{$this->serviceName}] listening on {$queue} keys=" . implode(',', $routingKeys) . "\n";
        $this->bus->subscribe($queue, $routingKeys, function (string $event, string $sagaId, array $payload): void {
            if ($event === 'saga.failed') {
                $this->runCompensations($sagaId, $payload);
                return;
            }
            if (isset($this->reactions[$event])) {
                ($this->reactions[$event])($sagaId, $payload);
            }
        });
    }

    private function runCompensations(string $sagaId, array $failurePayload): void
    {
        foreach ($this->compensations as $stepName => $handler) {
            if (!$this->log->tryClaim($sagaId, $stepName, $failurePayload)) {
                echo "[{$this->serviceName}] saga={$sagaId} comp={$stepName} already_applied (dedup)\n";
                continue;
            }
            try {
                $handler($sagaId, $failurePayload);
                echo "[{$this->serviceName}] saga={$sagaId} comp={$stepName} ok\n";
            } catch (\Throwable $e) {
                echo "[{$this->serviceName}] saga={$sagaId} comp={$stepName} FAILED: {$e->getMessage()}\n";
            }
        }
    }
}
