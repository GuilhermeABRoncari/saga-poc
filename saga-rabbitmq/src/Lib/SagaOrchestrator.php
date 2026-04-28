<?php

declare(strict_types=1);

namespace Mobilestock\Saga;

use Ramsey\Uuid\Uuid;

final class SagaOrchestrator
{
    public function __construct(
        private readonly Saga $saga,
        private readonly AmqpTransport $transport,
        private readonly SagaStateRepository $repo,
    ) {}

    public function start(array $payload): string
    {
        $sagaId = Uuid::uuid4()->toString();
        $this->repo->create($sagaId, $this->saga->name(), $payload);
        $this->dispatchStep($sagaId, 0, $payload);
        return $sagaId;
    }

    public function run(string $eventsQueue): void
    {
        $this->transport->consume($eventsQueue, fn(array $event) => $this->onEvent($event));
    }

    private function onEvent(array $event): void
    {
        ['saga_id' => $sagaId, 'type' => $type, 'step' => $stepIndex, 'payload' => $payload] = $event;
        $state = $this->repo->get($sagaId);
        if ($state === null) {
            fwrite(STDERR, "[orchestrator] saga not found: {$sagaId}\n");
            return;
        }
        $steps = $this->saga->definition();

        if ($type === 'step.completed') {
            echo "[orchestrator] saga={$sagaId} step={$stepIndex} completed\n";
            $this->repo->advance($sagaId, $stepIndex + 1, [
                'index' => $stepIndex,
                'name' => $steps[$stepIndex]->name,
                'result' => $payload,
            ]);
            $next = $stepIndex + 1;
            if ($next >= count($steps)) {
                $this->repo->setStatus($sagaId, 'COMPLETED');
                echo "[orchestrator] saga={$sagaId} COMPLETED\n";
                return;
            }
            $updated = $this->repo->get($sagaId);
            $merged = $state['payload'];
            foreach ($updated['completed_steps'] as $completed) {
                $merged = array_merge($merged, $completed['result'] ?? []);
            }
            $this->dispatchStep($sagaId, $next, $merged);
            return;
        }

        if ($type === 'step.failed') {
            echo "[orchestrator] saga={$sagaId} step={$stepIndex} FAILED → compensating\n";
            $this->repo->setStatus($sagaId, 'COMPENSATING');
            $this->compensate($sagaId, $state, $stepIndex - 1);
            $this->repo->setStatus($sagaId, 'COMPENSATED');
            echo "[orchestrator] saga={$sagaId} COMPENSATED\n";
            return;
        }
    }

    private function dispatchStep(string $sagaId, int $stepIndex, array $payload): void
    {
        $step = $this->saga->definition()[$stepIndex];
        $queue = "saga.commands.{$step->target}";
        $this->transport->publish($queue, [
            'saga_id' => $sagaId,
            'step' => $stepIndex,
            'command' => $step->command,
            'payload' => $payload,
        ]);
        echo "[orchestrator] saga={$sagaId} → {$step->target}.{$step->command} (step {$stepIndex})\n";
    }

    private function compensate(string $sagaId, array $state, int $fromStep): void
    {
        $steps = $this->saga->definition();
        $completed = $state['completed_steps'];
        $accumulated = $state['payload'];
        foreach ($completed as $entry) {
            $accumulated = array_merge($accumulated, $entry['result'] ?? []);
        }
        for ($i = $fromStep; $i >= 0; $i--) {
            $step = $steps[$i];
            if ($step->compensation === null) {
                continue;
            }
            $queue = "saga.compensations.{$step->target}";
            $this->transport->publish($queue, [
                'saga_id' => $sagaId,
                'step' => $i,
                'command' => $step->compensation,
                'payload' => $accumulated,
            ]);
            echo "[orchestrator] saga={$sagaId} ← compensate {$step->target}.{$step->compensation} (step {$i})\n";
        }
    }
}
