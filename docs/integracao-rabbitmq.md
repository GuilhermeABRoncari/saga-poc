# Integração Laravel ↔ RabbitMQ-PoC — passo a passo

Como adotar a abordagem **RabbitMQ + lib própria** como padrão organizacional em uma aplicação Laravel rodando em Docker Swarm. O exemplo concreto usado ao longo deste guia é um fluxo de criação de pedido com reserva de estoque + cobrança + confirmação, implementado como `ActivateStoreSaga`.

Premissas em vigor:

- Apenas o serviço Laravel principal (`order-service`) migra para Kubernetes inicialmente; demais serviços ficam em Swarm por tempo indeterminado, configurando uma stack híbrida Docker Swarm + Kubernetes.
- RabbitMQ provisionado como serviço compartilhado (Swarm hoje, eventualmente Amazon MQ ou cluster K8s).
- A **lib de orquestração é mantida internamente** (`acme/laravel-saga`), com responsabilidade contínua de manutenção.

> ⚠️ **Aviso**: este caminho foi avaliado e **não é o recomendado** (`docs/recomendacao-saga.md` §7). O teste T5.1 reproduziu silent corruption sob mudança comum de forma da saga. Esta integração permanece documentada como referência caso a decisão seja revisitada.

---

## Fase 0 — Pré-requisitos

1. **RabbitMQ acessível**: se o cluster Swarm já tem RabbitMQ usado por jobs Laravel, reaproveitar. Senão, provisionar 3 nós com mirror queue policy + management plugin.
2. **Repo do pacote interno** `acme/laravel-saga` criado (Packagist privado ou Satis). Esqueleto:

   ```
   laravel-saga/
   ├── composer.json
   ├── src/
   │   ├── ServiceProvider.php
   │   ├── Orchestrator/
   │   │   ├── Orchestrator.php           # core: routing eventos, compensação LIFO
   │   │   ├── SagaState.php              # persistência em DB (saga_states table)
   │   │   ├── SagaRepository.php
   │   │   └── DeadLetterHandler.php
   │   ├── Saga/
   │   │   ├── SagaDefinition.php         # registro de steps + compensações
   │   │   └── StepHandler.php            # contract pros handlers
   │   ├── Console/
   │   │   ├── RunOrchestratorCommand.php
   │   │   └── RunHandlerCommand.php
   │   └── Events/
   │       ├── StepRequested.php
   │       ├── StepCompleted.php
   │       └── StepFailed.php
   └── database/migrations/
       └── 2026_xx_xx_create_saga_states_table.php
   ```

3. **Storage de estado da saga**: tabela `saga_states` no MariaDB principal do `order-service` (ou MariaDB dedicado). Schema mínimo:

   ```sql
   CREATE TABLE saga_states (
     id              CHAR(36) PRIMARY KEY,
     saga_type       VARCHAR(64) NOT NULL,
     status          ENUM('pending','step_running','compensating','completed','failed','dead'),
     current_step    VARCHAR(64),
     payload         JSON,
     compensations   JSON,             -- stack LIFO de compensações pendentes
     attempts        INT DEFAULT 0,
     last_error      TEXT,
     created_at      TIMESTAMP,
     updated_at      TIMESTAMP,
     INDEX (status, updated_at)
   );
   ```

   > Esta tabela é o "history" do RabbitMQ-PoC — equivalente ao que Temporal faz built-in. Cuidado: é o ponto único onde o estado da saga vive; perda dela é perda de saga.

4. **Política de DLQ**: filas `*.failed` por step + worker `dead-letter-handler` que escuta e cria ticket Jira/PagerDuty. Sem isso, mensagens com erro permanente formam loop infinito (item T2.2 dos findings).

---

## Fase 1 — Lib interna: implementar e publicar

Esta é a fase mais densa e a fonte dos 5 itens semi-bloqueantes do PoC. **Antes de instalar no `order-service`, a lib precisa cobrir**:

