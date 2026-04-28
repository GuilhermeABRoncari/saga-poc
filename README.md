# saga-poc

PoC comparativo de ferramentas para o **padrão organizacional de SAGA** da empresa.

Contexto e critérios em [`backend/docs/recomendacao-saga.md`](https://github.com/mobilestock/backend/blob/descovery-queue/docs/recomendacao-saga.md) §3.

## Estrutura

- [`saga-rabbitmq/`](./saga-rabbitmq) — PoC com RabbitMQ + esboço de `mobilestock/laravel-saga`.
- [`saga-temporal/`](./saga-temporal) — PoC com Temporal + esboço de `mobilestock/laravel-temporal-saga`. (vazio até segunda fase)

Cada PoC implementa o **mesmo workflow de referência** (3 passos com compensação LIFO; o passo 3 falha intencionalmente para exercitar reversão).

## Workflow de referência

Versão reduzida do `ActivateStoreSaga` (caso do PR #2021 do `backend`):

| Step | Serviço | Ação | Compensação |
|---|---|---|---|
| 1 | service-a (marketplace) | `ReserveStock` | `ReleaseStock` |
| 2 | service-b (users) | `ChargeCredit` | `RefundCredit` |
| 3 | service-a (marketplace) | `ConfirmShipping` | — (último passo) |

Com `FORCE_FAIL=step3` no orquestrador, o passo 3 falha → orquestrador executa `RefundCredit` → `ReleaseStock` (LIFO).

## Como medir

Critérios congelados antes da implementação em `backend/docs/recomendacao-saga.md` §3.2.
