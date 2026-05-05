# Integração Laravel ↔ RabbitMQ coreografado — passo a passo

Como adotar a abordagem **RabbitMQ + lib coreografada** como padrão organizacional em uma aplicação Laravel rodando em Docker Swarm. Documento simétrico a [`integracao-rabbitmq.md`](./integracao-rabbitmq.md) (orquestrado), [`integracao-temporal.md`](./integracao-temporal.md) e [`integracao-step-functions.md`](./integracao-step-functions.md), mas para o modelo **coreografado** validado em [`saga-rabbitmq-coreografado/`](../saga-rabbitmq-coreografado/).

O exemplo concreto usado ao longo deste guia é um fluxo de criação de pedido com reserva de estoque + cobrança + confirmação, implementado como uma cadeia de eventos: `saga.started.create_order → stock.reserved → credit.charged → saga.completed.create_order`.

Premissas em vigor:

- Apenas o serviço Laravel principal (`order-service`) migra para Kubernetes inicialmente; demais serviços ficam em Swarm por tempo indeterminado, configurando uma stack híbrida Docker Swarm + Kubernetes.
- RabbitMQ provisionado como serviço compartilhado (Swarm hoje, eventualmente Amazon MQ ou cluster K8s com quorum queues — ver Fase 6).
- A **lib é mantida internamente** (`acme/laravel-saga`) — a mesma lib do guia orquestrado, mas v1 implementa apenas o modo coreografado conforme a decisão de design registrada em [`recomendacao-saga.md`](./recomendacao-saga.md) §5.1.
- Cada serviço Laravel tem seu próprio banco (MariaDB do `order-service`, MariaDB do `payment-service`). A lib usa esse banco para `step_log` e `compensation_log` locais — não há banco dedicado.

---

## Fase 0 — Pré-requisitos

Decisões de plataforma que precisam estar resolvidas antes de qualquer commit:

1. **RabbitMQ 4.3+ provisionado** com plugins `management` e `prometheus` habilitados:

   - Single-node aceitável para começar (Khepri default; mirrored queues removidas em 4.0).
   - Para HA em produção real: cluster 3 nós com quorum queues — testes de failover de líder Raft em [`findings-rabbitmq.md`](./findings-rabbitmq.md) §7.2.

2. **Pacote interno `acme/laravel-saga`** publicado (Packagist privado, Satis ou monorepo). Esqueleto:

   ```
   laravel-saga/
   ├── composer.json
   ├── src/
   │   ├── ServiceProvider.php
   │   ├── EventBus.php             # publish/subscribe AMQP + reconnect
   │   ├── SagaLog.php              # step_log + compensation_log no banco
   │   ├── SagaListener.php         # API fluente react()/compensate()/listen()
   │   ├── SagaDefinition.php       # contrato abstrato que cada saga da app implementa
   │   ├── SagaRegistry.php         # coleta SagaDefinitions via container tag
   │   └── Console/RunWorkerCommand.php  # genérico — itera o registry
   ├── config/saga.php
   └── database/migrations/
       └── xxxx_xx_xx_create_saga_log_tables.php
   ```

   **Contrato lib ⇄ aplicação:** a lib não conhece os handlers da aplicação. Ela expõe `SagaDefinition` como classe abstrata; cada saga organizacional vira uma classe da aplicação que estende `SagaDefinition` e declara seus `react()`/`compensate()` num único método `register(SagaListener $listener)`. A aplicação registra essas classes via tag no container (`saga.definitions`); o `RunWorkerCommand` da lib resolve o `SagaRegistry`, itera as definições e delega a montagem do chain a cada uma.

3. **Convenção de nomes de evento** combinada com o time:

   - Domínio: `<bounded-context>.<event>` (ex.: `stock.reserved`, `credit.charged`, `email.verified`).
   - Início de fluxo: `saga.started.<flow>` — o sufixo `<flow>` (ex.: `create_order`, `refund_order`, `activate_store`) é o que **multiplexa fluxos distintos** sobre a mesma lib e o mesmo broker. Cada `<flow>` corresponde a uma cadeia de `react()` independente.
   - Falha: `saga.failed` (fanout para todos os consumers que assinam).
   - Sucesso terminal: `saga.completed.<flow>` (informativo; consumido apenas por aggregator/observabilidade).
   - **Isolamento por instância:** `saga_id` (UUID gerado por requisição) é a única dimensão que separa execuções concorrentes — vale tanto entre sagas do mesmo `<flow>` quanto entre `<flow>`s diferentes. As tabelas `saga_step_log` e `saga_compensation_log` têm PK `(saga_id, step)`, então N sagas coexistem naturalmente.

