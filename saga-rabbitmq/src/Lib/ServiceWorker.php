<?php

declare(strict_types=1);

namespace Mobilestock\Saga;

final class ServiceWorker
{
    /** @var array<string, callable(array): array> */
    private array $handlers = [];

    public function __construct(
        private readonly string $serviceName,
        private readonly AmqpTransport $transport,
    ) {}

    public function on(string $command, callable $handler): self
    {
        $this->handlers[$command] = $handler;
        return $this;
    }

    public function run(string $eventsQueue): void
    {
        $commandsQueue = "saga.commands.{$this->serviceName}";
        $compensationsQueue = "saga.compensations.{$this->serviceName}";

        $this->transport->declareQueue($eventsQueue);
        $this->transport->declareQueue($compensationsQueue);

        echo "[{$this->serviceName}] consuming {$commandsQueue}\n";
        $this->transport->consume($commandsQueue, function (array $cmd) use ($eventsQueue): void {
            $this->dispatch($cmd, $eventsQueue, isCompensation: false);
        });
    }

    public function runCompensations(string $eventsQueue): void
    {
        $compensationsQueue = "saga.compensations.{$this->serviceName}";
        echo "[{$this->serviceName}] consuming {$compensationsQueue}\n";
        $this->transport->consume($compensationsQueue, function (array $cmd) use ($eventsQueue): void {
            $this->dispatch($cmd, $eventsQueue, isCompensation: true);
        });
    }

    private function dispatch(array $cmd, string $eventsQueue, bool $isCompensation): void
    {
        ['saga_id' => $sagaId, 'step' => $step, 'command' => $command, 'payload' => $payload] = $cmd;
        $tag = $isCompensation ? 'compensation' : 'command';
        echo "[{$this->serviceName}] saga={$sagaId} {$tag}={$command} step={$step}\n";

        if (!isset($this->handlers[$command])) {
            $this->emit($eventsQueue, $sagaId, $step, 'step.failed', ['error' => "no handler for {$command}"]);
            return;
        }

        try {
            $result = ($this->handlers[$command])($payload);
            if ($isCompensation) {
                echo "[{$this->serviceName}] saga={$sagaId} compensation done step={$step}\n";
                return;
            }
            $this->emit($eventsQueue, $sagaId, $step, 'step.completed', $result);
        } catch (\Throwable $e) {
            echo "[{$this->serviceName}] saga={$sagaId} step={$step} FAILED: {$e->getMessage()}\n";
            $eventType = $isCompensation ? 'compensation.failed' : 'step.failed';
            $this->emit($eventsQueue, $sagaId, $step, $eventType, ['error' => $e->getMessage()]);
        }
    }

    private function emit(string $eventsQueue, string $sagaId, int $step, string $type, array $payload): void
    {
        $this->transport->publish($eventsQueue, [
            'saga_id' => $sagaId,
            'step' => $step,
            'type' => $type,
            'payload' => $payload,
        ]);
    }
}
