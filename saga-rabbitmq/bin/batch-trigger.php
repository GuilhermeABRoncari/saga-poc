<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Sagas\ActivateStoreSaga;
use Mobilestock\Saga\AmqpTransport;
use Mobilestock\Saga\SagaOrchestrator;
use Mobilestock\Saga\SagaStateRepository;

$count = (int) ($argv[1] ?? 100);

$transport = new AmqpTransport(
    host: $_ENV['AMQP_HOST'] ?? 'localhost',
    port: (int) ($_ENV['AMQP_PORT'] ?? 5672),
    user: $_ENV['AMQP_USER'] ?? 'guest',
    pass: $_ENV['AMQP_PASS'] ?? 'guest',
);

$repo = new SagaStateRepository($_ENV['SAGA_DB'] ?? __DIR__ . '/../storage/saga.sqlite');
$orchestrator = new SagaOrchestrator(new ActivateStoreSaga(), $transport, $repo);

$ids = [];
$tStart = microtime(true);
for ($i = 0; $i < $count; $i++) {
    $ids[] = $orchestrator->start([
        'product_id' => 'p_' . random_int(100, 999),
        'quantity' => 2,
        'user_id' => 'u_' . random_int(100, 999),
        'amount' => 199.90,
    ]);
}
$tFired = microtime(true);
echo "fired={$count} sagas in " . number_format(($tFired - $tStart) * 1000, 0) . "ms\n";
echo "first_id={$ids[0]}\nlast_id={$ids[count($ids) - 1]}\n";
$transport->close();

$pdo = new PDO('sqlite:' . ($_ENV['SAGA_DB'] ?? __DIR__ . '/../storage/saga.sqlite'));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT COUNT(*) FROM sagas WHERE id IN ($placeholders) AND status IN ('COMPLETED','COMPENSATED','FAILED')";
$stmt = $pdo->prepare($sql);

$deadline = microtime(true) + 120;
$done = 0;
while (microtime(true) < $deadline) {
    $stmt->execute($ids);
    $done = (int) $stmt->fetchColumn();
    if ($done >= $count) {
        $tDone = microtime(true);
        echo "all_done={$done} elapsed_total_ms=" . number_format(($tDone - $tStart) * 1000, 0) . "\n";
        echo "elapsed_after_fire_ms=" . number_format(($tDone - $tFired) * 1000, 0) . "\n";
        $stmt = $pdo->prepare("SELECT status, COUNT(*) FROM sagas WHERE id IN ($placeholders) GROUP BY status");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            echo "  {$row[0]}={$row[1]}\n";
        }
        exit(0);
    }
    usleep(200000);
}
echo "TIMEOUT after 120s, done={$done}/{$count}\n";
exit(1);