4. **Endereçar dívida técnica da PoC antes de declarar a lib v1.0.** A PoC em [`saga-rabbitmq-coreografado/`](../saga-rabbitmq-coreografado/) prioriza clareza pedagógica e mínimo viável; cinco simplificações conscientes precisam ser revisadas no caminho pra produção:

   | #   | Simplificação na PoC                                           | Risco em produção                                                                                                                                                                                              | Encaminhamento na v1                                                                                                                                                                                                                                           |
   | --- | -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
   | 1   | `basic_publish` é fire-and-forget — sem **publisher confirms** | Mensagens podem ser perdidas se o broker cair entre `publish` e o flush real (ex.: ack TCP recebido mas mensagem ainda em buffer do broker).                                                                   | Habilitar `confirm_select` no channel; expor `publish()` síncrono que aguarda confirm (com timeout configurável) e `publishAsync()` pra casos onde latência importa mais que garantia. Métrica de `publisher_nack` pra alertar se broker rejeita.              |
   | 2   | Retry de handler via `ack + sleep(2) + republish`              | Bloqueia o channel durante o sleep (não processa outras mensagens); perde ordem (mensagem republicada vai pro fim da fila); CPU spike se muitas falhas concorrentes.                                           | Configurar **dead-letter exchange (DLX) com TTL** — `nack` da mensagem original com `requeue=false` faz broker mandar pra DLX, que re-injeta na queue após o TTL. Não bloqueia channel, escalável. Configuração via policy do RabbitMQ ou argumentos da queue. |
   | 3   | Worker usa **1 channel só**                                    | Não há paralelismo intra-processo: handler lento bloqueia toda a queue do worker; throughput cap = 1/handler-latency.                                                                                          | Pool de N channels (cada um com seu `basic_consume`) sobre a mesma connection. Tunable via config `saga.worker.parallelism`. Cuidado: handlers precisam ser thread-safe (sem state global mutável).                                                            |
   | 4   | Heartbeat AMQP no default (60s)                                | Se o broker considera a conexão morta antes do worker perceber, a próxima operação dá `AMQPConnectionClosedException` — `subscribe()` recupera, mas pode haver janela de mensagens em voo perdidas/duplicadas. | Configurar heartbeat explícito (recomendado: 10-30s) via `AMQPStreamConnection` constructor. Documentar interação com `consumer_timeout` do broker (default 30min em RabbitMQ 3.12+).                                                                          |
   | 5   | Sem rate limit / circuit breaker no publish                    | Se o broker estiver em overload e o worker tentar republicar (caminho de erro do handler), agrava o problema — feedback loop.                                                                                  | Circuit breaker simples no `publish()`: após N falhas seguidas em janela de tempo, abrir circuito e falhar fast por X segundos. Métrica de circuit state pra observabilidade.                                                                                  |

   Esses cinco pontos não são bug — são **decisões de simplificação documentadas** da PoC, registradas aqui pra que a v1 da lib não os herde por inércia. Antes de marcar `acme/laravel-saga@1.0.0` como release estável, cada um deve ter código + teste correspondente.

---

## Fase 1 — Infra do RabbitMQ local (Compose para dev)

Antes de mexer no `order-service`, replicar o `saga-rabbitmq-coreografado/docker-compose.yml` deste PoC no ambiente de dev local. Adicionar ao `docker-compose.override.yml` do `order-service`:

```yaml
services:
  rabbitmq:
    image: rabbitmq:4.3-management-alpine
    ports: ["5672:5672", "15672:15672"]
    healthcheck:
      test: ["CMD", "rabbitmq-diagnostics", "ping"]
      interval: 5s
      timeout: 3s
      retries: 20
    volumes: ["rabbitmq-data:/var/lib/rabbitmq"]

volumes:
  rabbitmq-data:
```

Validação: `docker compose up rabbitmq` + abrir http://localhost:15672 (login `guest`/`guest`) deve mostrar o Management UI.

---

## Fase 2 — Service do worker no compose

Pré-requisito: a imagem PHP do serviço precisa ter as extensões `sockets` e `bcmath` (requeridas pelo `php-amqplib`). A maioria dos stacks Laravel já tem ambas via base image compartilhada — quando esse for o caso, **não é preciso construir uma imagem nova nem adotar Dockerfile multi-stage**. O worker é só **mais um service no compose** apontando pra mesma imagem do app, com `command:` próprio.

Esse padrão — uma imagem por app, N services com `command:` distintos e `restart:` policy independente — é o mesmo já aplicado a outros processos long-running de aplicações Laravel (workers de fila, jobs agendados em loop, listeners de eventos, etc.).

`docker-compose.development.yml` (ou equivalente do app):

```yaml
order-service-saga-worker:
  build:
    dockerfile_inline: |
      FROM <imagem-base-php-do-app>:latest
  command: php artisan saga:listen
  working_dir: /order-service
  volumes:
    - ./order-service:/order-service
  environment:
    <<: *order_service_envs
  restart: always
  depends_on:
    - rabbitmq
```

