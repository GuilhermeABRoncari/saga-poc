# Integração Laravel ↔ AWS Step Functions — passo a passo

Como adotar **AWS Step Functions** como padrão organizacional, partindo de uma stack Laravel + Docker Swarm. O exemplo concreto usado ao longo deste guia é um fluxo de criação de pedido com reserva de estoque + cobrança + confirmação, implementado como `ActivateStoreSaga`.

Premissas em vigor:

- Apenas o serviço Laravel principal (`order-service`) migra para Kubernetes inicialmente; demais serviços ficam em Swarm por tempo indeterminado, configurando uma stack híbrida Docker Swarm + Kubernetes.
- Conta AWS já existente; região alvo `us-east-1` (mesma do cluster Kubernetes).
- Step Functions é serviço **gerenciado AWS** — sem cluster próprio pra operar, mas custo por transição de state e lock-in elevado.

> ⚠️ **Aviso**: este caminho foi avaliado como **não recomendado** (`docs/recomendacao-saga.md` §7 e `findings-step-functions.md`). Vence em zero-ops e durabilidade nativa, mas perde em latência (p99 ~3x Temporal), expressividade (ASL JSON), custo em escala e lock-in AWS. Esta integração existe como referência caso a decisão seja revisitada.

---

## Fase 0 — Pré-requisitos AWS

1. **Conta AWS com Step Functions habilitado** + IAM roles:
   - `OrderSagaStateMachineRole`: assume role do Step Functions, permissions `states:StartExecution`, `states:DescribeExecution`, e ARNs das **Activities** (definidas como AWS Activities, não Lambdas).
   - `OrderSagaWorkerRole`: usado pelos pods que pollam Activities — `states:GetActivityTask`, `states:SendTaskSuccess`, `states:SendTaskFailure`, `states:SendTaskHeartbeat`.

2. **Decisão de execução**: `STANDARD` vs `EXPRESS`:
   - **STANDARD** (recomendado pra saga): execuções até 1 ano, history visível, exactly-once. Custo: $0.025 por 1k transições.
   - **EXPRESS**: até 5 min, sem history persistido, custo bem menor — **inadequado pra compensação** (precisa history pra rastrear).

3. **Provisionamento via Terraform** (recomendado — versionar state machine como código):

   ```
   terraform/
   ├── modules/saga/
   │   ├── activity.tf            # define aws_sfn_activity por step
   │   ├── state-machine.tf       # define aws_sfn_state_machine via templatefile()
   │   ├── iam.tf
   │   └── variables.tf
   └── envs/
       ├── staging/main.tf
       └── prod/main.tf
   ```

4. **Egress + VPC endpoints**: pods do Kubernetes precisam de `com.amazonaws.us-east-1.states` (Step Functions) endpoint pra evitar tráfego pela internet pública. Ou NAT gateway, se aceitarem o custo.

---

## Fase 1 — Infra local (LocalStack)

LocalStack emula Step Functions razoavelmente bem (ver `saga-step-functions/docker-compose.yml` deste PoC). Para dev:

`order-service/docker-compose.override.yml`:

```yaml
services:
  localstack:
    image: localstack/localstack:3.8
    environment:
      SERVICES: stepfunctions,iam,sts
      AWS_DEFAULT_REGION: us-east-1
      AWS_ACCESS_KEY_ID: test
      AWS_SECRET_ACCESS_KEY: test
    ports: ["4566:4566"]
    healthcheck:
      test: ["CMD", "curl", "-sf", "http://localhost:4566/_localstack/health"]
      interval: 5s

  saga-bootstrap:
    build: { context: ., target: cli }
    depends_on: { localstack: { condition: service_healthy } }
    environment:
      AWS_ENDPOINT_URL: http://localstack:4566
      AWS_REGION: us-east-1
      AWS_ACCESS_KEY_ID: test
      AWS_SECRET_ACCESS_KEY: test
    command: ["php", "artisan", "saga:bootstrap"] # cria activities + state machine

  saga-worker-order:
    build: { context: ., target: cli }
    depends_on:
      { saga-bootstrap: { condition: service_completed_successfully } }
    environment:
      AWS_ENDPOINT_URL: http://localstack:4566
      AWS_REGION: us-east-1
      AWS_ACCESS_KEY_ID: test
      AWS_SECRET_ACCESS_KEY: test
      WORKER_NAME: order
    command: ["php", "artisan", "saga:worker", "order"]
```

