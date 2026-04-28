# saga-temporal PoC

Implementação do **mesmo workflow de referência** do `saga-rabbitmq` usando Temporal — para comparação direta dos critérios da §3.2 da [`recomendacao-saga.md`](../docs/recomendacao-saga.md).

## Topologia

```
┌──────────────────────────────────────────────────────────────────┐
│  Temporal Server (auto-setup, Postgres dentro)                   │
│  Task queues: saga-orchestrator, service-a, service-b            │
└─────────────────────────────┬────────────────────────────────────┘
                              │ gRPC
       ┌──────────────────────┼──────────────────────┐
       ▼                      ▼                      ▼
  workflow-worker        service-a-worker      service-b-worker
  registers              registers             registers
  ActivateStoreSaga      ServiceAActivities    ServiceBActivities
  (reserve_stock,        (reserve_stock,       (charge_credit,
   charge_credit,         release_stock,        refund_credit)
   confirm_shipping —     confirm_shipping)
   yield + Saga LIFO)
```

Estado da saga, retries e compensação são responsabilidade do **engine Temporal** — o orquestrador é apenas código sequencial com `yield`.

## Workflow

| Step | Activity | Compensação |
|---|---|---|
| 1 | `ServiceA.reserveStock` | `ServiceA.releaseStock` |
| 2 | `ServiceB.chargeCredit` | `ServiceB.refundCredit` |
| 3 | `ServiceA.confirmShipping` | — (último passo) |

Com `FORCE_FAIL=step3`, o `confirmShipping` lança exceção → bloco `catch` chama `yield $saga->compensate()` → engine roda `refundCredit` e `releaseStock` em LIFO automático.

## Como rodar

### Subir o stack

```bash
docker compose up --build -d
```

Componentes:
- `temporal:7233` — gRPC do Temporal server
- `temporal-ui` em http://localhost:8088 — UI de execução
- 3 workers (workflow-worker, service-a-worker, service-b-worker)

### Disparar saga

```bash
docker compose exec workflow-worker php bin/trigger.php
```

### Forçar compensação

```bash
docker compose down
FORCE_FAIL=step3 docker compose up --build -d
docker compose exec workflow-worker php bin/trigger.php
```

### Inspecionar

- UI Temporal: http://localhost:8088 → Workflows → clicar em workflow → ver timeline completo (cada activity, retries, payload de entrada/saída, compensações).
- Logs: `docker compose logs -f workflow-worker service-a-worker service-b-worker`.

### Reset

```bash
docker compose down -v
```

## Estrutura

```
src/
├── Sagas/
│   └── ActivateStoreSaga.php       # Workflow class — o orquestrador inteiro num arquivo
└── Activities/
    ├── ServiceA/
    │   ├── ServiceAActivitiesInterface.php
    │   └── ServiceAActivities.php
    └── ServiceB/
        ├── ServiceBActivitiesInterface.php
        └── ServiceBActivities.php
bin/
├── workflow-worker.php             # registra Workflow no task queue saga-orchestrator
├── service-a-worker.php            # registra activities no task queue service-a
├── service-b-worker.php            # registra activities no task queue service-b
└── trigger.php                     # inicia uma execução de workflow
```

`.rr.yaml` é compartilhado pelos 3 workers; o script-alvo vem da env `WORKER_SCRIPT`.

## Comparação prevista (a preencher em `docs/findings-temporal.md`)

| Critério | RabbitMQ (medido) | Temporal (a medir) |
|---|---|---|
| LOC happy path | 632 | _a medir_ |
| LOC compensação | ~55 | _a medir_ |
| Resiliência (kill mid-handler) | ✅ via requeue | _a medir, espera-se ✅ via Activity retry_ |
| At-least-once → execução dupla | ⚠️ gap real | _espera-se ✅ exactly-once via event sourcing_ |
| Observabilidade | logs + Mgmt UI básica | _UI Temporal: timeline rica_ |
| DX em code review | fluxo espalhado em 3-4 arquivos | _saga em um arquivo, sequencial_ |
