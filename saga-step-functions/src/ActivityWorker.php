<?php

declare(strict_types=1);

namespace App;

use Aws\Sfn\SfnClient;

final class ActivityWorker
{
    /** @var array<string, callable(array): array> */
    private array $handlers = [];

    public function __construct(
        private readonly array $sfnConfig,
        private readonly string $workerName,
    ) {}

    public function register(string $activityArn, callable $handler): self
    {
        $this->handlers[$activityArn] = $handler;
        return $this;
    }

    public function run(): void
    {
        $arns = array_keys($this->handlers);
        echo "[{$this->workerName}] forking " . count($arns) . " activity pollers\n";

        foreach ($arns as $arn) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('pcntl_fork failed');
            }
            if ($pid === 0) {
                $this->pollActivity($arn);
                exit(0);
            }
        }
        while (pcntl_waitpid(-1, $status) > 0) {
        }
    }

    private function pollActivity(string $arn): void
    {
        $shortName = basename($arn);
        $sfn = new SfnClient($this->sfnConfig);
        echo "[{$this->workerName}/{$shortName}] polling\n";

        while (true) {
            try {
                $result = $sfn->getActivityTask([
                    'activityArn' => $arn,
                    'workerName' => "{$this->workerName}-{$shortName}",
                ]);
            } catch (\Throwable $e) {
                fwrite(STDERR, "[{$this->workerName}/{$shortName}] poll error: {$e->getMessage()}\n");
                sleep(2);
                continue;
            }

            $token = $result['taskToken'] ?? '';
            if ($token === '') {
                continue;
            }

            $input = json_decode($result['input'] ?? '{}', true);
            $execName = $input['_meta']['execution'] ?? '?';
            echo "[{$this->workerName}/{$shortName}] execution={$execName}\n";

            try {
                $output = ($this->handlers[$arn])($input);
                $sfn->sendTaskSuccess([
                    'taskToken' => $token,
                    'output' => json_encode($output, JSON_THROW_ON_ERROR),
                ]);
            } catch (\Throwable $e) {
                echo "[{$this->workerName}/{$shortName}] FAILED: {$e->getMessage()}\n";
                $sfn->sendTaskFailure([
                    'taskToken' => $token,
                    'error' => 'TaskFailed',
                    'cause' => $e->getMessage(),
                ]);
            }
        }
    }
}