> **Limitação LocalStack**: sem CloudWatch Metrics realísticos. Testes de carga e cost projection só fazem sentido contra AWS real (staging account).

---

## Fase 2 — Imagem Docker

Step Functions não exige `grpc` nem RoadRunner — basta `aws/aws-sdk-php` e loop polling.

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
CMD ["php", "artisan"]
```

CI publica `order-service:1.x.y-api` e `order-service:1.x.y-cli`.

---

## Fase 3 — Pacote interno + SDK AWS

Diferente das outras opções, aqui o pacote interno é menor — Step Functions cuida do orquestrador. O pacote só padroniza:

- Cliente SFN configurado (endpoint, retries, mTLS opcional).
- Worker base (loop `getActivityTask` + `sendTaskSuccess`/`Failure` + heartbeat).
- Mapeamento de exceptions PHP → ASL `Error`.

```bash
cd order-service
composer require acme/laravel-step-functions:^1.0 aws/aws-sdk-php:^3.336 ramsey/uuid:^4.7
php artisan vendor:publish --tag=sfn-config
```

`config/step-functions.php`:

```php
return [
    'region' => env('AWS_REGION', 'us-east-1'),
    'endpoint' => env('AWS_ENDPOINT_URL'),                   // null em prod
    'state_machines' => [
        'activate-store' => env('SFN_ACTIVATE_STORE_ARN'),
    ],
    'activities' => [
        'reserve-stock'    => env('SFN_ACTIVITY_RESERVE_STOCK_ARN'),
        'release-stock'    => env('SFN_ACTIVITY_RELEASE_STOCK_ARN'),
        'confirm-shipping' => env('SFN_ACTIVITY_CONFIRM_SHIPPING_ARN'),
    ],
    'workers' => [
        'order' => [
            'reserve-stock'    => [\App\Saga\Activities\ReserveStock::class, 'handle'],
            'release-stock'    => [\App\Saga\Activities\ReleaseStock::class, 'handle'],
            'confirm-shipping' => [\App\Saga\Activities\ConfirmShipping::class, 'handle'],
        ],
    ],
    'heartbeat_seconds' => 20,
];
```

`.env`:

```ini
AWS_REGION=us-east-1
SFN_ACTIVATE_STORE_ARN=arn:aws:states:us-east-1:1234:stateMachine:activate-store-prod
SFN_ACTIVITY_RESERVE_STOCK_ARN=arn:aws:states:us-east-1:1234:activity:reserve-stock-prod
# ... demais ARNs vêm do Terraform output
```

---

## Fase 4 — State Machine (Terraform + ASL JSON)

Reaproveitar `saga-step-functions/state-machine.json` deste PoC, substituindo placeholders `__ARN_*__` por interpolação Terraform. Estrutura ASL completa em ASCII:

`terraform/modules/saga/templates/activate-store.json.tpl`:

```json
{
  "Comment": "ActivateStoreSaga - 3 steps com compensação LIFO via Catch",
  "StartAt": "ReserveStock",
  "States": {
    "ReserveStock": {
      "Type": "Task",
      "Resource": "${arn_reserve_stock}",
      "TimeoutSeconds": 60,
      "HeartbeatSeconds": 30,
      "Retry": [
        {
          "ErrorEquals": ["States.TaskFailed"],
          "MaxAttempts": 3,
          "IntervalSeconds": 1,
          "BackoffRate": 2.0
        }
      ],
      "ResultPath": "$.reserve",
      "Next": "ChargeCredit",
      "Catch": [
        {
          "ErrorEquals": ["States.ALL"],
          "ResultPath": "$.error",
          "Next": "FailReserveStock"
        }
      ]
    },
    "ChargeCredit": {
      "Type": "Task",
      "Resource": "${arn_charge_credit}",
      "TimeoutSeconds": 60,
      "ResultPath": "$.charge",
      "Next": "ConfirmShipping",
      "Catch": [
        {
          "ErrorEquals": ["States.ALL"],
          "ResultPath": "$.error",
          "Next": "ReleaseStockOnly"
        }
      ]
    },
    "ConfirmShipping": {
      "Type": "Task",
      "Resource": "${arn_confirm_shipping}",
      "TimeoutSeconds": 60,
      "ResultPath": "$.shipping",
      "Next": "Success",
      "Catch": [
        {
          "ErrorEquals": ["States.ALL"],
          "ResultPath": "$.error",
          "Next": "RefundCredit"
        }
      ]
    },
    "RefundCredit": {
      "Type": "Task",
      "Resource": "${arn_refund_credit}",
      "TimeoutSeconds": 60,
      "ResultPath": "$.refund",
      "Next": "ReleaseStock",
      "Catch": [
        {
          "ErrorEquals": ["States.ALL"],
          "ResultPath": "$.compensationError",
          "Next": "FailCompensation"
        }
      ]
    },
    "ReleaseStockOnly": {
      "Type": "Task",
      "Resource": "${arn_release_stock}",
      "TimeoutSeconds": 60,
      "ResultPath": "$.release",
      "Next": "Compensated",
      "Catch": [
        {
          "ErrorEquals": ["States.ALL"],
          "ResultPath": "$.compensationError",
          "Next": "FailCompensation"
        }
      ]
    },
    "ReleaseStock": {
      "Type": "Task",
      "Resource": "${arn_release_stock}",
      "TimeoutSeconds": 60,
      "ResultPath": "$.release",
      "Next": "Compensated",
      "Catch": [
        {
          "ErrorEquals": ["States.ALL"],
          "ResultPath": "$.compensationError",
          "Next": "FailCompensation"
        }
      ]
    },
    "Compensated": {
      "Type": "Fail",
      "Error": "Compensated",
      "Cause": "Saga compensated successfully"
    },
    "FailReserveStock": {
      "Type": "Fail",
      "Error": "ReserveStockFailed",
      "Cause": "Nothing to compensate"
    },
    "FailCompensation": {
      "Type": "Fail",
      "Error": "CompensationFailed",
      "Cause": "Manual intervention needed"
    },
    "Success": { "Type": "Succeed" }
  }
}
```

`terraform/modules/saga/state-machine.tf`:

```hcl
resource "aws_sfn_activity" "reserve_stock"    { name = "reserve-stock-${var.env}" }
resource "aws_sfn_activity" "release_stock"    { name = "release-stock-${var.env}" }
resource "aws_sfn_activity" "charge_credit"    { name = "charge-credit-${var.env}" }
resource "aws_sfn_activity" "refund_credit"    { name = "refund-credit-${var.env}" }
resource "aws_sfn_activity" "confirm_shipping" { name = "confirm-shipping-${var.env}" }

