# Integração Laravel ↔ Temporal — passo a passo

Como adotar Temporal como padrão organizacional partindo de uma stack Laravel + Docker Swarm. O exemplo concreto usado ao longo deste guia é um fluxo de criação de pedido com reserva de estoque + cobrança + confirmação, implementado como `ActivateStoreSaga`.

Premissas em vigor:

- Apenas o serviço Laravel principal (`order-service`) migra para Kubernetes inicialmente; demais serviços ficam em Swarm por tempo indeterminado, configurando uma stack híbrida Docker Swarm + Kubernetes.
- Workers Temporal rodam no cluster Kubernetes; Activities chamam APIs Laravel/Swarm via HTTPS pública.
- Alvo de produção: Temporal Cloud nos primeiros 6-12 meses, depois self-host em Kubernetes (gatilho de custo).

---

## Fase 0 — Pré-requisitos (1-2 dias)

Decisões de plataforma que precisam estar resolvidas antes de qualquer commit no `order-service`:

1. **Conta Temporal Cloud provisionada** (ou cluster self-hosted em Kubernetes) com:

   - Namespaces `app-prod` e `app-staging`.
   - mTLS cert/key emitidos (Cloud) ou cluster CA exportada (self-host).
   - Retenção de history: 30 dias para prod, 7 dias para staging.

2. **DNS interno + egress liberado** do cluster Kubernetes para `*.tmprl.cloud:7233` (gRPC). Se self-host: ALB privado + Service ClusterIP.

3. **Pacote interno `acme/laravel-temporal-saga`** criado como repo separado (Packagist privado ou Satis). Esqueleto:
   ```
   laravel-temporal-saga/
   ├── composer.json
   ├── src/
   │   ├── ServiceProvider.php
   │   ├── ClientFactory.php           # cria WorkflowClient com mTLS
   │   ├── Saga.php                    # helper compensação LIFO
   │   ├── Console/RunWorkerCommand.php
   │   └── PHPStan/
   │       └── NoEloquentInWorkflowRule.php  # lint anti-determinismo
   └── config/temporal.php
   ```

---

## Fase 1 — Infra do Temporal local (Compose para dev) (1 dia)

Antes de mexer no `order-service`, replicar o `saga-temporal/docker-compose.yml` deste PoC no ambiente de dev local. Adicionar ao `docker-compose.override.yml` do `order-service`:

> ⚠️ **Achado decisivo:** Temporal **NÃO suporta MariaDB**. Tentativa com `mariadb:11.4` + driver `mysql8` falhou em migration de schema do auto-setup (CREATE INDEX com path JSON usa sintaxe MySQL 8 incompatível). Como esta aplicação Laravel usa MariaDB em produção, **adoção de Temporal exige um 2º SGBD dedicado** ao engine — o banco do `order-service` continua MariaDB; o do Temporal precisa ser PostgreSQL ou MySQL 8. Esse custo operacional (DBA, backup, monitoring de outro engine) está registrado em `findings-temporal.md` §2.2.6 e é **fortemente negativo para a adoção de Temporal**.

```yaml
services:
  postgresql-temporal:
    image: postgres:16-alpine
    environment:
      POSTGRES_PASSWORD: temporal
      POSTGRES_USER: temporal
    volumes: ["temporal-pg:/var/lib/postgresql/data"]

  temporal:
    image: temporalio/auto-setup:1.26
    depends_on: [postgresql-temporal]
    environment:
      DB: postgres12
      POSTGRES_SEEDS: postgresql-temporal
      POSTGRES_USER: temporal
      POSTGRES_PWD: temporal
    ports: ["7233:7233"]
    healthcheck:
      test: ["CMD", "tctl", "--address", "temporal:7233", "cluster", "health"]
      interval: 5s
      timeout: 5s
      retries: 30
      start_period: 20s

  temporal-ui:
    image: temporalio/ui:2.35.0
    environment:
      TEMPORAL_ADDRESS: temporal:7233
    ports: ["8088:8080"]

volumes:
  temporal-pg:
```