O service HTTP existente (`order-service`, php-fpm) permanece como está — mesma imagem, `command:` padrão. O worker compartilha código, configs, conexão de banco e `.env` por volume; só diverge no entrypoint.

**Quando migrarem pra K8s/EKS**: o mesmo padrão se traduz em dois `Deployment`s apontando pra mesma `image:`, com `args:` diferentes (`["php", "artisan", "serve"]` vs `["php", "artisan", "saga:listen"]`). Não é preciso quebrar em duas tags no registry — escala, rollout, livenessProbe e HPA continuam independentes porque são objetos K8s distintos, não imagens distintas.

> **Quando consideraria multi-stage `-api`/`-cli` separado**: se um dia API e worker precisarem de dependências de runtime conflitantes (ex.: worker exigindo extensão pesada que infla footprint da API, ou versões divergentes de uma extensão). Hoje não é o caso — `php-fpm-base` cobre os dois.

---

## Fase 3 — Instalação do pacote no `order-service`

```bash
cd order-service
composer require acme/laravel-saga:^1.0 php-amqplib/php-amqplib:^3.7
php artisan vendor:publish --tag=saga-config
php artisan vendor:publish --tag=saga-migrations
php artisan migrate
```

`config/saga.php` (publicado pelo pacote):

```php
return [
    'amqp' => [
        'host' => env('AMQP_HOST', 'rabbitmq'),
        'port' => (int) env('AMQP_PORT', 5672),
        'user' => env('AMQP_USER', 'guest'),
        'pass' => env('AMQP_PASS', 'guest'),
        'queue_type' => env('AMQP_QUEUE_TYPE', 'classic'), // 'quorum' em prod multi-node
    ],
    'service_name' => env('SAGA_SERVICE_NAME', 'order-service'),
    'queue_name' => env('SAGA_QUEUE', 'order-service.saga'),
    'log_connection' => env('SAGA_DB_CONNECTION', 'mysql'), // banco do próprio serviço
];
```

Migration cria duas tabelas no banco principal do serviço:

```sql
CREATE TABLE saga_step_log (
    saga_id      CHAR(36)     NOT NULL,
    step         VARCHAR(64)  NOT NULL,
    completed_at DATETIME(6)  NOT NULL,
    PRIMARY KEY (saga_id, step)
);

CREATE TABLE saga_compensation_log (
    saga_id    CHAR(36)     NOT NULL,
    step       VARCHAR(64)  NOT NULL,
    status     VARCHAR(16)  NOT NULL DEFAULT 'in_progress',  -- 'in_progress' | 'done'
    attempts   INT UNSIGNED NOT NULL DEFAULT 0,
    payload    JSON         NOT NULL,
    started_at DATETIME(6)  NOT NULL,
    PRIMARY KEY (saga_id, step)
);
```

`.env`:

```ini
AMQP_HOST=rabbitmq
AMQP_USER=guest
AMQP_PASS=guest
AMQP_QUEUE_TYPE=classic
SAGA_SERVICE_NAME=order-service
SAGA_QUEUE=order-service.saga
SAGA_DB_CONNECTION=mysql
```

---

## Fase 4 — Definir os handlers da primeira saga

Vamos refatorar o endpoint atual de criação de pedido. O exemplo original (síncrono) era algo como:

### 4.1 Antes (síncrono)

```php
// app/Http/Controllers/OrderController.php
public function create(Request $request)
{
    DB::transaction(function () use ($request) {
        $reservation = $this->stockApi->reserve($request->input('items'));
        $charge = $this->paymentApi->charge($request->input('payment'));
        $tracking = $this->shippingApi->confirm($reservation->id);
        Order::create(['status' => 'active', /* ... */]);
    });
    return response()->json(['ok' => true]);
}
```

Problemas: timeout do request; rollback de DB não desfaz `payment.charge` se `shipping.confirm` falhar; sem visibilidade; sem rastreabilidade de qual passo travou.

### 4.2 Depois — controller dispara saga e retorna 202

```php
// app/Http/Controllers/OrderController.php
public function create(Request $request, EventBus $bus)
{
    $sagaId = $bus->startSaga('create_order', [
        'items' => $request->input('items'),
        'payment' => $request->input('payment'),
        'user_id' => $request->user()->id,
    ]);
    return response()->json(['saga_id' => $sagaId], 202);
}
```

> `startSaga($flow, $payload)` é o entrypoint correto **apenas no ponto de origem** da saga. Internamente ele gera o `sagaId` (UUID), publica `saga.started.<flow>` e retorna o `sagaId` pra logging/rastreio. Para propagar steps no meio do fluxo (dentro de handlers reagindo a eventos), continua-se usando `publish($eventType, $sagaId, $payload)` com o `sagaId` recebido — preservar o `sagaId` entre eventos é o que costura o `step_log`/`compensation_log` dos serviços envolvidos.