| Item                                                               | Descoberto em |
| ------------------------------------------------------------------ | ------------- |
| Reconexão automática AMQP com backoff                              | T1.4          |
| Wait-for-ack do publisher (publisher confirms)                     | T2.3          |
| Cobertura completa de falhas (timeout, DB down, network partition) | T2.2          |
| Health-check do storage da saga state                              | T4.2          |
| Timeout configurável por step                                      | T4.4          |
| Mitigação deadlock SQLite/MySQL sob load                           | T6.2          |

API alvo:

```php
// Definição de saga (DSL declarativa)
SagaDefinition::for('activate-store')
    ->step('reserve-stock')
        ->handledBy(OrderHandlers::class . '@reserveStock')
        ->compensatesWith(OrderHandlers::class . '@releaseStock')
        ->timeout(30)
        ->maxAttempts(3)
    ->step('charge-credit')
        ->handledBy(PaymentHandlers::class . '@chargeCredit')
        ->compensatesWith(PaymentHandlers::class . '@refundCredit')
        ->timeout(30)
    ->step('confirm-shipping')
        ->handledBy(OrderHandlers::class . '@confirmShipping')
        ->timeout(30)
    ->register();
```

---

## Fase 2 — Infra do RabbitMQ local (Compose para dev)

Adicionar ao `docker-compose.override.yml` do `order-service`:

```yaml
services:
  rabbitmq:
    image: rabbitmq:3.13-management-alpine
    ports: ["5672:5672", "15672:15672"]
    healthcheck:
      test: ["CMD", "rabbitmq-diagnostics", "ping"]
      interval: 5s
      retries: 20

  saga-orchestrator:
    build:
      context: .
      target: cli
    command: ["php", "artisan", "saga:orchestrator"]
    environment:
      AMQP_HOST: rabbitmq
      AMQP_USER: guest
      AMQP_PASS: guest
      DB_HOST: mysql
    depends_on: [rabbitmq, mysql]

  saga-handler-order:
    build:
      context: .
      target: cli
    command: ["php", "artisan", "saga:handler", "order"]
    environment:
      AMQP_HOST: rabbitmq
      DB_HOST: mysql
    depends_on: [rabbitmq, mysql]
```

> **Observação**: `service-a-worker` + `service-b-worker` do PoC viram `saga-handler-<bounded-context>` no real. No `order-service` provavelmente teremos 1 handler (`order`) consumindo passos do próprio domínio. O `payment-service` terá outro handler (`payment`) — ver Fase 7.

Validação: `docker compose up rabbitmq` + `curl http://localhost:15672` (UI: guest/guest).

---

## Fase 3 — Imagem Docker

Diferente do Temporal, **não precisa de grpc nem RoadRunner** — handlers usam loop AMQP padrão (`php-amqplib`).

`order-service/Dockerfile`:

```dockerfile
FROM php:8.3-cli-alpine AS base
RUN apk add --no-cache git unzip libstdc++ \
 && docker-php-ext-install sockets pcntl pdo_mysql bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-dev
COPY . .
RUN composer dump-autoload -o

FROM base AS api
RUN apk add --no-cache nginx supervisor
CMD ["/usr/bin/supervisord"]

FROM base AS cli
# imagem usada por saga:orchestrator e saga:handler
CMD ["php", "artisan"]
```

CI publica `order-service:1.x.y-api` e `order-service:1.x.y-cli`.

---

## Fase 4 — Pacote interno: instalação no `order-service`

```bash
cd order-service
composer require acme/laravel-saga:^1.0 php-amqplib/php-amqplib:^3.7
php artisan vendor:publish --tag=saga-config --tag=saga-migrations
php artisan migrate
```

`config/saga.php`:

```php
return [
    'connection' => [
        'host' => env('AMQP_HOST', 'rabbitmq'),
        'port' => env('AMQP_PORT', 5672),
        'user' => env('AMQP_USER', 'guest'),
        'pass' => env('AMQP_PASS', 'guest'),
        'vhost' => env('AMQP_VHOST', '/'),
        'heartbeat' => 30,
        'connection_timeout' => 5,
    ],
    'storage' => [
        'driver' => 'mysql',                  // Eloquent SagaState model
        'connection' => 'saga',               // conexão DB dedicada (recomendado)
    ],
    'orchestrator' => [
        'consumer_tag' => 'saga-orchestrator',
        'prefetch' => 10,
    ],
    'sagas' => [
        \App\Sagas\ActivateStoreSaga::class,
    ],
];
```