> **Importante**: o healthcheck no service `temporal` é necessário porque a imagem `temporalio/auto-setup` leva ~10-15s para inicializar (cria namespace `default`, sobe os 4 serviços internos). Sem ele, workers que usam `depends_on: temporal` partem antes do gRPC estar respondendo e morrem com `connection refused`. Os services de worker (Fase 3) devem usar `depends_on: temporal: condition: service_healthy` — bug encontrado durante o PoC e documentado em `findings-temporal.md` §1.3.

Validação: `docker compose up temporal` + `tctl --address localhost:7233 namespace list` deve retornar `default`.

---

## Fase 2 — Imagem Docker da aplicação (PHP + grpc + RoadRunner) (2-3 dias)

A imagem atual do `order-service` (php-fpm) não consegue rodar workers Temporal — falta extensão `grpc` e o worker precisa ser long-running com loop próprio (RoadRunner).

**Estratégia**: duas imagens, mesmo Dockerfile multi-stage.

`order-service/Dockerfile`:

```dockerfile
# === Stage base: extensões PHP comuns aos dois alvos ===
FROM php:8.3-cli-alpine AS base
RUN apk add --no-cache git unzip linux-headers zlib-dev libstdc++ $PHPIZE_DEPS \
 && pecl install grpc \
 && docker-php-ext-enable grpc \
 && docker-php-ext-install sockets pcntl pdo_mysql \
 && apk del $PHPIZE_DEPS \
 && apk add --no-cache libstdc++ zlib

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-dev
COPY . .
RUN composer dump-autoload -o

# === Stage api: php-fpm para requests HTTP ===
FROM base AS api
RUN apk add --no-cache nginx supervisor
# config nginx + php-fpm como hoje
CMD ["/usr/bin/supervisord"]

# === Stage worker: RoadRunner para workflows/activities ===
FROM ghcr.io/roadrunner-server/roadrunner:2024.3 AS rr
FROM base AS worker
COPY --from=rr /usr/bin/rr /usr/bin/rr
COPY .rr.yaml ./
CMD ["rr", "serve", "-c", ".rr.yaml"]
```

`.rr.yaml` (referência: `saga-temporal/.rr.yaml` deste PoC):

```yaml
version: "3"
rpc: { listen: tcp://127.0.0.1:6001 }
server:
  command: "php worker.php"
temporal:
  address: ${TEMPORAL_ADDRESS}
  activities: { num_workers: 4 }
```

CI publica duas tags: `order-service:1.x.y-api` e `order-service:1.x.y-worker`.

---

## Fase 3 — Pacote interno: instalação no `order-service` (1 dia)

```bash
cd order-service
composer require acme/laravel-temporal-saga:^1.0 temporal/sdk:^2.17 spiral/roadrunner-cli:^2.5
php artisan vendor:publish --tag=temporal-config
```

`config/temporal.php` (publicado pelo pacote):

```php
return [
    'address' => env('TEMPORAL_ADDRESS', 'temporal:7233'),
    'namespace' => env('TEMPORAL_NAMESPACE', 'app-prod'),
    'tls' => [
        'cert' => env('TEMPORAL_TLS_CERT'),
        'key'  => env('TEMPORAL_TLS_KEY'),
    ],
    'task_queues' => [
        'order-saga' => [
            'workflows'  => [\App\Sagas\ActivateStoreSaga::class],
            'activities' => [\App\Sagas\Activities\OrderActivities::class],
        ],
    ],
];
```

`.env`:

```ini
TEMPORAL_ADDRESS=temporal:7233
TEMPORAL_NAMESPACE=app-prod
TEMPORAL_TLS_CERT=/secrets/temporal-client.crt
TEMPORAL_TLS_KEY=/secrets/temporal-client.key
```

---

## Fase 4 — Refatorar o endpoint de criação de pedido (2-3 dias)

### 4.1 Antes (síncrono — código original simplificado)

```php
// app/Http/Controllers/OrderController.php
public function activate(int $storeId, Request $request)
{
    DB::transaction(function () use ($storeId) {
        $reservation = $this->reserveStock($storeId);
        $charge = $this->paymentApi->chargeCredit($storeId);
        $shipping = $this->shippingApi->confirm($storeId);
        Store::find($storeId)->update(['status' => 'active']);
    });
    return response()->json(['ok' => true]);
}
```