### 4.3 Handlers de step e compensação no `order-service`

```php
// app/Saga/Handlers/ReserveStockHandler.php
namespace App\Saga\Handlers;

final class ReserveStockHandler
{
    public function __construct(private StockRepository $stockRepo) {}

    public function __invoke(string $sagaId, array $payload): array
    {
        $reservationId = $this->stockRepo->reserve(
            items: $payload['items'],
            sagaId: $sagaId, // chave de idempotência local
        );
        return [
            'reservation_id' => $reservationId,
            'items' => $payload['items'],
            'payment' => $payload['payment'],
            'user_id' => $payload['user_id'],
        ];
    }
}
```

```php
// app/Saga/Handlers/ConfirmShippingHandler.php
final class ConfirmShippingHandler
{
    public function __invoke(string $sagaId, array $payload): array
    {
        $tracking = $this->shippingRepo->confirm($payload['reservation_id']);
        Order::create([
            'saga_id' => $sagaId,
            'status' => 'active',
            'tracking_code' => $tracking,
            // ...
        ]);
        return ['tracking_code' => $tracking];
    }
}
```

```php
// app/Saga/Handlers/ReleaseStockCompensation.php
final class ReleaseStockCompensation
{
    public function __invoke(string $sagaId, array $failurePayload): void
    {
        // Idempotência: a lib só chama essa compensação se ReserveStock tiver
        // marcado step_log='done' e compensation_log ainda não estiver 'done'.
        $this->stockRepo->release(sagaId: $sagaId);
    }
}
```

### 4.4 Declarar a saga na aplicação

A aplicação declara cada saga como uma classe que estende `SagaDefinition` (vinda da lib). Os handlers e compensações são injetados no construtor da definição — Laravel resolve via container automaticamente.

```php
// app/Saga/Definitions/CreateOrderSaga.php
namespace App\Saga\Definitions;

use Acme\LaravelSaga\SagaDefinition;
use Acme\LaravelSaga\SagaListener;
use App\Saga\Handlers\ReserveStockHandler;
use App\Saga\Handlers\ConfirmShippingHandler;
use App\Saga\Handlers\ReleaseStockCompensation;

final class CreateOrderSaga extends SagaDefinition
{
    public function __construct(
        private ReserveStockHandler $reserveStock,
        private ConfirmShippingHandler $confirmShipping,
        private ReleaseStockCompensation $releaseStock,
    ) {}

    public function register(SagaListener $listener): void
    {
        $listener
            ->react(
                event: 'saga.started.create_order',
                stepName: 'reserve_stock',
                emit: 'stock.reserved',
                handler: $this->reserveStock,
            )
            ->react(
                event: 'credit.charged',
                stepName: 'confirm_shipping',
                emit: 'saga.completed.create_order',
                handler: $this->confirmShipping,
            )
            ->compensate('reserve_stock', $this->releaseStock);
    }
}
```

Registrar a definição no container via tag, em um ServiceProvider da aplicação:

```php
// app/Providers/SagaServiceProvider.php
namespace App\Providers;

use App\Saga\Definitions\CreateOrderSaga;
use Illuminate\Support\ServiceProvider;

final class SagaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([
            CreateOrderSaga::class,
        ], 'saga.definitions');
    }
}
```

(Não esqueça de adicionar `App\Providers\SagaServiceProvider::class` ao array `providers` em `config/app.php`.)

**O que a lib faz internamente** (não precisa ser tocado pela aplicação):

```php
// vendor/acme/laravel-saga/src/Console/RunWorkerCommand.php
namespace Acme\LaravelSaga\Console;

final class RunWorkerCommand extends Command
{
    protected $signature = 'saga:listen';

    public function handle(EventBus $bus, SagaLog $log, SagaRegistry $registry): int
    {
        $listener = new SagaListener(config('saga.service_name'), $bus, $log);
        foreach ($registry->all() as $definition) {
            $definition->register($listener);
        }
        $listener->listen(config('saga.queue_name'));
        return self::SUCCESS;
    }
}

// vendor/acme/laravel-saga/src/SagaRegistry.php
namespace Acme\LaravelSaga;

final class SagaRegistry
{
    public function __construct(private Container $app) {}

    /** @return iterable<SagaDefinition> */
    public function all(): iterable
    {
        return $this->app->tagged('saga.definitions');
    }
}
```

Validação: rodar `php artisan saga:listen` em foreground + abrir Management UI em http://localhost:15672 — bindings e queue durável aparecem. Disparar saga via `curl -X POST /orders` deve persistir step_log no banco do `order-service`.

### 4.5 Múltiplas sagas no mesmo worker

Um único worker do `order-service` pode participar de **N sagas distintas em paralelo** — basta criar mais classes `SagaDefinition` na aplicação e taggear todas. O command da lib continua o mesmo; ele itera o registry e cada definição se monta isoladamente.

