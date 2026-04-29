# saga-step-functions

PoC com **AWS Step Functions** rodando em **LocalStack** + workers PHP poll-based como Activities.

## Arquitetura

```
┌──────────────────┐    ┌────────────────────┐
│  trigger.php     │───▶│  Step Functions    │
│  (StartExecution)│    │  (LocalStack:4566) │
└──────────────────┘    └────────┬───────────┘
                                 │ GetActivityTask
                  ┌──────────────┼──────────────┐
                  ▼              ▼              ▼
        ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
        │ service-a-   │ │ service-b-   │ │  alerter     │
        │  worker      │ │  worker      │ │ (poll FAILED)│
        └──────────────┘ └──────────────┘ └──────────────┘
```

- **State machine** definida em `state-machine.json` (ASL com 3 Tasks + Catch chain LIFO).
- **Activity workers** (PHP CLI long-lived) pollam `GetActivityTask`, executam handler local, retornam `SendTaskSuccess`/`SendTaskFailure`.
- **LocalStack** emula Step Functions sem custo AWS.

## Workflow de referência

Mesmo dos outros PoCs (`saga-rabbitmq/`, `saga-temporal/`):

| Step | Serviço   | Ação              | Compensação    |
| ---- | --------- | ----------------- | -------------- |
| 1    | service-a | `ReserveStock`    | `ReleaseStock` |
| 2    | service-b | `ChargeCredit`    | `RefundCredit` |
| 3    | service-a | `ConfirmShipping` | —              |

Compensação LIFO via `Catch` chain do ASL: ConfirmShipping fail → RefundCredit → ReleaseStock → Compensated.

## Como rodar

```bash
docker compose up -d --build
docker compose exec service-a-worker php bin/trigger.php
```

## Variáveis de teste

- `FORCE_FAIL=step1` ou `step3` — força falha em ReserveStock ou ConfirmShipping.
- `FAIL_COMPENSATION=refund` — força falha em RefundCredit.
- `SLOW_RESERVE_STOCK=N` — atraso em segundos no reserveStock (resilience tests).
- `SLOW_COMPENSATION=N` — atraso em compensações (T2.3).

## Bench scripts

- `bin/batch-trigger.php N` — dispara N executions concorrentes (T1.3).
- `bin/p99-bench.php N` — N executions sequenciais com latência por execução (T6.2).
- `bin/sustained-load.php SECONDS RATE` — load sustentado (T3.3).
- `bin/alerter.php` — daemon de alerta para executions com status FAILED (T2.2).
