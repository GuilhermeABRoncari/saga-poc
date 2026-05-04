# Integração Laravel ↔ RabbitMQ coreografado — passo a passo

Como adotar a abordagem **RabbitMQ + lib coreografada** como padrão organizacional em uma aplicação Laravel rodando em Docker Swarm. Documento simétrico a [`integracao-rabbitmq.md`](./integracao-rabbitmq.md) (orquestrado), [`integracao-temporal.md`](./integracao-temporal.md) e [`integracao-step-functions.md`](./integracao-step-functions.md), mas para o modelo **coreografado** validado em [`saga-rabbitmq-coreografado/`](../saga-rabbitmq-coreografado/).

O exemplo concreto usado ao longo deste guia é um fluxo de criação de pedido com reserva de estoque + cobrança + confirmação, implementado como uma cadeia de eventos: `saga.started → stock.reserved → credit.charged → saga.completed`.

Premissas em vigor:

- Apenas o serviço Laravel principal (`order-service`) migra para Kubernetes inicialmente; demais serviços ficam em Swarm por tempo indeterminado, configurando uma stack híbrida Docker Swarm + Kubernetes.
- RabbitMQ provisionado como serviço compartilhado (Swarm hoje, eventualmente Amazon MQ ou cluster K8s com quorum queues — ver Fase 6).
- A **lib é mantida internamente** (`acme/laravel-saga`) — a mesma lib do guia orquestrado, mas v1 implementa apenas o modo coreografado conforme a decisão de design registrada em [`recomendacao-saga.md`](./recomendacao-saga.md) §5.1.
- Cada serviço Laravel tem seu próprio banco (MariaDB do `order-service`, MariaDB do `payment-service`). A lib usa esse banco para `step_log` e `compensation_log` locais — não há banco dedicado.

---

## Fase 0 — Pré-requisitos (1-2 dias)

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
   │   └── Console/RunWorkerCommand.php
   ├── config/saga.php
   └── database/migrations/
       └── xxxx_xx_xx_create_saga_log_tables.php
   ```

3. **Convenção de nomes de evento** combinada com o time:
   - Domínio: `<bounded-context>.<event>` (ex.: `stock.reserved`, `credit.charged`, `email.verified`).
   - Falha: `saga.failed` (fanout para todos os consumers que assinam).
   - Sucesso terminal: `saga.completed` (informativo; consumido apenas por aggregator/observabilidade).

---

## Fase 1 — Infra do RabbitMQ local (Compose para dev) (1 dia)

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

## Fase 2 — Imagem Docker do Laravel (1 dia)

A imagem atual do `order-service` (php-fpm) não consegue rodar workers AMQP — falta a extensão `sockets` e o worker precisa ser long-running com loop próprio.

**Estratégia**: Dockerfile multi-stage com duas tags — `api` (php-fpm/nginx para HTTP) e `cli` (CLI long-running para workers).

`order-service/Dockerfile`:

```dockerfile
FROM php:8.3-fpm-alpine AS base
RUN apk add --no-cache git unzip $PHPIZE_DEPS \
 && docker-php-ext-install sockets pdo_mysql bcmath \
 && apk del $PHPIZE_DEPS

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
CMD ["php", "artisan", "saga:listen"]
```

CI publica `order-service:1.x.y-api` e `order-service:1.x.y-cli`.

---

## Fase 3 — Instalação do pacote no `order-service` (1 dia)

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

## Fase 4 — Definir os handlers da primeira saga (2-3 dias)

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
    $sagaId = (string) Str::uuid();
    $bus->publish('saga.started.create_order', $sagaId, [
        'items' => $request->input('items'),
        'payment' => $request->input('payment'),
        'user_id' => $request->user()->id,
    ]);
    return response()->json(['saga_id' => $sagaId], 202);
}
```

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

### 4.4 Wire-up no comando de worker

```php
// vendor/acme/laravel-saga/src/Console/RunWorkerCommand.php (publicado)
public function handle(EventBus $bus, SagaLog $log): int
{
    (new SagaListener(config('saga.service_name'), $bus, $log))
        ->react(
            event: 'saga.started.create_order',
            stepName: 'reserve_stock',
            emit: 'stock.reserved',
            handler: app(ReserveStockHandler::class),
        )
        ->react(
            event: 'credit.charged',
            stepName: 'confirm_shipping',
            emit: 'saga.completed.create_order',
            handler: app(ConfirmShippingHandler::class),
        )
        ->compensate('reserve_stock', app(ReleaseStockCompensation::class))
        ->listen(config('saga.queue_name'));
    return self::SUCCESS;
}
```

Validação: rodar `php artisan saga:listen` em foreground + abrir Management UI em http://localhost:15672 — bindings e queue durável aparecem. Disparar saga via `curl -X POST /orders` deve persistir step_log no banco do `order-service`.

---

## Fase 5 — Replicar wire-up no segundo serviço (`payment-service`) (1 dia)

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

Wire-up dos handlers do `payment-service`:

```php
(new SagaListener('payment-service', $bus, $log))
    ->react(
        event: 'stock.reserved',
        stepName: 'charge_credit',
        emit: 'credit.charged',
        handler: app(ChargeCreditHandler::class),
    )
    ->compensate('charge_credit', app(RefundCreditCompensation::class))
    ->listen('payment-service.saga');
```