Problemas: timeout do request; rollback de DB não desfaz `chargeCredit` se `confirm` falhar; sem visibilidade de retry; sem rastreabilidade de qual passo travou.

### 4.2 Depois (controller dispara workflow, retorna 202)

```php
// app/Http/Controllers/OrderController.php
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowOptions;
use App\Sagas\ActivateStoreSagaInterface;
use Carbon\CarbonInterval;

public function activate(int $storeId, WorkflowClient $client)
{
    $stub = $client->newWorkflowStub(
        ActivateStoreSagaInterface::class,
        WorkflowOptions::new()
            ->withWorkflowId("activate-store-{$storeId}")    // dedupe: 2 cliques = 1 saga
            ->withTaskQueue('order-saga')
            ->withWorkflowExecutionTimeout(CarbonInterval::minutes(15))
    );

    $run = $client->start($stub, $storeId);

    return response()->json([
        'saga_id' => $run->getExecution()->getID(),
        'run_id'  => $run->getExecution()->getRunID(),
        'status'  => 'pending',
    ], 202);
}

public function showSaga(string $sagaId, WorkflowClient $client)
{
    $info = $client->describeWorkflowExecution(
        config('temporal.namespace'),
        $sagaId,
    );
    return response()->json([
        'status' => $info->getWorkflowExecutionInfo()->getStatus()->name,
    ]);
}
```

### 4.3 Workflow (pacote `app/Sagas/`)

```php
// app/Sagas/ActivateStoreSagaInterface.php
namespace App\Sagas;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface ActivateStoreSagaInterface
{
    #[WorkflowMethod(name: 'ActivateStoreSaga')]
    public function execute(int $storeId);
}
```

```php
// app/Sagas/ActivateStoreSaga.php
namespace App\Sagas;

use App\Sagas\Activities\OrderActivitiesInterface;
use App\Sagas\Activities\PaymentActivitiesInterface;
use Acme\TemporalSaga\Saga;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Workflow;
use Carbon\CarbonInterval;

class ActivateStoreSaga implements ActivateStoreSagaInterface
{
    private OrderActivitiesInterface $order;
    private PaymentActivitiesInterface $payment;

    public function __construct()
    {
        $opts = ActivityOptions::new()
            ->withStartToCloseTimeout(CarbonInterval::seconds(30))
            ->withRetryOptions(
                RetryOptions::new()
                    ->withMaximumAttempts(3)
                    ->withInitialInterval(CarbonInterval::seconds(1))
                    ->withBackoffCoefficient(2.0)
            );

        $this->order = Workflow::newActivityStub(
            OrderActivitiesInterface::class, $opts
        );
        $this->payment = Workflow::newActivityStub(
            PaymentActivitiesInterface::class, $opts
        );
    }

    public function execute(int $storeId)
    {
        $saga = new Saga();
        try {
            $reservation = yield $this->order->reserveStock($storeId);
            $saga->addCompensation(fn() =>
                yield $this->order->releaseStock($reservation['id'])
            );

            $charge = yield $this->payment->chargeCredit($storeId);
            $saga->addCompensation(fn() =>
                yield $this->payment->refundCredit($charge['id'])
            );

            yield $this->order->confirmShipping($storeId);

            return ['status' => 'completed', 'storeId' => $storeId];
        } catch (\Throwable $e) {
            yield $saga->compensate();
            throw $e;
        }
    }
}
```

### 4.4 Activities (Eloquent comum, sem restrições)

```php
// app/Sagas/Activities/OrderActivitiesInterface.php
namespace App\Sagas\Activities;

use Temporal\Activity\ActivityInterface;

#[ActivityInterface(prefix: "Order.")]
interface OrderActivitiesInterface
{
    public function reserveStock(int $storeId): array;
    public function releaseStock(int $reservationId): void;
    public function confirmShipping(int $storeId): array;
}
```