Adicionar uma segunda saga (devolução de pedido):

```php
// app/Saga/Definitions/RefundOrderSaga.php
namespace App\Saga\Definitions;

use Acme\LaravelSaga\SagaDefinition;
use Acme\LaravelSaga\SagaListener;
use App\Saga\Handlers\RestockItemsHandler;
use App\Saga\Handlers\UnrestockItemsCompensation;

final class RefundOrderSaga extends SagaDefinition
{
    public function __construct(
        private RestockItemsHandler $restockItems,
        private UnrestockItemsCompensation $unrestockItems,
    ) {}

    public function register(SagaListener $listener): void
    {
        $listener
            ->react(
                event: 'saga.started.refund_order',
                stepName: 'restock_items',
                emit: 'items.restocked',
                handler: $this->restockItems,
            )
            ->compensate('restock_items', $this->unrestockItems);
    }
}
```

Atualizar o `SagaServiceProvider` para taggear ambas:

```php
public function register(): void
{
    $this->app->tag([
        CreateOrderSaga::class,
        RefundOrderSaga::class,
        // adicionar novas sagas aqui
    ], 'saga.definitions');
}
```

O que coexiste sem conflito:

- **Mesma queue `order-service.saga`** recebe eventos de `create_order` e `refund_order` — o roteamento por `routing_key` no topic exchange separa.
- **Mesmas tabelas `saga_step_log` / `saga_compensation_log`** servem aos dois fluxos — PK `(saga_id, step)` garante isolamento; não há colisão mesmo se ambos os fluxos tiverem um `step` chamado `reserve_stock`, porque `saga_id` difere.
- **Workers continuam stateless** — adicionar uma 3ª saga é só mais uma classe `SagaDefinition` + entrada no `tag()` + deploy do worker; nenhuma mudança em broker, banco, config ou no command da lib.

> **Por que uma classe por saga, não tudo num lugar só:** mantém cada fluxo isolado, testável (você instancia a `SagaDefinition` com mocks dos handlers e verifica os `react()`/`compensate()` chamados), e evita um command monolítico que cresce indefinidamente. A lib não precisa saber quantas sagas existem — só itera o que estiver tagueado.

---

## Fase 5 — Replicar wire-up no segundo serviço (`payment-service`)

```bash
cd payment-service
composer require acme/laravel-saga:^1.0
php artisan migrate # cria saga_step_log, saga_compensation_log no banco do payment
```

`payment-service/.env`:

```ini
SAGA_SERVICE_NAME=payment-service
SAGA_QUEUE=payment-service.saga
```

No `payment-service`, declara-se uma `SagaDefinition` que registra apenas o passo de cobrança da saga `create_order` — o `payment-service` não conhece a topologia inteira, só os eventos que ele escuta e emite:

```php
// payment-service/app/Saga/Definitions/CreateOrderPaymentSaga.php
namespace App\Saga\Definitions;

use Acme\LaravelSaga\SagaDefinition;
use Acme\LaravelSaga\SagaListener;
use App\Saga\Handlers\ChargeCreditHandler;
use App\Saga\Handlers\RefundCreditCompensation;

final class CreateOrderPaymentSaga extends SagaDefinition
{
    public function __construct(
        private ChargeCreditHandler $chargeCredit,
        private RefundCreditCompensation $refundCredit,
    ) {}

    public function register(SagaListener $listener): void
    {
        $listener
            ->react(
                event: 'stock.reserved',
                stepName: 'charge_credit',
                emit: 'credit.charged',
                handler: $this->chargeCredit,
            )
            ->compensate('charge_credit', $this->refundCredit);
    }
}
```

Tagueada no `SagaServiceProvider` do `payment-service` exatamente como no `order-service` (mesma string `saga.definitions`, mesma lib).

> **Acoplamento por convenção, não por tipo:** os nomes de evento (`stock.reserved`, `credit.charged`) são contratos implícitos entre serviços. Para reduzir risco de quebra silenciosa, recomenda-se adicionalmente um pacote `acme/saga-contracts` com **PHP DTOs versionados** compartilhados via Composer (mesma lógica do orquestrado, ver `integracao-rabbitmq.md` Fase 7).

> **Cada serviço, sua queue, suas N sagas:** a convenção `<service>.saga` (ex.: `order-service.saga`, `payment-service.saga`, `<outro>.saga`) garante que cada app tenha sua queue durável independente — falha de um worker não afeta o backlog dos outros. Dentro de cada queue, o serviço participa de **quantos `<flow>`s precisar** sem nova infra: basta adicionar mais uma `SagaDefinition` ao tag `saga.definitions` (Fase 4.5). Cada app tem 1 worker daemon, mas pode ser citado em N sagas organizacionais — não há limite estrutural.

---

## Fase 6 — Deploy em produção (Kubernetes)