> **Acoplamento por convenção, não por tipo:** os nomes de evento (`stock.reserved`, `credit.charged`) são contratos implícitos entre serviços. Para reduzir risco de quebra silenciosa, recomenda-se adicionalmente um pacote `acme/saga-contracts` com **PHP DTOs versionados** compartilhados via Composer (mesma lógica do orquestrado, ver `integracao-rabbitmq.md` Fase 7).

---

## Fase 6 — Deploy em produção (Kubernetes) (2-3 dias)

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

## Fase 7 — Saga Aggregator + UI de postmortem (5-7 dias) — opcional na v1

A coreografia tem postmortem distribuído: cada serviço tem seu `step_log` local. Para incidente em produção, juntar logs por `saga_id` em N serviços leva 2-15 min sem ferramenta dedicada (medição em T3.4). A solução madura é o **Saga Aggregator** — um microsserviço que assina `saga.#` (topic wildcard) e popula uma `saga_view` desnormalizada, sobre a qual roda uma UI Filament/Livewire.

Plano técnico completo (schema, lógica do consumer, custo) está em [`consideracoes.md`](./consideracoes.md) §7.

**Quando construir:**

- Se volume esperado for alto (≥ 1k sagas/dia) e postmortem manual virar gargalo.
- Se compliance/auditoria exigir audit trail unificado.
- **Não construa antes de 3 sagas reais em produção** — YAGNI: pode ser que postmortem manual via `grep` correlacionando `saga_id` seja suficiente para o volume real.

---

## Fase 8 — Migração de fluxos existentes (1-2 semanas)

Para cada fluxo síncrono que vira saga:

1. **Identifique steps e compensações.** Mapeie o fluxo atual; cada `try/catch + rollback` é candidato a step + compensação.
2. **Defina contratos de evento.** `<context>.<event>` para sucesso, exception lançada para falha (a lib publica `saga.failed` automaticamente).
3. **Implemente os handlers em cada serviço dono do contexto.** Idempotência local: cada handler precisa ser seguro a ser executado duas vezes com o mesmo `sagaId`.
4. **Implemente as compensações.** Idem idempotência. A lib trata dedup via `compensation_log`, mas o efeito do handler precisa ser idempotente também.
5. **Adicione novos `react()` ao worker** sem reiniciar o broker — basta restart do container worker, queues durável retêm mensagens em voo.
6. **Sunset do código síncrono.** Manter feature flag por algumas semanas para alternar entre síncrono e saga durante transição.

**Não tente migrar fluxos com 8+ steps de uma vez.** A lib coreografada favorece fluxos curtos (≤ 3-4 steps); fluxos longos viram spaghetti de eventos. Para esses, considere alternativas — orquestração centralizada (v2 da lib, se vier) ou ferramenta dedicada (Temporal). Discussão completa em [`recomendacao-saga.md`](./recomendacao-saga.md) §4.

---

## Custos consolidados

| Fase                                                     | Custo (dias eng) |
| -------------------------------------------------------- | ---------------- |
| 0 — Pré-requisitos (RabbitMQ + lib esqueleto)            | 1-2              |
| 1 — Infra Compose local                                  | 1                |
| 2 — Dockerfile multi-stage api/cli                       | 1                |
| 3 — Instalação no `order-service` + migrations           | 1                |
| 4 — Refatorar primeiro fluxo para coreografia            | 2-3              |
| 5 — Replicar no `payment-service`                        | 1                |
| 6 — Deploy K8s (workers + secrets + HPA opcional)        | 2-3              |
| 7 — Saga Aggregator (opcional, recomendado em prod real) | 5-7              |
| 8 — Migração incremental de fluxos                       | 1-2 semanas/saga |
| **Total para entrega de 1 saga em produção**             | **~10-15 dias**  |
| **Adoção em escala (5-10 sagas + aggregator + DLQ)**     | **~6-8 semanas** |

> Estimativas otimistas: assumem time com expertise prévia em PHP/Laravel/Docker/RabbitMQ. Sem expertise prévia em RabbitMQ, somar ~1 semana de onboarding.

---

## Checklist de adoção

- [ ] Lib `acme/laravel-saga` v1.0 publicada (modo coreografado apenas, conforme decisão registrada em `recomendacao-saga.md` §5.1).
- [ ] RabbitMQ 4.3+ provisionado em dev/staging/prod.
- [ ] Convenção de nomes de evento documentada e revisada com squads.
- [ ] Migrations `saga_step_log` e `saga_compensation_log` aplicadas no banco de cada serviço participante.
- [ ] Dockerfile multi-stage publicando tags `-api` e `-cli`.
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

## Referências internas

- [`saga-rabbitmq-coreografado/README.md`](../saga-rabbitmq-coreografado/README.md) — PoC viva com smoke tests e exemplo de uso.
- [`docs/findings-rabbitmq-coreografado.md`](./findings-rabbitmq-coreografado.md) — medições empíricas Tier 1-6.
- [`docs/checklist-testes-coreografia.md`](./checklist-testes-coreografia.md) — matriz de testes adaptados ao modelo.
- [`docs/recomendacao-saga.md`](./recomendacao-saga.md) §4 — árvore de decisão por cenário.
- [`docs/recomendacao-saga.md`](./recomendacao-saga.md) §5.1 — decisão de design da lib (v1 coreografado, v2 orquestrado se gatilho disparar).
- [`docs/consideracoes.md`](./consideracoes.md) §7 — plano técnico do Saga Aggregator.