resource "aws_sfn_state_machine" "activate_store" {
  name     = "activate-store-${var.env}"
  role_arn = aws_iam_role.sfn_state_machine.arn
  type     = "STANDARD"

  definition = templatefile("${path.module}/templates/activate-store.json.tpl", {
    arn_reserve_stock    = aws_sfn_activity.reserve_stock.id
    arn_release_stock    = aws_sfn_activity.release_stock.id
    arn_charge_credit    = aws_sfn_activity.charge_credit.id
    arn_refund_credit    = aws_sfn_activity.refund_credit.id
    arn_confirm_shipping = aws_sfn_activity.confirm_shipping.id
  })

  logging_configuration {
    log_destination        = "${aws_cloudwatch_log_group.sfn.arn}:*"
    include_execution_data = true
    level                  = "ALL"
  }
}
```

> ⚠️ **State machine é imutável quanto a forma — tipo de mudança que demanda nova versão**. AWS suporta versionamento built-in (`version`/`alias`), mas execuções em vôo continuam na versão original. Mudanças de step → bump de versão + alias `live` apontando pra nova → execuções novas pegam a nova, antigas terminam na antiga. Comportamento mais previsível que RabbitMQ-PoC, mas mais rígido que Temporal `Workflow::getVersion()`.

---

## Fase 5 — Refatorar o endpoint de criação de pedido

### 5.1 Antes

Mesmo código síncrono mostrado em `integracao-temporal.md` §4.1.

### 5.2 Depois — controller dispara execução

```php
// app/Http/Controllers/OrderController.php
use Aws\Sfn\SfnClient;
use Ramsey\Uuid\Uuid;

