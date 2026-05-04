<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mobilestock\SagaCoreografada\EventBus;
use Ramsey\Uuid\Uuid;

// Bench de latência sequencial — dispara N sagas uma a uma, espera cada saga
// chegar a confirm_shipping (último step de service-a) antes de disparar a próxima.
// Modelo coreografado não tem tabela central de sagas, então pollamos o step_log
// de service-a que recebe confirm_shipping = saga.completed.

$count = (int) ($argv[1] ?? 1000);

$bus = new EventBus(
    host: $_ENV['AMQP_HOST'] ?? 'localhost',
    port: (int) ($_ENV['AMQP_PORT'] ?? 5672),
    user: $_ENV['AMQP_USER'] ?? 'guest',
    pass: $_ENV['AMQP_PASS'] ?? 'guest',
);

$pdo = new PDO('sqlite:' . ($_ENV['SAGA_DB'] ?? __DIR__ . '/../storage/service-a.sqlite'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 5000');
$stmt = $pdo->prepare("SELECT 1 FROM step_log WHERE saga_id = ? AND step = 'confirm_shipping'");

$latencies = [];
for ($i = 0; $i < $count; $i++) {
    $sagaId = Uuid::uuid4()->toString();
    $start = microtime(true);
    $bus->publish('saga.started', $sagaId, [
        'product_id' => 'p_' . random_int(100, 999),
        'quantity' => 2,
        'user_id' => 'u_' . random_int(100, 999),
        'amount' => 199.90,
    ]);
    $deadline = $start + 30;
    while (microtime(true) < $deadline) {
        $stmt->execute([$sagaId]);
        if ($stmt->fetchColumn()) {
            $latencies[] = (microtime(true) - $start) * 1000;
            break;
        }
        usleep(10000);
    }
}
$bus->close();

sort($latencies);
$n = count($latencies);
$p = fn(float $q) => $latencies[(int) min($n - 1, floor($q * $n))];
echo "n={$n}\n";
echo sprintf("p50=%.1fms p95=%.1fms p99=%.1fms max=%.1fms\n", $p(0.50), $p(0.95), $p(0.99), end($latencies));
echo sprintf("avg=%.1fms\n", array_sum($latencies) / max(1, $n));
