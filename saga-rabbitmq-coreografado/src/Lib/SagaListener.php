<?php

declare(strict_types=1);

namespace Mobilestock\SagaCoreografada;

/**
 * Wrapper minimalista que cada serviço usa para reagir a eventos de domínio.
 *
 * Responsabilidades:
 * 1. Capturar exception em handler de step e republicar como evento `saga.failed`.
 * 2. Marcar step como executado no `step_log` local após sucesso (para
 *    compensação saber se este serviço de fato fez algo a desfazer).
 * 3. Em compensação, rodar handlers locais com retry: marca status='in_progress'
 *    antes do handler, 'done' após sucesso. Se handler falha, lança exception
 *    para o EventBus fazer requeue. Re-tentativas re-rodam até sucesso (dedup
 *    via 'done') ou alerta humano (max attempts em produção).
 *
 * Sem state machine, sem definição central de saga.
 */
final class SagaListener
{
    /** @var array<string, callable(string $sagaId, array $payload): void> */
    private array $reactions = [];

    /** @var array<string, callable(string $sagaId, array $payload): void> */
    private array $compensations = [];

    public function __construct(
        private readonly string $serviceName,
        private readonly EventBus $bus,
        private readonly SagaLog $log,
    ) {}

    /**
     * @param string $event evento de entrada (ex: 'saga.started', 'stock.reserved')
     * @param string $stepName nome canônico do step (chave no step_log e compensation_log)
     * @param string $emit evento publicado em sucesso (ex: 'stock.reserved')
     * @param callable(string $sagaId, array $payload): array $handler retorna payload do evento de sucesso
     */
    public function react(string $event, string $stepName, string $emit, callable $handler): self
    {
        $this->reactions[$event] = function (string $sagaId, array $payload) use ($stepName, $emit, $handler): void {
            try {
                $result = $handler($sagaId, $payload);
                $this->log->markStepDone($sagaId, $stepName);
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
     * Registra compensação local desse serviço para um step.
     * Só roda se: (1) step foi executado com sucesso, (2) ainda não foi compensada com sucesso.
     * Em caso de falha do handler, propaga exception → EventBus faz requeue → retry.
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
        $errors = [];
        foreach ($this->compensations as $stepName => $handler) {
            if (!$this->log->wasStepDone($sagaId, $stepName)) {
                echo "[{$this->serviceName}] saga={$sagaId} comp={$stepName} skipped (step never executed)\n";
                continue;
            }

            $state = $this->log->startCompensation($sagaId, $stepName, $failurePayload);
            if ($state === 'done') {
                echo "[{$this->serviceName}] saga={$sagaId} comp={$stepName} already_done (dedup)\n";
                continue;
            }

            $attempts = $this->log->compensationAttempts($sagaId, $stepName);
            try {
                $handler($sagaId, $failurePayload);
                $this->log->markCompensationDone($sagaId, $stepName);
                echo "[{$this->serviceName}] saga={$sagaId} comp={$stepName} ok (attempt={$attempts})\n";
            } catch (\Throwable $e) {
                echo "[{$this->serviceName}] saga={$sagaId} comp={$stepName} FAILED attempt={$attempts}: {$e->getMessage()}\n";
                $errors[] = "{$stepName}: {$e->getMessage()}";
            }
        }

        if ($errors !== []) {
            throw new \RuntimeException('compensation errors: ' . implode('; ', $errors));
        }
    }
}