public function activate(int $storeId, SfnClient $sfn)
{
    $executionName = "activate-store-{$storeId}-" . Uuid::uuid4()->toString();

    $result = $sfn->startExecution([
        'stateMachineArn' => config('step-functions.state_machines.activate-store'),
        'name'  => $executionName,                                  // dedupe limitado: 90 dias retention
        'input' => json_encode([
            'storeId' => $storeId,
            'idempotencyKey' => "activate-store-{$storeId}",        // handlers usam pra dedupe real
        ]),
    ]);

    return response()->json([
        'saga_id'       => $result['executionArn'],
        'execution_name' => $executionName,
        'status'        => 'pending',
    ], 202);
}

public function showSaga(string $executionArn, SfnClient $sfn)
{
    $r = $sfn->describeExecution(['executionArn' => $executionArn]);
    return response()->json([
        'status' => $r['status'],                                    // RUNNING|SUCCEEDED|FAILED|TIMED_OUT|ABORTED
        'started_at' => $r['startDate']->format(DATE_ATOM),
        'output' => isset($r['output']) ? json_decode($r['output'], true) : null,
    ]);
}
```

> **Diferença prática vs Temporal**: Step Functions identifica execução por **ARN**, não por nome semântico. Para `activate-store-{id}` virar identificador único reutilizável, é preciso passar `name` no `startExecution()` — mas AWS deduplica esse nome por **apenas 90 dias**. Após isso, mesmo nome pode reaparecer. Em Temporal, `WorkflowId` é único pra sempre.

### 5.3 Activity handlers (loop polling)

```php
// app/Saga/Activities/ReserveStock.php
namespace App\Saga\Activities;

use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;

class ReserveStock
{
    public function handle(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $r = StockReservation::firstOrCreate(
                ['idempotency_key' => $input['idempotencyKey']],
                ['store_id' => $input['storeId']]
            );
            return ['reservation_id' => $r->id];
        });
    }
}
```

> **Idempotência é responsabilidade do handler**: workers fazem `getActivityTask` + processam + `sendTaskSuccess`. Se worker morre antes do `SendTaskSuccess`, Step Functions reentrega (após `HeartbeatSeconds` ou timeout). Sem idempotency_key, dupla reserva.

### 5.4 Worker (Artisan command, fornecido pelo pacote)

```php
// vendor/acme/laravel-step-functions/src/Console/RunWorkerCommand.php
class RunWorkerCommand extends Command
{
    protected $signature = 'saga:worker {pool}';