```php
// app/Sagas/Activities/OrderActivities.php
namespace App\Sagas\Activities;

use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;

class OrderActivities implements OrderActivitiesInterface
{
    public function reserveStock(int $storeId): array
    {
        return DB::transaction(function () use ($storeId) {
            $r = StockReservation::create([
                'store_id' => $storeId,
                'idempotency_key' => "activate-store-{$storeId}",
            ]);
            return $r->toArray();
        });
    }

    public function releaseStock(int $reservationId): void
    {
        StockReservation::find($reservationId)?->release();
    }

    public function confirmShipping(int $storeId): array
    {
        // chamada Guzzle pra shipping-api como hoje
        return app(ShippingClient::class)->confirm($storeId);
    }
}
```

> **Importante**: idempotency_key obrigatório. Activities podem ser executadas mais de uma vez (retry). Sem chave idempotente, retry duplica reserva. Esse é o item que o lint PHPStan do pacote interno deve enforçar.

---

## Fase 5 — Worker process (Artisan command) (1 dia)

```php
// app/Console/Commands/RunSagaWorker.php — fornecido pelo pacote interno
class RunSagaWorker extends Command
{
    protected $signature = 'saga:worker {queue}';

    public function handle()
    {
        $factory = WorkerFactory::create();
        $worker = $factory->newWorker($this->argument('queue'));

        $config = config("temporal.task_queues.{$this->argument('queue')}");
        foreach ($config['workflows'] as $w)  $worker->registerWorkflowTypes($w);
        foreach ($config['activities'] as $a) $worker->registerActivity($a);

        $factory->run();
    }
}
```

Local (dev): `php artisan saga:worker order-saga` em terminal separado.

---

## Fase 6 — Manifestos Kubernetes (worker) (2-3 dias)

`k8s/order-service/worker-deployment.yaml`:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: order-saga-worker
  namespace: order
spec:
  replicas: 3
  selector: { matchLabels: { app: order-saga-worker } }
  template:
    metadata:
      labels: { app: order-saga-worker }
    spec:
      containers:
        - name: worker
          image: registry.example.com/order-service:1.x.y-worker
          command: ["php", "artisan", "saga:worker", "order-saga"]
          env:
            - { name: TEMPORAL_ADDRESS, value: "app.tmprl.cloud:7233" }
            - { name: TEMPORAL_NAMESPACE, value: "app-prod" }
            - name: DB_HOST
              valueFrom: { secretKeyRef: { name: order-db, key: host } }
          volumeMounts:
            - { name: temporal-tls, mountPath: /secrets, readOnly: true }
          resources:
            requests: { cpu: "200m", memory: "256Mi" }
            limits: { cpu: "1000m", memory: "1Gi" }
          livenessProbe:
            exec: { command: ["pgrep", "-f", "saga:worker"] }
            periodSeconds: 30
      volumes:
        - name: temporal-tls
          secret: { secretName: temporal-client-tls }
```

Notas críticas:

- **Sem Service/Ingress**: workers não recebem tráfego, apenas fazem long-polling pra Temporal.
- **Replicas ≥ 2** desde o dia 1 — single replica = SPOF de processamento.
- **HPA por CPU** funciona, mas o melhor sinal é `temporal_workflow_task_schedule_to_start_latency` via Prometheus → Datadog.

API container continua igual (php-fpm), só ganha env vars Temporal:

```yaml
env:
  - { name: TEMPORAL_ADDRESS, value: "app.tmprl.cloud:7233" }
  - { name: TEMPORAL_NAMESPACE, value: "app-prod" }
