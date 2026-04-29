<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Sagas\ActivateStoreSaga;
use Mobilestock\Saga\AmqpTransport;
use Mobilestock\Saga\SagaOrchestrator;
use Mobilestock\Saga\SagaStateRepository;

$count = (int) ($argv[1] ?? 1000);

$transport = new AmqpTransport(
    host: $_ENV['AMQP_HOST'] ?? 'localhost',
    port: (int) ($_ENV['AMQP_PORT'] ?? 5672),
    user: $_ENV['AMQP_USER'] ?? 'guest',
    pass: $_ENV['AMQP_PASS'] ?? 'guest',
);
$repo = new SagaStateRepository($_ENV['SAGA_DB'] ?? __DIR__ . '/../storage/saga.sqlite');
$orchestrator = new SagaOrchestrator(new ActivateStoreSaga(), $transport, $repo);

$pdo = new PDO('sqlite:' . ($_ENV['SAGA_DB'] ?? __DIR__ . '/../storage/saga.sqlite'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 5000');
$stmt = $pdo->prepare("SELECT status FROM sagas WHERE id = ?");

$latencies = [];
for ($i = 0; $i < $count; $i++) {
    $start = microtime(true);
    $sagaId = $orchestrator->start([
        'product_id' => 'p_' . random_int(100, 999),
        'quantity' => 2,
        'user_id' => 'u_' . random_int(100, 999),
        'amount' => 199.90,
    ]);
    $deadline = $start + 30;
    while (microtime(true) < $deadline) {
        $stmt->execute([$sagaId]);
        $status = $stmt->fetchColumn();
        if (in_array($status, ['COMPLETED', 'COMPENSATED', 'FAILED'], true)) {
            $latencies[] = (microtime(true) - $start) * 1000;
            break;
        }
        usleep(20000);
    }
}
$transport->close();

sort($latencies);
$n = count($latencies);
$p = fn(float $q) => $latencies[(int) min($n - 1, floor($q * $n))];
echo "n={$n}\n";
echo sprintf("p50=%.1fms p95=%.1fms p99=%.1fms max=%.1fms\n", $p(0.50), $p(0.95), $p(0.99), end($latencies));
echo sprintf("avg=%.1fms\n", array_sum($latencies) / max(1, $n));