`.env`:

```ini
AMQP_HOST=rabbitmq.swarm.internal
AMQP_USER=order
AMQP_PASS=...
DB_SAGA_HOST=...
DB_SAGA_DATABASE=saga_states
```

---

## Fase 5 — Refatorar o endpoint de criação de pedido

### 5.1 Definição da saga

```php
// app/Sagas/ActivateStoreSaga.php
namespace App\Sagas;

use Acme\Saga\SagaDefinition;
use App\Sagas\Handlers\OrderHandlers;

class ActivateStoreSaga
{
    public static function definition(): SagaDefinition
    {
        return SagaDefinition::for('activate-store')
            ->step('reserve-stock')
                ->handledBy([OrderHandlers::class, 'reserveStock'])
                ->compensatesWith([OrderHandlers::class, 'releaseStock'])
                ->timeout(30)->maxAttempts(3)
            ->step('charge-credit')
                ->handledBy('payment.chargeCredit')   // routed pra fila payment.requests
                ->compensatesWith('payment.refundCredit')
                ->timeout(30)->maxAttempts(3)
            ->step('confirm-shipping')
                ->handledBy([OrderHandlers::class, 'confirmShipping'])
                ->timeout(30)->maxAttempts(3);
    }
}
```

### 5.2 Controller (dispara saga, retorna 202)

```php
// app/Http/Controllers/OrderController.php
use Acme\Saga\Orchestrator\Orchestrator;

public function activate(int $storeId, Orchestrator $orchestrator)
{
    $sagaId = $orchestrator->start(
        sagaType: 'activate-store',
        payload: ['store_id' => $storeId],
        idempotencyKey: "activate-store-{$storeId}",
    );

    return response()->json([
        'saga_id' => $sagaId,
        'status'  => 'pending',
    ], 202);
}

public function showSaga(string $sagaId)
{
    $state = \Acme\Saga\SagaState::find($sagaId);
    return response()->json([
        'status'       => $state->status,
        'current_step' => $state->current_step,
        'last_error'   => $state->last_error,
    ]);
}
```

> O `Orchestrator::start()` faz: cria registro em `saga_states` + publica primeira mensagem em `saga.activate-store.reserve-stock.requested`. Tudo dentro de uma transação DB+AMQP confirmada (publisher confirms — item T2.3).

### 5.3 Handlers (lógica de cada step)

```php
// app/Sagas/Handlers/OrderHandlers.php
namespace App\Sagas\Handlers;

use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;

class OrderHandlers
{
    public function reserveStock(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $r = StockReservation::firstOrCreate(
                ['idempotency_key' => "activate-store-{$payload['store_id']}"],
                ['store_id' => $payload['store_id']]
            );
            return ['reservation_id' => $r->id];
        });
    }

    public function releaseStock(array $payload, array $stepResult): void
    {
        StockReservation::find($stepResult['reservation_id'])?->release();
    }

    public function confirmShipping(array $payload): array
    {
        return app(ShippingClient::class)->confirm($payload['store_id']);
    }
}
```

> ⚠️ **Idempotência é responsabilidade do handler**, não da lib. Diferente do Temporal (que tem retry com state cacheado), aqui retry republica a mensagem — `reserveStock` vai rodar 2x se network falhou após o commit. Sem `firstOrCreate(idempotency_key)`, dupla reserva.

---

## Fase 6 — Comandos Artisan (orchestrator + handlers) — fornecidos pelo pacote

```bash
# Terminal 1: orquestrador (consome eventos *.completed/*.failed, decide próximo step ou compensação)
php artisan saga:orchestrator

# Terminal 2: handler do bounded context order
php artisan saga:handler order

# (Em outro repo) Terminal 3: handler do bounded context payment
php artisan saga:handler payment
```

Em prod cada um vira um Deployment K8s ou serviço Swarm separado.

---

## Fase 7 — Cross-service (chamada pra `payment-service`)

Diferente do Temporal Opção A (HTTP Activity), aqui a comunicação cross-service **é via fila AMQP** desde o início — caso contrário o orquestrador perde retry/compensação automática.

