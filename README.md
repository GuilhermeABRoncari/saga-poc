# saga-poc

Estudo comparativo de ferramentas para implementar o **padrão SAGA** em arquiteturas distribuídas baseadas em PHP/Laravel. Este repositório agrupa quatro PoCs independentes que executam o mesmo workflow de referência sob estratégias e plataformas distintas, junto com a documentação analítica que sustenta a comparação.

## Documentação

A parte conceitual e analítica vive em [`docs/`](./docs):

- [`docs/glossario.md`](./docs/glossario.md) — sumário de siglas e termos usados ao longo do estudo (PoC, SAGA, AMQP, ASL, etc.). Boa porta de entrada para quem encontra um termo desconhecido.
- [`docs/estudo.md`](./docs/estudo.md) — pesquisa inicial: comparação RabbitMQ vs Temporal e o motivo pelo qual Step Functions foi reincorporado posteriormente como uma 3ª PoC.
- [`docs/compreensao-saga.md`](./docs/compreensao-saga.md) — o que é SAGA na literatura, o que **não é**, e como exemplos concretos se encaixam no padrão.
- [`docs/saga-rabbitmq-deep-dive.md`](./docs/saga-rabbitmq-deep-dive.md) — conceitos de AMQP/RabbitMQ + lições da PoC + lacunas para produção.
- [`docs/recomendacao-saga.md`](./docs/recomendacao-saga.md) — estado atual da decisão + plano de PoC comparativo + critérios de avaliação.
- [`docs/findings-rabbitmq.md`](./docs/findings-rabbitmq.md) — medições e observações da PoC RabbitMQ (preenche a tabela §3.2 da recomendação).
- [`docs/findings-temporal.md`](./docs/findings-temporal.md) — medições simétricas da PoC Temporal + tabela final de comparação direta.
- [`docs/findings-step-functions.md`](./docs/findings-step-functions.md) — medições simétricas da 3ª PoC (Step Functions via LocalStack).
- [`docs/consideracoes.md`](./docs/consideracoes.md) — prós e contras detalhados por abordagem, com mitigações e o ponto-chave da "dialética Temporal vs Laravel".
- [`docs/checklist-testes.md`](./docs/checklist-testes.md) — checklist de 20 testes comparativos Tier 1-6, com resultados anotados.
- [`docs/fechamento.md`](./docs/fechamento.md) — síntese das baterias de teste e recomendação consolidada.

## Estrutura

- [`saga-rabbitmq/`](./saga-rabbitmq) — PoC com RabbitMQ + esboço de uma lib interna de saga orquestrada.
- [`saga-temporal/`](./saga-temporal) — PoC com Temporal + esboço de wrapper Laravel para o SDK PHP do Temporal.
- [`saga-rabbitmq-coreografado/`](./saga-rabbitmq-coreografado) — PoC com RabbitMQ no estilo coreografia (sem orquestrador central).
- [`saga-step-functions/`](./saga-step-functions) — PoC com AWS Step Functions rodando em LocalStack + activity workers PHP em modo poll-based.

Cada PoC implementa o **mesmo workflow de referência** (3 passos com compensação LIFO; o passo 3 falha intencionalmente para exercitar a reversão).

## Workflow de referência

Workflow genérico de processamento de pedido, escolhido por ser um exemplo clássico da literatura SAGA e por exercitar de forma compacta os três comportamentos relevantes (commit local, compensação semântica, reversão em ordem inversa):

| Step | Serviço   | Ação              | Compensação      |
| ---- | --------- | ----------------- | ---------------- |
| 1    | service-a | `ReserveStock`    | `ReleaseStock`   |
| 2    | service-b | `ChargeCredit`    | `RefundCredit`   |
| 3    | service-a | `ConfirmShipping` | — (último passo) |

Com `FORCE_FAIL=step3` configurado no orquestrador (ou no produtor, no caso da coreografia), o passo 3 falha → a saga executa `RefundCredit` → `ReleaseStock` em ordem reversa (LIFO).

## Como medir

Os critérios de avaliação foram congelados antes da implementação dos PoCs e estão consolidados em [`docs/recomendacao-saga.md`](./docs/recomendacao-saga.md) §3.2. Cada `findings-*.md` preenche a mesma tabela para permitir comparação direta lado a lado.
