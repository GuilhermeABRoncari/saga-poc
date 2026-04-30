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
│   └── trigger.php         # dispara saga.started
└── src/
    ├── Lib/                # ~150 LOC de lib (vs 381 da PoC orquestrada)
    │   ├── EventBus.php             # topic exchange RabbitMQ
    │   ├── CompensationLog.php      # SQLite local p/ dedup
    │   └── SagaListener.php         # detecta exception → publica saga.failed
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

Ver [`docs/checklist-testes-coreografia.md`](../docs/checklist-testes-coreografia.md) (a criar).

Sumário:

- ❌ Não se aplicam: T1.1 (versionamento), T5.1 (reordenar steps), T5.2 (mudar shape) — não há definição central.
- ⚠️ Adaptados: T2.2 (idempotência sob retry), T3.4 (postmortem distribuído).
- ✅ Mantidos: T1.4 (broker caído), T1.3 (concorrência).
- ➕ Novos: ordering parcial, handler perdido, loop de eventos.
