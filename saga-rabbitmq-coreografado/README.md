# PoC SAGA — RabbitMQ Coreografado

> 4ª PoC do estudo. Construída em 2026-04-30.

## Modelo

**Coreografia pura** — não há orquestrador, não há tabela central de saga, não há `saga_definition`. Cada serviço:

1. **Reage a um evento de domínio** que assina (ex.: `service-b` reage a `stock.reserved`).
2. **Executa a ação local** correspondente.
3. **Publica um novo evento** com o resultado (ex.: `credit.charged`) — que dispara o próximo serviço.
4. Em caso de falha em qualquer step, publica `saga.failed`.
5. Cada serviço também **assina `saga.failed`** e roda a compensação local **se aplicável**, **idempotente** via dedup-key local.

## Fluxo do workflow de referência

```
trigger ────saga.started────┐
                            ▼
                       service-a
                       reserveStock
                            │
                            ├──stock.reserved───┐
                            │                   ▼
                            │              service-b
                            │              chargeCredit
                            │                   │
                            │              credit.charged
                            │                   │
                            ▼                   │
                       service-a ◄──────────────┘
                       confirmShipping
                            │
                            ├──saga.completed (sucesso)
                            │
                            └──saga.failed (qualquer falha)
                                       │
                       ┌───────────────┴────────────────┐
                       ▼                                ▼
                 service-a                        service-b
                 ReleaseStockComp                 RefundCreditComp
                 (idempotente, dedup local)       (idempotente, dedup local)
```

## Estrutura

```
saga-rabbitmq-coreografado/
├── docker-compose.yml      # rabbitmq + 2 serviços
├── Dockerfile              # PHP 8.3 + sqlite + amqplib
├── composer.json
├── bin/
│   ├── service-a.php       # workers do serviço A
│   ├── service-b.php       # workers do serviço B
│   ├── trigger.php         # dispara saga.started
│   ├── batch-trigger.php   # dispara N sagas em batch
│   └── p99-bench.php       # bench de latência sequencial
└── src/
    ├── Lib/                # 357 LOC de lib (vs 381 da PoC orquestrada)
    │   ├── EventBus.php             # topic exchange RabbitMQ + reconnect
    │   ├── SagaLog.php              # SQLite local: step_log + compensation_log
    │   └── SagaListener.php         # API fluente react()/compensate()/listen()
    └── Handlers/
        ├── ServiceA/
        │   ├── ReserveStockHandler.php
        │   ├── ConfirmShippingHandler.php
        │   └── ReleaseStockCompensation.php
        └── ServiceB/
            ├── ChargeCreditHandler.php
            └── RefundCreditCompensation.php
```

**Sem `saga_states`, `saga_steps`, `saga_definition`, `saga_version`, sem orchestrator.** Cada serviço só conhece os eventos que reage e os eventos que publica.

## API pública da lib

A lib expõe três classes; o dev de aplicação interage diretamente com `SagaListener`. As outras duas são detalhe de infra — instanciadas uma vez e injetadas.

| Classe         | Responsabilidade                                                                                                            | API que o dev usa                            |
| -------------- | --------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------- |
| `EventBus`     | Conexão AMQP, publish/subscribe em topic exchange, reconnect com backoff exponencial.                                       | `new EventBus(host, port, user, pass)`       |
| `SagaLog`      | Persistência local SQLite com duas tabelas: `step_log` (rastreia o que cada serviço executou) e `compensation_log` (dedup). | `new SagaLog(path)`                          |
| `SagaListener` | API fluente para registrar handlers e compensações; intercepta exceptions e publica `saga.failed` automaticamente.          | `react()` / `compensate()` / `listen(queue)` |

### Como o dev sinaliza falha

**Não há `failed()` explícito a chamar — qualquer `\Throwable` lançado pelo handler é capturado pela lib e republicado como `saga.failed`** com `failed_step`, `service`, `error` e `original_payload`. Isso é a forma mais idiomática em PHP: o handler escreve código normal; se algo der errado (regra de negócio, exception técnica), só lança exception. A lib cuida do resto.

```php
// Handler reativa: lança exception se algo falhar.
final class ChargeCreditHandler
{
    public function __invoke(string $sagaId, array $payload): array
    {
        if ($payload['amount'] <= 0) {
            throw new \DomainException('amount must be positive');
        }
        // … cobrança …
        return ['charge_id' => $chargeId];
    }
}
```

### Como o dev escreve a compensação

```php
// Compensação: idempotente, recebe o payload da falha.
final class RefundCreditCompensation
{
    public function __invoke(string $sagaId, array $failurePayload): void
    {
        // dedup automática pelo SagaLog — só roda se charge_credit foi feito
        // E ainda não foi compensado. Você implementa o efeito; lib trata o resto.
        // … estorno …
    }
}
```

## Adicionando uma saga nova

Suponha que você queira adicionar um fluxo simples de "criar usuário" com dois passos: (1) reservar e-mail no `service-x`, (2) criar credenciais no `service-y`. Em caso de falha no passo 2, libera o e-mail.

**1. Crie os handlers de step (em cada serviço):**