`k8s/order-service/saga-worker-deployment.yaml`:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: order-saga-worker
spec:
  replicas: 2 # múltiplos consumers em paralelo na mesma queue (RabbitMQ distribui round-robin)
  selector:
    matchLabels: { app: order-saga-worker }
  template:
    metadata:
      labels: { app: order-saga-worker }
    spec:
      containers:
        - name: worker
          image: registry.example.com/order-service:1.x.y-cli
          command: ["php", "artisan", "saga:listen"]
          env:
            - name: AMQP_HOST
              value: "rabbitmq.messaging.svc.cluster.local"
            - name: AMQP_QUEUE_TYPE
              value: "quorum" # HA em multi-node cluster
            - name: SAGA_SERVICE_NAME
              value: "order-service"
            - name: SAGA_QUEUE
              value: "order-service.saga"
          envFrom:
            - secretRef: { name: order-service-db }
          resources:
            requests: { memory: "128Mi", cpu: "100m" }
            limits: { memory: "256Mi", cpu: "500m" }
          livenessProbe:
            exec:
              command: ["sh", "-c", "test -f /tmp/saga-alive && find /tmp/saga-alive -mmin -1"]
            periodSeconds: 30
```

> **Observação sobre HA:** em multi-node cluster, `AMQP_QUEUE_TYPE=quorum` é obrigatório (mirrored foi removido em 4.0). Há perda mensurável de throughput burst (~25% — ver `findings-rabbitmq.md` §7.2), aceitável diante do ganho de failover automático.

> **Reconnect:** o `EventBus` da lib já implementa backoff exponencial (1s→2s→4s→8s→16s) — testado em T1.4. Não exige tooling externo.

---

## Fase 7 — Saga Aggregator + UI de postmortem — opcional na v1

A coreografia tem postmortem distribuído: cada serviço tem seu `step_log` local. Para incidente em produção, juntar logs por `saga_id` em N serviços leva 2-15 min sem ferramenta dedicada (medição em T3.4). A solução madura é o **Saga Aggregator** — um microsserviço que assina `saga.#` (topic wildcard) e popula uma `saga_view` desnormalizada, sobre a qual roda uma UI Filament/Livewire.

Plano técnico completo (schema, lógica do consumer, custo) está em [`consideracoes.md`](./consideracoes.md) §7.

**Quando construir:**

- Se volume esperado for alto (≥ 1k sagas/dia) e postmortem manual virar gargalo.
- Se compliance/auditoria exigir audit trail unificado.
- **Não construa antes de 3 sagas reais em produção** — YAGNI: pode ser que postmortem manual via `grep` correlacionando `saga_id` seja suficiente para o volume real.

---

## Fase 8 — Migração de fluxos existentes

Para cada fluxo síncrono que vira saga:

1. **Identifique steps e compensações.** Mapeie o fluxo atual; cada `try/catch + rollback` é candidato a step + compensação.
2. **Defina contratos de evento.** `<context>.<event>` para sucesso, exception lançada para falha (a lib publica `saga.failed` automaticamente).
3. **Implemente os handlers em cada serviço dono do contexto.** Idempotência local: cada handler precisa ser seguro a ser executado duas vezes com o mesmo `sagaId`.
4. **Implemente as compensações.** Idem idempotência. A lib trata dedup via `compensation_log`, mas o efeito do handler precisa ser idempotente também.
5. **Adicione uma nova `SagaDefinition`** em `app/Saga/Definitions/` e registre-a no tag `saga.definitions` do `SagaServiceProvider`. Sem reiniciar o broker — basta restart do container worker; queues duráveis retêm mensagens em voo.
6. **Sunset do código síncrono.** Manter feature flag por algumas semanas para alternar entre síncrono e saga durante transição.

**Não tente migrar fluxos com 8+ steps de uma vez.** A lib coreografada favorece fluxos curtos (≤ 3-4 steps); fluxos longos viram spaghetti de eventos. Para esses, considere alternativas — orquestração centralizada (v2 da lib, se vier) ou ferramenta dedicada (Temporal). Discussão completa em [`recomendacao-saga.md`](./recomendacao-saga.md) §4.

---

## Ordem das fases

| Fase                                                     | Bloqueia próxima fase? |
| -------------------------------------------------------- | ---------------------- |
| 0 — Pré-requisitos (RabbitMQ + lib esqueleto)            | Sim                    |
| 1 — Infra Compose local                                  | Sim                    |
| 2 — Service do worker no compose (mesma imagem base)     | Sim                    |
| 3 — Instalação no `order-service` + migrations           | Sim                    |
| 4 — Refatorar primeiro fluxo para coreografia            | Sim                    |
| 5 — Replicar no `payment-service`                        | Sim (se a saga atravessa serviço) |
| 6 — Deploy K8s (workers + secrets + HPA opcional)        | Sim (pra produção)     |
| 7 — Saga Aggregator (opcional, recomendado em prod real) | Não — pode entrar depois |
| 8 — Migração incremental de fluxos                       | Não — incremental, saga por saga |

