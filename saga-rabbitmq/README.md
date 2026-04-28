# saga-rabbitmq PoC

Implementação do workflow de referência (3 passos com compensação LIFO) usando RabbitMQ + esboço da lib `mobilestock/saga`.

## Topologia

```
                        saga.events (todos publicam aqui)
                                    │
                                    ▼
   ┌────────────────────────  orchestrator  ────────────────────────┐
   │                                                                 │
   │  publica em saga.commands.<service>                             │
   │  publica em saga.compensations.<service> em caso de falha       │
   │                                                                 │
   └─────┬─────────────────────────────────────────────┬─────────────┘
         │                                             │
         ▼                                             ▼
  saga.commands.service-a                     saga.commands.service-b
  saga.compensations.service-a                saga.compensations.service-b
         │                                             │
         ▼                                             ▼
     service-a                                     service-b
   (reserve, confirm)                            (charge_credit)
   compensa: release                             compensa: refund
```

Estado da saga em SQLite (`storage/saga.sqlite`), tabela `sagas`.

## Workflow

| Step | Serviço | Ação | Compensação |
|---|---|---|---|
| 0 | service-a | `reserve_stock` | `release_stock` |
| 1 | service-b | `charge_credit` | `refund_credit` |
| 2 | service-a | `confirm_shipping` | — |

Com `FORCE_FAIL=step3`, o passo 2 falha → orquestrador dispara `refund_credit` → `release_stock` (LIFO).

## Como rodar

### Happy path

```bash
docker compose up --build
```

Logs esperados (resumido):

```
orchestrator | [orchestrator] started saga=…
orchestrator | [orchestrator] saga=… → service-a.reserve_stock (step 0)
service-a    |   → ReserveStock: produto=… qty=2 → res_…
orchestrator | [orchestrator] saga=… → service-b.charge_credit (step 1)
service-b    |   → ChargeCredit: user=… amount=199.9 → chg_…
orchestrator | [orchestrator] saga=… → service-a.confirm_shipping (step 2)
service-a    |   → ConfirmShipping: … → BR…
orchestrator | [orchestrator] saga=… COMPLETED
```

### Forçar compensação

```bash
FORCE_FAIL=step3 docker compose up --build
```

Logs esperados:

```
service-a    |   → ReserveStock: …
service-b    |   → ChargeCredit: …
service-a    | saga=… step=2 FAILED: forced failure on confirm_shipping
orchestrator | [orchestrator] saga=… step=2 FAILED → compensating
service-b    |   ← RefundCredit: …
service-a    |   ← ReleaseStock: …
orchestrator | [orchestrator] saga=… COMPENSATED
```

### Reset

```bash
docker compose down -v
rm -f storage/saga.sqlite
```

## Estrutura

```
src/
├── Lib/                       # esboço da mobilestock/saga
│   ├── Saga.php               # base class abstrata
│   ├── Step.php               # value object
│   ├── AmqpTransport.php      # wrapper php-amqplib
│   ├── SagaStateRepository.php # SQLite
│   ├── SagaOrchestrator.php   # state machine + LIFO compensation
│   └── ServiceWorker.php      # consumer base para serviços
├── Sagas/
│   └── ActivateStoreSaga.php  # definição declarativa
└── Handlers/
    ├── ServiceA/              # reserve / release / confirm_shipping
    └── ServiceB/              # charge_credit / refund_credit
bin/
├── orchestrator.php
├── service-a.php
├── service-b.php
└── trigger.php                # dispara nova saga manualmente
```

## Métricas a coletar (referência §3.2)

A preencher após smoke test:

| Critério | Medida |
|---|---|
| LOC happy path (lib + saga + handlers) | _a medir_ |
| LOC compensação | _a medir_ |
| Tempo até primeiro saga rodando | _a medir_ |
| Observabilidade default | _a medir_ |
| Esforço para observabilidade aceitável | _a medir_ |
| DX em code review | _a medir_ |