```

---

## Fase 7 — Cross-service (chamadas pra `payment-service`, shipping-api) (decisão arquitetural)

Duas opções pro `chargeCredit` que vive no `payment-service`:

### Opção A — HTTP Activity (recomendado pra começar)

Activity no `order-service` chama o `payment-service` via Guzzle, como hoje. Temporal cuida de retry/timeout. Zero mudança no `payment-service`.

```php
public function chargeCredit(int $storeId): array
{
    return Http::withToken(config('payment.token'))
        ->timeout(10)
        ->post(config('payment.url') . '/credits/charge', ['store_id' => $storeId])
        ->throw()
        ->json();
}
```

### Opção B — Worker dedicado em `payment-service` (depois)

`payment-service` ganha seu próprio worker registrando `PaymentActivities` na task queue `payment-saga`. Workflow no `order-service` usa `Workflow::newActivityStub()` apontando pra essa queue.

Vantagens: retry granular por serviço; falha em `payment-service` não consome retry do orquestrador; observabilidade separada.

Custo: replicar Fases 2-6 em cada repo Laravel que vira "owner" de activities.

**Caminho recomendado**: começar em A, migrar pra B quando volume de uma activity específica justificar (ex: `chargeCredit` p99 acima de 500ms ou >1k/min).

---

## Fase 8 — Observabilidade e operação (3-5 dias)

1. **Datadog APM**: pacote interno injeta tracer no worker. Cada Activity vira span filho do workflow.
2. **Métricas Temporal → Prometheus**: scrape do `:9090/metrics` do worker.
3. **Alertas**:
   - `temporal_workflow_failed_total{workflow_type="ActivateStoreSaga"}` > 5/min → PagerDuty.
   - Activity `releaseStock` failure → ticket Jira automático (compensação não-determinística é corrupção em potencial).
4. **Runbook**: `docs/runbooks/temporal-saga-stuck.md` com comandos `tctl workflow stack`, `tctl workflow reset`, etc.

---

## Fase 9 — Lint anti-determinismo (PHPStan custom) (3-5 dias)

Regra do pacote interno que falha CI se workflow tiver:

- `now()`, `Carbon::now()`, `time()` (use `Workflow::now()`).
- `DB::`, `Eloquent::` direto (deve estar em Activity).
- `Http::`, `Guzzle::` direto (deve estar em Activity).
- `random_int()`, `Str::uuid()` (use `Workflow::sideEffect()`).

Exemplo:

```php
// packages/laravel-temporal-saga/src/PHPStan/NoEloquentInWorkflowRule.php
class NoEloquentInWorkflowRule implements Rule
{
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isInsideWorkflow($scope)) return [];
        if ($this->callsEloquent($node)) {
            return [RuleErrorBuilder::message(
                'Eloquent não pode ser usado dentro de Workflow — mova para Activity (determinismo).'
            )->build()];
        }
        return [];
    }
}
```

CI roda `vendor/bin/phpstan analyse app/Sagas` em PR; bloqueia merge.

---

## Cronograma consolidado

| Fase                                          | Esforço                            | Quem             |
| --------------------------------------------- | ---------------------------------- | ---------------- |
| 0. Pré-requisitos (Cloud, namespace, DNS)     | 1-2 dias                           | DevOps           |
| 1. Compose local                              | 1 dia                              | Backend          |
| 2. Dockerfile multi-stage + RoadRunner        | 2-3 dias                           | Backend + DevOps |
| 3. Pacote interno (instalação básica)         | 1 dia                              | Backend          |
| 4. Refator do endpoint + Workflow + Activities| 2-3 dias                           | Backend          |
| 5. Artisan command worker                     | 1 dia                              | Backend          |
| 6. Manifestos Kubernetes                      | 2-3 dias                           | DevOps           |
| 7. Cross-service (decisão A/B)                | 1 dia decisão + N dias por serviço | Time + Backend   |
| 8. Observabilidade                            | 3-5 dias                           | Backend + SRE    |
| 9. Lint PHPStan                               | 3-5 dias                           | Backend          |
| **Total p/ primeiro saga em prod**            | **~17-23 dias eng**                | —                |

Confere com o número da `recomendacao-saga.md` §6 (custo de adoção ~1 semestre considerando rampa de aprendizado em paralelo a outras demandas).

---

## Checklist mínimo pra dar `git push` da PR de adoção

- [ ] Imagem worker buildada e publicada em registry.
- [ ] Namespace Temporal (Cloud ou self-host) acessível do cluster Kubernetes.
- [ ] mTLS cert montado como Secret no Kubernetes.
- [ ] `php artisan saga:worker order-saga` rodando ≥2 replicas.
- [ ] Endpoint de criação de pedido retorna 202 com `saga_id`.
- [ ] `GET /sagas/{id}` retorna status correto.
- [ ] Teste E2E: happy path + FORCE_FAIL=step3 verifica compensação completa.
- [ ] PHPStan custom rules ativas no CI.
- [ ] Runbook publicado e drill executado uma vez.
- [ ] Dashboard Datadog "Saga: ActivateStore" ativo.