---

## Checklist de adoção

- [ ] Lib `acme/laravel-saga` v1.0 publicada (modo coreografado apenas, conforme decisão registrada em `recomendacao-saga.md` §5.1).
- [ ] **Dívida técnica da PoC endereçada na v1** (ver Fase 0 §4): publisher confirms, retry via DLX+TTL, pool de channels, heartbeat customizado, circuit breaker no publish.
- [ ] RabbitMQ 4.3+ provisionado em dev/staging/prod.
- [ ] Convenção de nomes de evento documentada e revisada com squads.
- [ ] Migrations `saga_step_log` e `saga_compensation_log` aplicadas no banco de cada serviço participante.
- [ ] Service `*-saga-worker` declarado no compose (dev) e Deployment correspondente em K8s/EKS, ambos reaproveitando a imagem do app com `command:`/`args:` `php artisan saga:listen`.
- [ ] Manifests K8s para workers (Deployment + secrets + livenessProbe).
- [ ] **Idempotência validada nos handlers** — teste explícito de "rodar duas vezes com mesmo `sagaId` produz mesmo efeito".
- [ ] **Idempotência validada nas compensações** — idem.
- [ ] Testes Tier 1-6 do `saga-rabbitmq-coreografado/` rodados na lib publicada (ver `checklist-testes-coreografia.md`).
- [ ] Quando aplicável: Saga Aggregator + UI de postmortem implementados.
- [ ] DLQ (Dead Letter Exchange) configurado para `saga.failed` que falham repetidamente em compensação.
- [ ] Alertas: subscriber dedicado em `saga.failed` que dispara para PagerDuty/Slack quando `compensation_log.attempts > 5`.
- [ ] Runbook de incidente: "saga X.Y.Z falhou — como diagnosticar".

---

## Diferenças vs `integracao-rabbitmq.md` (orquestrado)

| Aspecto                             | Orquestrado                                   | Coreografado (este)                               |
| ----------------------------------- | --------------------------------------------- | ------------------------------------------------- |
| Componente central                  | `saga:run-orchestrator` daemon                | nenhum — cada serviço tem seu worker independente |
| Tabela de saga                      | `saga_states` + `saga_steps` no banco central | `saga_step_log` + `saga_compensation_log` por svc |
| `saga_definition`                   | classe `ActivateStoreSaga` com `definition()` | não existe — ordem é emergente da topologia       |
| Versionamento                       | `saga_version` + bump manual                  | versionamento de evento (`stock.reserved.v2`)     |
| Compensação                         | LIFO disparada pelo orquestrador              | fanout — cada serviço decide localmente           |
| Postmortem                          | timeline central na tabela                    | Saga Aggregator (opcional) + correlation-id       |
| Custo de adoção (1 saga em prod)    | ~12-18 dias                                   | ~10-15 dias                                       |
| Risco T5.1 (silent corruption)      | sim                                           | n/a (estruturalmente seguro)                      |
| Latência sequencial (medido em PoC) | p99 23.8 ms                                   | p99 20.4 ms (~15% mais rápido)                    |

---

## Fluxograma de referência rápida

Dois mecanismos ortogonais coordenam uma saga coreografada. Eles atendem perguntas diferentes; os dois precisam estar certos pra saga funcionar:

| Mecanismo                        | Pergunta que responde                              | Onde mora                                                                                                                  |
| -------------------------------- | -------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| **Nome do evento** (routing key) | "_Quem_ recebe esta mensagem?"                     | Bindings no RabbitMQ + string em `react(event:…)` e `publish()`                                                            |
| **sagaId** (correlation ID)      | "_Qual instância_ da saga esta mensagem pertence?" | UUID gerado por `startSaga()` no disparo, propagado em todo `publish()` subsequente; PK em `step_log` e `compensation_log` |

### Eixo 1 — Nome do evento decide o roteamento

Para o fluxo `create_order` do exemplo do guia:

```
                     ┌────────────────────── topic exchange "saga.events" ──────────────────────┐
                     │                                                                          │
   ┌─────────────────┴──┐                                                              ┌────────┴───────────┐
   │ order-service.saga │                                                              │ payment-service.saga│
   │ (queue do worker)  │                                                              │ (queue do worker)   │
   └────────────────────┘                                                              └────────────────────┘
   bound on routing keys:                                                              bound on routing keys:
     • saga.started.create_order                                                         • stock.reserved
     • credit.charged                                                                    • saga.failed
     • saga.failed                                                                       (e do(s) flow(s) em que
     (e demais eventos                                                                     o payment-service participa)
      em que o order-service
      reage)


  Caminho de uma saga create_order:

  controller ──startSaga('create_order')──▶ publish "saga.started.create_order"
                                                    │
                                                    ▼ (broker entrega na queue do order-service)
                                            order-service worker
                                            • react('saga.started.create_order') → ReserveStockHandler
                                            • publish "stock.reserved"
                                                    │
                                                    ▼ (broker entrega na queue do payment-service)
                                            payment-service worker
                                            • react('stock.reserved') → ChargeCreditHandler
                                            • publish "credit.charged"
                                                    │
                                                    ▼ (broker entrega na queue do order-service)
                                            order-service worker
                                            • react('credit.charged') → ConfirmShippingHandler
                                            • publish "saga.completed.create_order"
                                                    │
                                                    ▼
                                            (consumido por aggregator/observabilidade,
                                             não por nenhum step ativo)
```