Isso significa que o `payment-service` precisa instalar a lib também:

```bash
cd payment-service
composer require acme/laravel-saga:^1.0
php artisan vendor:publish --tag=saga-config
```

`payment-service/config/saga.php` registra apenas handlers:

```php
'handlers' => [
    'payment.chargeCredit'  => [\App\Saga\PaymentHandlers::class, 'chargeCredit'],
    'payment.refundCredit'  => [\App\Saga\PaymentHandlers::class, 'refundCredit'],
],
```

`payment-service` roda `php artisan saga:handler payment` num container próprio. Esse handler consome `saga.activate-store.charge-credit.requested` → executa lógica → publica `.completed` ou `.failed`.

> ⚠️ **Acoplamento implícito**: o nome de step `payment.chargeCredit` precisa bater entre `order-service` (definição) e `payment-service` (handler). Mudou um, quebrou silenciosamente o outro. Sem schema registry de eventos, isso é fonte recorrente de incidente — recomenda-se um pacote `acme/saga-contracts` com PHP DTOs versionados compartilhados via Composer.

---

## Fase 8 — Manifestos Kubernetes / Swarm

### 8.1 `order-service` (Kubernetes, primeiro a migrar)

`k8s/order-service/saga-orchestrator-deployment.yaml`:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: order-saga-orchestrator
spec:
  replicas: 2 # pode escalar; idempotência em DB previne dupla decisão
  template:
    spec:
      containers:
        - name: orchestrator
          image: registry.example.com/order-service:1.x.y-cli
          command: ["php", "artisan", "saga:orchestrator"]
          env:
            - { name: AMQP_HOST, value: "rabbitmq.swarm.internal" }
            - name: DB_PASSWORD
              valueFrom:
                { secretKeyRef: { name: order-db, key: password } }
          resources:
            requests: { cpu: "200m", memory: "256Mi" }
            limits: { cpu: "500m", memory: "512Mi" }
          livenessProbe:
            exec: { command: ["php", "artisan", "saga:health"] } # checa AMQP + DB (item T4.2)
            periodSeconds: 30
---
apiVersion: apps/v1
kind: Deployment
metadata: { name: order-saga-handler }
spec:
  replicas: 4 # paralelismo de processamento
  template:
    spec:
      containers:
        - name: handler
          image: registry.example.com/order-service:1.x.y-cli
          command: ["php", "artisan", "saga:handler", "order"]
          # demais campos análogos
```

### 8.2 `payment-service` e demais (Swarm, ainda)

Stack file `payment-service-saga.yml`:

```yaml
version: "3.8"
services:
  saga-handler:
    image: registry.example.com/payment-service:1.x.y-cli
    command: ["php", "artisan", "saga:handler", "payment"]
    deploy:
      replicas: 3
      placement:
        constraints: ["node.role==worker"]
    environment:
      - AMQP_HOST=rabbitmq.swarm.internal
      - DB_PASSWORD_FILE=/run/secrets/payment-db-password
    secrets: [payment-db-password]
