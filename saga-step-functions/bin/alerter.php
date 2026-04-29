<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Aws\Sfn\SfnClient;

$sfn = new SfnClient([
    'region' => $_ENV['AWS_REGION'] ?? 'us-east-1',
    'version' => 'latest',
    'endpoint' => $_ENV['AWS_ENDPOINT_URL'] ?? 'http://localstack:4566',
    'credentials' => [
        'key' => $_ENV['AWS_ACCESS_KEY_ID'] ?? 'test',
        'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? 'test',
    ],
]);

$config = json_decode(file_get_contents('/app/storage/sfn-config.json'), true, 512, JSON_THROW_ON_ERROR);
$stateMachineArn = $config['state_machine_arn'];

$logFile = $_ENV['ALERT_LOG'] ?? '/app/storage/alerts.log';
$intervalSeconds = (int) ($_ENV['ALERT_POLL_INTERVAL'] ?? 2);

echo "[alerter] polling Step Functions every {$intervalSeconds}s; alerts → {$logFile}\n";

$alerted = [];

while (true) {
    try {
        $result = $sfn->listExecutions([
            'stateMachineArn' => $stateMachineArn,
            'statusFilter' => 'FAILED',
            'maxResults' => 100,
        ]);
        foreach ($result['executions'] ?? [] as $exec) {
            $arn = $exec['executionArn'];
            if (isset($alerted[$arn])) {
                continue;
            }
            $alerted[$arn] = true;
            $line = sprintf(
                "%s ALERT execution_failed name=%s status=FAILED stop_date=%s\n",
                date('c'),
                $exec['name'],
                $exec['stopDate']?->format(DATE_ATOM) ?? '?',
            );
            file_put_contents($logFile, $line, FILE_APPEND);
            fwrite(STDERR, $line);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[alerter] poll error: {$e->getMessage()}\n");
    }
    sleep($intervalSeconds);
}