    public function handle(SfnClient $sfn)
    {
        $pool = config("step-functions.workers.{$this->argument('pool')}");
        $workerName = gethostname() . '-' . getmypid();

        while (true) {
            foreach ($pool as $activityName => $handler) {
                $arn = config("step-functions.activities.{$activityName}");

                // Long poll (até 65s)
                $task = $sfn->getActivityTask([
                    'activityArn' => $arn,
                    'workerName'  => $workerName,
                ]);

                if (empty($task['taskToken'])) continue;

                try {
                    $input = json_decode($task['input'], true);
                    $result = app($handler[0])->{$handler[1]}($input);

                    $sfn->sendTaskSuccess([
                        'taskToken' => $task['taskToken'],
                        'output'    => json_encode($result),
                    ]);
                } catch (\Throwable $e) {
                    $sfn->sendTaskFailure([
                        'taskToken' => $task['taskToken'],
                        'error'     => substr(class_basename($e), 0, 256),
                        'cause'     => substr($e->getMessage(), 0, 32768),
                    ]);
                }
            }
        }
    }
}
```

Local: `php artisan saga:worker order`.

---

## Fase 6 — Manifestos Kubernetes (workers)

`k8s/order-service/saga-worker-deployment.yaml`:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: order-saga-worker
  namespace: order
spec:
  replicas: 5 # workers fazem long-poll bloqueante; 1 worker = 1 task em paralelo
  template:
    metadata:
      labels: { app: order-saga-worker }
    spec:
      serviceAccountName: order-saga-worker # IRSA → OrderSagaWorkerRole
      containers:
        - name: worker
          image: registry.example.com/order-service:1.x.y-cli
          command: ["php", "artisan", "saga:worker", "order"]
          env:
            - { name: AWS_REGION, value: "us-east-1" }
            - name: SFN_ACTIVITY_RESERVE_STOCK_ARN
              valueFrom:
                { configMapKeyRef: { name: saga-arns, key: reserve-stock } }
            # demais ARNs idem
          resources:
            requests: { cpu: "100m", memory: "128Mi" }
            limits: { cpu: "500m", memory: "512Mi" }
          livenessProbe:
            exec: { command: ["pgrep", "-f", "saga:worker"] }
            periodSeconds: 30
```

ServiceAccount com IRSA:

```yaml
apiVersion: v1
kind: ServiceAccount
metadata:
  name: order-saga-worker
  namespace: order
  annotations:
    eks.amazonaws.com/role-arn: arn:aws:iam::1234:role/OrderSagaWorkerRole
```

> **Importante**: `replicas` precisa ser dimensionado pelo **paralelismo desejado de tasks**, não por CPU/RAM. Cada worker pollando uma activity tem **uma task em vôo por vez**. Para suportar 50 ativações simultâneas com 3 activities cada, são ~50 workers (na prática ~15-20 com latência de polling). HPA por CPU é inútil aqui — `task_schedule_to_start_latency` do CloudWatch é o sinal correto.

---

## Fase 7 — Cross-service (chamada pra `payment-service`)

Mesma estrutura: o `payment-service` instala `acme/laravel-step-functions`, registra handlers de `charge-credit` e `refund-credit`, roda worker próprio.

`payment-service/config/step-functions.php`:

```php
'workers' => [
    'payment' => [
        'charge-credit' => [\App\Saga\ChargeCredit::class, 'handle'],
        'refund-credit' => [\App\Saga\RefundCredit::class, 'handle'],
    ],
],
```

`payment-service` Deployment (Swarm, ainda):

```yaml
version: "3.8"
services:
  saga-worker:
    image: registry.example.com/payment-service:1.x.y-cli
    command: ["php", "artisan", "saga:worker", "payment"]
    deploy:
      replicas: 5
    environment:
      AWS_REGION: us-east-1
      AWS_ACCESS_KEY_ID_FILE: /run/secrets/payment-saga-aws-key
      AWS_SECRET_ACCESS_KEY_FILE: /run/secrets/payment-saga-aws-secret
      SFN_ACTIVITY_CHARGE_CREDIT_ARN: arn:aws:states:us-east-1:1234:activity:charge-credit-prod
      SFN_ACTIVITY_REFUND_CREDIT_ARN: arn:aws:states:us-east-1:1234:activity:refund-credit-prod
    secrets: [payment-saga-aws-key, payment-saga-aws-secret]
```

> **Atenção**: Swarm não tem IRSA — workers em Swarm precisam de **IAM user fixo** com chaves estáticas em Secrets do Swarm. Risco de chave vazada permanente. Mitigação: rotação trimestral via SecretsManager + redeploy automático.

---

## Fase 8 — Observabilidade e operação

CloudWatch dá observabilidade out-of-the-box, mas precisa integração com Datadog se a equipe não quiser usar Console AWS:

1. **CloudWatch → Datadog**: integração nativa via `AWS Integration` plugin. Métricas:
   - `AWS/States.ExecutionsStarted/Succeeded/Failed/Aborted/TimedOut`.
   - `AWS/States.ExecutionTime` (p50/p95/p99 por state machine).
   - `AWS/States.ActivityScheduleToStartTime` (sinal-chave: workers atrasando).