A única coisa que liga os serviços é **a string da routing key igual nos dois lados**: o produtor publica com aquela string, o consumidor declarou `react(event: 'mesma-string')` e bindou a queue dele com aquela string. Não há registry, não há descoberta, não há handshake — é puro roteamento por broker.

### Eixo 2 — sagaId costura os efeitos da mesma instância de saga

Considere duas chamadas paralelas a `POST /orders` disparando duas instâncias do mesmo flow:

```
   Instância X                                                Instância Y
   ───────────                                                ───────────

   sagaId=abc-111                                             sagaId=def-222
   payload={items:[a,b], user:42}                             payload={items:[c],   user:99}

   evento "saga.started.create_order"                         evento "saga.started.create_order"
   evento "stock.reserved"                                    evento "stock.reserved"
   evento "credit.charged"                                    evento "credit.charged"
   evento "saga.completed.create_order"                       evento "saga.completed.create_order"

   ┌─── as duas instâncias compartilham as MESMAS routing keys; ───┐
   │   o broker entrega tudo nas mesmas queues, intercalado.       │
   └───────────────────────────────────────────────────────────────┘

   Cada serviço grava no seu banco local:

   order-service.step_log                       payment-service.step_log
   ┌─────────────────┬───────────────────┐      ┌─────────────────┬───────────────────┐
   │ saga_id         │ step              │      │ saga_id         │ step              │
   ├─────────────────┼───────────────────┤      ├─────────────────┼───────────────────┤
   │ abc-111         │ reserve_stock     │      │ abc-111         │ charge_credit     │
   │ abc-111         │ confirm_shipping  │      │ def-222         │ charge_credit     │
   │ def-222         │ reserve_stock     │      └─────────────────┴───────────────────┘
   │ def-222         │ confirm_shipping  │
   └─────────────────┴───────────────────┘

   Quando saga.failed chega com sagaId=abc-111:
   • order-service roda runCompensations(sagaId=abc-111)
     → wasStepDone(abc-111, reserve_stock)? sim → ReleaseStockCompensation rodada
     → wasStepDone(abc-111, confirm_shipping)? talvez sim, talvez não
       (depende de quando a falha aconteceu)
   • payment-service roda runCompensations(sagaId=abc-111)
     → wasStepDone(abc-111, charge_credit)? sim → RefundCreditCompensation rodada

   A instância Y (sagaId=def-222) é completamente intocada — sagaId distingue
   uma execução da outra mesmo dentro do mesmo serviço, mesma queue, mesma rotina.
```

### Resumo mental

> **Routing key = qual serviço recebe. sagaId = qual instância está sendo processada.**
>
> A routing key é o _contrato cross-service_ (acoplamento por convenção: um serviço produz, outro consome a mesma string). O sagaId é o _correlation key_ dentro de cada serviço (chave que liga linhas em `step_log`/`compensation_log` da mesma execução).
>
> Errar a routing key = mensagem não chega no consumidor (saga trava). Errar o sagaId (gerar novo no meio do fluxo, ou perder o existente) = mensagem chega no consumidor mas as tabelas locais não correlacionam (compensação não acha o que desfazer). Por isso `startSaga()` só é usado no disparo, e `publish()` no meio do fluxo recebe o sagaId que veio de fora — nunca gera um novo.

---

## Referências internas

- [`saga-rabbitmq-coreografado/README.md`](../saga-rabbitmq-coreografado/README.md) — PoC viva com smoke tests e exemplo de uso.
- [`docs/findings-rabbitmq-coreografado.md`](./findings-rabbitmq-coreografado.md) — medições empíricas Tier 1-6.
- [`docs/checklist-testes-coreografia.md`](./checklist-testes-coreografia.md) — matriz de testes adaptados ao modelo.
- [`docs/recomendacao-saga.md`](./recomendacao-saga.md) §4 — árvore de decisão por cenário.
- [`docs/recomendacao-saga.md`](./recomendacao-saga.md) §5.1 — decisão de design da lib (v1 coreografado, v2 orquestrado se gatilho disparar).
- [`docs/consideracoes.md`](./consideracoes.md) §7 — plano técnico do Saga Aggregator.