```

> **Importante**: handler em Kubernetes (`order`) e handler em Swarm (`payment`) **conversam pela mesma fila RabbitMQ**. RabbitMQ vira o ponto único de integração cross-cluster. Latência cross-cluster (K8s↔Swarm) some na fila, mas RTT direto importa pra ack — colocar RabbitMQ próximo de quem tem mais throughput.

---

## Fase 9 — Observabilidade e operação

Sem o "Temporal UI" pronto, observabilidade precisa ser construída:

1. **Dashboard custom** em Grafana lendo `saga_states`:
   - Distribuição por status (pending / step_running / compensating / completed / failed / dead).
   - p95 de duração por saga_type.
   - Top 10 erros recentes.
2. **Alertas Prometheus**:
   - `saga_states{status="dead"}` > 0 → PagerDuty (compensação falhou — corrupção em potencial).
   - DLQ depth > 10 → alerta time on-call.
   - `saga_states{status="step_running",updated_at < now() - 5m}` > 5 → step travado.
3. **Replay manual**: comando `php artisan saga:replay <saga_id>` lê estado, decide próximo passo correto, republica mensagem. Operação **manual** — não há `tctl workflow reset` pronto.
4. **Runbooks** obrigatórios:
   - `saga-stuck.md`: como reanimar saga em status `step_running` há mais de N min.
   - `dlq-flood.md`: como drenar DLQ sem perder eventos.
   - `state-divergence.md`: o que fazer se `saga_states.status = completed` mas a entidade real está incompleta (cenário T5.1).

---

## Fase 10 — Defesa contra silent corruption (T5.1)

T5.1 mostrou que reordenar steps em deploy faz a saga marcar `completed` com state corrompido (compensação não roda na ordem certa).

Mitigações **manuais** (Temporal evita built-in):

1. **Versionar a definição da saga**: cada `SagaDefinition` tem `version()` incrementada quando muda forma. Sagas em vôo são processadas com a versão sob a qual foram iniciadas (ler `saga_states.definition_version`).
2. **Lint custom PHPStan**: regra que falha CI se uma `SagaDefinition` em produção tiver `step()` removido ou reordenado sem incrementar versão.
3. **Code review obrigatório** em PRs que tocam `app/Sagas/` por 2 reviewers seniores + pinning no CODEOWNERS.

> Esse trabalho **não some** — vira processo permanente. É a fonte do "esquecimento humano cumulativo" citado nas conclusões deste estudo.

---

## Ordem das fases

| Fase                                                      | Quem             | Bloqueia próxima fase? |
| --------------------------------------------------------- | ---------------- | ---------------------- |
| 0. Pré-requisitos (RabbitMQ, repo lib, schema DB)         | DevOps + Backend | Sim |
| 1. **Implementar lib interna (5 itens semi-bloqueantes)** | Backend          | Sim — base de tudo |
| 2. Compose local                                          | Backend          | Sim |
| 3. Dockerfile                                             | Backend          | Sim |
| 4. Instalação do pacote no `order-service`                | Backend          | Sim |
| 5. Refator do endpoint + handlers                         | Backend          | Sim |
| 6. Artisan commands (do pacote)                           | Backend          | Sim |
| 7. Cross-service (por serviço novo)                       | Backend          | Sim quando saga atravessa serviço |
| 8. Manifestos Kubernetes + Swarm                          | DevOps           | Sim para produção |
| 9. Observabilidade custom + runbooks                      | Backend + SRE    | Não — incrementa após primeiro deploy |
| 10. Versionamento + lint anti-T5.1                        | Backend          | Não — manutenção contínua por saga |

Comparado ao Temporal: a lib interna concentra trabalho que o Temporal SDK + engine entregam prontos (durabilidade, retry, replay, signals). Cada novo bounded context exige uma nova rodada das Fases 4-6 — em Temporal, basta adicionar uma Activity nova ao worker.

---

## Checklist mínimo pra dar `git push` da PR de adoção

- [ ] Lib `acme/laravel-saga` v1.0 publicada com os 5 itens semi-bloqueantes resolvidos.
- [ ] RabbitMQ provisionado, acessível de Kubernetes e Swarm, monitorado.
- [ ] Tabela `saga_states` migrada em DB dedicado (não compartilhar com domínio principal).
- [ ] DLQ + dead-letter-handler operacional, com alerta no PagerDuty.
- [ ] `order-saga-orchestrator` ≥2 replicas; `order-saga-handler` ≥3.
- [ ] `payment-saga-handler` ≥2 replicas (Swarm).
- [ ] Endpoint de criação de pedido retorna 202 com `saga_id`.
- [ ] `GET /sagas/{id}` retorna estado consistente com a tabela.
- [ ] Teste E2E: happy path + FORCE_FAIL=step3 verifica compensação completa LIFO.
- [ ] **Teste destrutivo T5.1**: reordenar steps em deploy NÃO deve completar saga sem compensar — confirmar que o versionamento detecta divergência.
- [ ] PHPStan custom rules ativas no CI (mudança de forma → exige bump de versão).
- [ ] Dashboard Grafana "Saga: ActivateStore" ativo.
- [ ] Runbooks publicados; drill executado uma vez (saga travada, DLQ flood).