2. **AWS X-Ray** habilitado na state machine: trace distribuído visível na Console.
3. **Step Functions Console**: já tem visualização gráfica de cada execução com clique-para-ver-input/output. **É a única ferramenta visual que vem pronta** entre as três opções comparadas.
4. **Alertas CloudWatch**:
   - `ExecutionsFailed{StateMachineArn=activate-store-prod}` > 5 em 5min → PagerDuty.
   - Activity `release-stock` failure > 0 → alerta crítico (compensação falhou).
5. **Replay manual**: `aws stepfunctions redrive-execution --execution-arn ...` re-executa a partir do último step com falha (feature relativamente nova).

---

## Fase 9 — Cost watch (item específico desta opção) (contínuo)

Step Functions cobra **por transição de state**. ActivateStoreSaga típica = 3 transições (sucesso) ou até 5 (compensação). Custo bruto:

| Volume         | Transições/mês | Custo SFN (STANDARD) |
| -------------- | -------------- | -------------------- |
| 100 sagas/dia  | ~9k            | ~$0.23               |
| 10k sagas/dia  | ~900k          | ~$22                 |
| 100k sagas/dia | ~9M            | ~$225                |
| 1M sagas/dia   | ~90M           | ~$2.250              |

A múltiplos serviços × M sagas/dia em escala, custo SFN supera self-host Temporal em Kubernetes rapidamente. Adicionar dashboard custom em Datadog/AWS Cost Explorer com alerta em $X/mês é obrigatório.

> **Lock-in nota**: state machines são definidas em ASL JSON proprietária. Migrar pra Temporal/RabbitMQ depois exige reescrever orquestração + versionamento. Custo de saída é alto e cresce com o número de sagas — é o item-chave que ranqueou Step Functions abaixo de Temporal em `recomendacao-saga.md` §5.

---

## Ordem das fases

| Fase                                                | Quem             |
| --------------------------------------------------- | ---------------- |
| 0. AWS account + IAM + Terraform setup              | DevOps           |
| 1. LocalStack para dev                              | Backend          |
| 2. Dockerfile                                       | Backend          |
| 3. Pacote interno + SDK AWS                         | Backend          |
| 4. State machine ASL + Terraform                    | DevOps + Backend |
| 5. Refator do endpoint + handlers                   | Backend          |
| 6. Manifestos Kubernetes workers (IRSA)             | DevOps           |
| 7. Cross-service (por serviço)                      | Backend          |
| 8. Observabilidade (CloudWatch → Datadog + alertas) | SRE              |
| 9. Cost dashboard + budget alerts                   | SRE              |

Comparado ao Temporal: a economia em "lib interna não precisa existir" é compensada por Terraform/IAM/IRSA mais complexos e cost engineering contínuo (transições ASL custam por evento — em volume alto isso vira o decisor).

---

## Checklist mínimo pra dar `git push` da PR de adoção

- [ ] State machine `activate-store-prod` criada via Terraform (não Console).
- [ ] Activities ARNs publicados como ConfigMap/Secret pros workers.
- [ ] IRSA configurada para workers Kubernetes; chaves IAM estáticas para workers Swarm em Secret rotacionável.
- [ ] CloudWatch Logs habilitado (`level: ALL`) e integrado a Datadog.
- [ ] X-Ray habilitado.
- [ ] `order-saga-worker` ≥5 replicas (calibrado por carga esperada, não CPU).
- [ ] `payment-saga-worker` ≥3 replicas (Swarm).
- [ ] Endpoint de criação de pedido retorna 202 com `executionArn`.
- [ ] `GET /sagas/{arn}` retorna status correto.
- [ ] Teste E2E: happy path + FORCE_FAIL=step3 verifica compensação completa.
- [ ] Budget alert configurado em $X/mês com PagerDuty.
- [ ] Runbook publicado: como usar `redrive-execution`, como inspecionar via Console, como atualizar state machine sem quebrar execuções em vôo (alias/version).
- [ ] Disaster scenario: AWS region down → onde estado está? (resposta: CloudWatch + execution history; sem replicação cross-region built-in).
