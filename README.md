# saga-poc

PoC comparativo de ferramentas para o **padrão organizacional de SAGA** da empresa.

## Documentação

Toda a parte conceitual / analítica vive em [`docs/`](./docs):

- [`docs/estudo.md`](./docs/estudo.md) — pesquisa inicial: comparação RabbitMQ vs Temporal (e por que Step Functions saiu).
- [`docs/compreensao-saga.md`](./docs/compreensao-saga.md) — o que é SAGA na literatura, o que **não é**, e como o caso real `StoreController@activate` se encaixa.
- [`docs/saga-rabbitmq-deep-dive.md`](./docs/saga-rabbitmq-deep-dive.md) — conceitos de AMQP/RabbitMQ + lições da PoC + gap pra produção.
- [`docs/recomendacao-saga.md`](./docs/recomendacao-saga.md) — estado atual da decisão (em aberto) + plano de PoC comparativo + critérios.

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
