<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;

$address = $_ENV['TEMPORAL_ADDRESS'] ?? 'temporal:7233';
$logFile = $_ENV['ALERT_LOG'] ?? '/app/storage/alerts.log';
$intervalSeconds = (int) ($_ENV['ALERT_POLL_INTERVAL'] ?? 2);

$client = WorkflowClient::create(ServiceClient::create($address));

echo "[alerter] polling Temporal at {$address} every {$intervalSeconds}s; alerts → {$logFile}\n";

$alerted = [];
$query = "WorkflowType='ActivateStoreSaga' AND ExecutionStatus='Failed'";

while (true) {
    try {
        $executions = $client->listWorkflowExecutions($query, pageSize: 50);
        foreach ($executions as $info) {
            $id = $info->execution->getID();
            if (isset($alerted[$id])) {
                continue;
            }
            $alerted[$id] = true;
            $line = sprintf(
                "%s ALERT workflow_failed workflow_id=%s status=Failed close_time=%s\n",
                date('c'),
                $id,
                $info->closeTime?->format(DATE_ATOM) ?? '?',
            );
            file_put_contents($logFile, $line, FILE_APPEND);
            fwrite(STDERR, $line);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[alerter] poll error: {$e->getMessage()}\n");
    }
    sleep($intervalSeconds);
}
