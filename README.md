# saga-poc

PoC comparativo de ferramentas para o **padrão organizacional de SAGA** da empresa.

## Documentação

Toda a parte conceitual / analítica vive em [`docs/`](./docs):

- [`docs/glossario.md`](./docs/glossario.md) — sumário de siglas e termos usados no estudo (PoC, SAGA, AMQP, ASL, etc.). Ler primeiro se algum termo não estiver claro.
- [`docs/estudo.md`](./docs/estudo.md) — pesquisa inicial: comparação RabbitMQ vs Temporal (e por que Step Functions saiu — agora reaberto como 3ª PoC).
- [`docs/compreensao-saga.md`](./docs/compreensao-saga.md) — o que é SAGA na literatura, o que **não é**, e como o caso real `StoreController@activate` se encaixa.
- [`docs/saga-rabbitmq-deep-dive.md`](./docs/saga-rabbitmq-deep-dive.md) — conceitos de AMQP/RabbitMQ + lições da PoC + gap pra produção.
- [`docs/recomendacao-saga.md`](./docs/recomendacao-saga.md) — estado atual da decisão (em aberto) + plano de PoC comparativo + critérios.
- [`docs/findings-rabbitmq.md`](./docs/findings-rabbitmq.md) — medições e observações vivas da PoC RabbitMQ (preenche a tabela §3.2 da recomendação).
- [`docs/findings-temporal.md`](./docs/findings-temporal.md) — medições simétricas da PoC Temporal + tabela final de comparação direta.
- [`docs/findings-step-functions.md`](./docs/findings-step-functions.md) — medições simétricas da 3ª PoC (Step Functions via LocalStack).
- [`docs/consideracoes.md`](./docs/consideracoes.md) — pros e contras detalhados por abordagem, com mitigações e o ponto-chave da "dialética Temporal vs Laravel".
- [`docs/checklist-testes.md`](./docs/checklist-testes.md) — checklist de 20 testes comparativos Tier 1-6, com resultados anotados.
- [`docs/fechamento.md`](./docs/fechamento.md) — síntese das 6 baterias de teste e recomendação consolidada.

## Estrutura

- [`saga-rabbitmq/`](./saga-rabbitmq) — PoC com RabbitMQ + esboço de `mobilestock/laravel-saga`.
- [`saga-temporal/`](./saga-temporal) — PoC com Temporal + esboço de `mobilestock/laravel-temporal-saga`. (vazio até segunda fase)
- [`saga-step-functions/`](./saga-step-functions) — 3ª PoC com AWS Step Functions rodando em LocalStack + activity workers PHP poll-based.

Cada PoC implementa o **mesmo workflow de referência** (3 passos com compensação LIFO; o passo 3 falha intencionalmente para exercitar reversão).

## Workflow de referência

Versão reduzida do `ActivateStoreSaga` (caso do PR #2021 do `backend`):

| Step | Serviço                 | Ação              | Compensação      |
| ---- | ----------------------- | ----------------- | ---------------- |
| 1    | service-a (marketplace) | `ReserveStock`    | `ReleaseStock`   |
| 2    | service-b (users)       | `ChargeCredit`    | `RefundCredit`   |
| 3    | service-a (marketplace) | `ConfirmShipping` | — (último passo) |

Com `FORCE_FAIL=step3` no orquestrador, o passo 3 falha → orquestrador executa `RefundCredit` → `ReleaseStock` (LIFO).

## Como medir

Critérios congelados antes da implementação em `backend/docs/recomendacao-saga.md` §3.2.