```php
// service-x: src/Handlers/ServiceX/ReserveEmailHandler.php
namespace App\Handlers\ServiceX;

final class ReserveEmailHandler
{
    public function __invoke(string $sagaId, array $payload): array
    {
        // efeito: reserva o e-mail no DB local (idempotente via UPSERT)
        $reserved = $this->emailRepo->reserve($payload['email']);
        if (!$reserved) {
            throw new \DomainException('email already taken');
        }
        return ['email' => $payload['email']];
    }
}

// service-y: src/Handlers/ServiceY/CreateCredentialsHandler.php
final class CreateCredentialsHandler
{
    public function __invoke(string $sagaId, array $payload): array
    {
        $credentialId = $this->credentialRepo->create($payload['email'], $payload['password']);
        return ['credential_id' => $credentialId];
    }
}
```

**2. Crie a compensação no serviço que precisa reverter:**

```php
// service-x: src/Handlers/ServiceX/ReleaseEmailCompensation.php
final class ReleaseEmailCompensation
{
    public function __invoke(string $sagaId, array $failurePayload): void
    {
        // a lib só vai chamar isso se ReserveEmail tiver rodado para essa saga
        // E a compensação ainda não tiver sido aplicada (dedup automática).
        $this->emailRepo->release($failurePayload['original_payload']['email']);
    }
}
```

**3. Wire-up no `bin/service-x.php` (worker do serviço):**

```php
$bus = new EventBus(host: $_ENV['AMQP_HOST'], /* … */);
$log = new SagaLog($_ENV['SAGA_DB']);

(new SagaListener('service-x', $bus, $log))
    ->react(
        event: 'user.signup.requested',     // evento de entrada
        stepName: 'reserve_email',          // chave canônica do step
        emit: 'email.reserved',             // evento publicado em sucesso
        handler: new ReserveEmailHandler(),
    )
    ->compensate('reserve_email', new ReleaseEmailCompensation())
    ->listen('service-x.user-signup');      // nome da queue local
```

**4. Wire-up no `bin/service-y.php`:**

```php
(new SagaListener('service-y', $bus, $log))
    ->react(
        event: 'email.reserved',
        stepName: 'create_credentials',
        emit: 'user.signup.completed',
        handler: new CreateCredentialsHandler(),
    )
    ->listen('service-y.user-signup');
```

**5. Disparar a saga (de qualquer lugar):**

```php
$bus->publish('user.signup.requested', uuid_v4(), [
    'email' => 'foo@bar.com',
    'password' => 'hashed',
]);
```

Pronto. Em caso de falha em `create_credentials`, o `SagaListener` no `service-y` captura a exception, publica `saga.failed`, e o `service-x` (que assina `saga.failed` automaticamente) executa `ReleaseEmailCompensation` se tiver registrado a compensação para `reserve_email`.

**Pontos não-óbvios que valem mencionar:**

- Cada job (handler ou compensação) **inclui o nome da saga em si** via `stepName` e via os eventos publicados — pré-requisito para correlação cross-service. A lib força isso.
- A idempotência é responsabilidade do handler, **mas a dedup do dispatch é da lib** (`step_log` evita execução dupla; `compensation_log` evita compensação dupla).
- Não há registry central de sagas — adicionar uma saga nova é só registrar `react()` em quem precisa. Sem migration de schema, sem deploy coordenado.

## Como rodar

```bash
docker compose up --build -d
docker compose logs -f service-a service-b   # em outro terminal

# Happy path
docker compose run --rm service-a php bin/trigger.php

# Compensação por falha em service-a (step 3)
FORCE_FAIL=step3 docker compose up -d --force-recreate
docker compose run --rm service-a php bin/trigger.php
# esperado: saga.failed → ReleaseStock + RefundCredit publicados, ambos idempotentes

# Compensação por falha em service-b (step 2)
FORCE_FAIL=step2 docker compose up -d --force-recreate
docker compose run --rm service-a php bin/trigger.php
# esperado: saga.failed → ReleaseStock executa (RefundCredit pula — não houve charge)
```

## Diferenças vs `saga-rabbitmq/` (orquestrado)

| Aspecto               | Orquestrado (`saga-rabbitmq/`)           | Coreografado (este)                              |
| --------------------- | ---------------------------------------- | ------------------------------------------------ |
| Componente central    | `orchestrator.php` rodando               | nenhum                                           |
| Tabela de saga        | `saga_states`, `saga_steps` (sqlite)     | `compensation_log` local em cada serviço         |
| `saga_definition`     | sim (`ActivateStoreSaga`)                | não — cada serviço só conhece seu pedaço         |
| LOC da lib            | 381 (6 arquivos)                         | ~150 (3 arquivos)                                |
| Versionamento de saga | `saga_version` + bump manual obrigatório | n/a — sem definição central                      |
| Compensação           | LIFO disparada pelo orquestrador         | fanout — cada serviço decide localmente          |
| Idempotência          | precisa em handlers + na lib             | precisa em handlers + dedup-key local automática |
| Postmortem            | timeline central na tabela `saga_steps`  | correlation-id + logs distribuídos               |

## Testes Tier 1-6 re-projetados

Ver [`docs/checklist-testes-coreografia.md`](../docs/checklist-testes-coreografia.md) — matriz Tier 1-6 + Tier C (testes específicos do modelo).

Sumário:

- ❌ Não se aplicam: T1.1 (versionamento), T5.1 (reordenar steps), T5.2 (mudar shape) — não há definição central.
- ⚠️ Adaptados: T2.2 (idempotência sob retry), T3.4 (postmortem distribuído).
- ✅ Mantidos: T1.4 (broker caído), T1.3 (concorrência).
- ➕ Novos: ordering parcial, handler perdido, loop de eventos.
