<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$dbPath = $_ENV['SAGA_DB'] ?? __DIR__ . '/../storage/saga.sqlite';
$logFile = $_ENV['ALERT_LOG'] ?? '/app/storage/alerts.log';
$intervalSeconds = (int) ($_ENV['ALERT_POLL_INTERVAL'] ?? 2);

echo "[alerter] polling {$dbPath} every {$intervalSeconds}s; alerts → {$logFile}\n";

$pdo = new PDO("sqlite:{$dbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$alerted = [];

while (true) {
    try {
        $rows = $pdo->query("SELECT id, status, current_step, updated_at FROM sagas WHERE status = 'FAILED'")
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (isset($alerted[$row['id']])) {
                continue;
            }
            $alerted[$row['id']] = true;
            $line = sprintf(
                "%s ALERT saga_failed saga_id=%s step=%s updated_at=%s\n",
                date('c'),
                $row['id'],
                $row['current_step'],
                $row['updated_at'],
            );
            file_put_contents($logFile, $line, FILE_APPEND);
            fwrite(STDERR, $line);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[alerter] poll error: {$e->getMessage()}\n");
    }
    sleep($intervalSeconds);
}
