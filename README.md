# saga-poc

Estudo comparativo público do padrão **SAGA** em arquiteturas distribuídas baseadas em PHP/Laravel. Quatro PoCs independentes implementam o mesmo workflow de referência sob estratégias e plataformas diferentes, junto com a documentação analítica que sustenta a comparação. O objetivo do estudo nunca foi escolher uma "ferramenta vencedora" — foi mapear quando cada combinação ferramenta×modelo se justifica.

## Resumo executivo

A pergunta não é "qual ferramenta resolve um caso pontual?", mas **qual modelo + qual ferramenta servem como padrão sustentável** para múltiplos serviços que coordenam transações distribuídas. Para responder, congelamos critérios antes de implementar, executamos 20 testes Tier 1-6 contra cada PoC, e medimos número (não opinião): LOC, latência p50/p99, throughput, RAM idle, escritas no banco por saga, custo Cloud projetado em 12 meses.

A conclusão consolidada é uma **árvore de decisão**, não uma escolha única (detalhe em `docs/recomendacao-saga.md` §9.1):

| Ferramenta × Modelo             | Latência p99 | Throughput | Vence quando…                                                                                             |
| ------------------------------- | ------------ | ---------- | --------------------------------------------------------------------------------------------------------- |
| RabbitMQ + lib **orquestrada**  | 23.8 ms      | ~46/s      | fluxo médio (4-7 steps), poucos serviços, time pequeno, sem requisito de audit trail estrito              |
| RabbitMQ + lib **coreografada** | 20.4 ms      | ~94/s      | fluxo curto (≤3 steps), múltiplos squads desacoplados, requisito de latência baixa, throughput burst alto |
| **Temporal**                    | 351.2 ms     | ~7.4/s     | fluxo longo (8+ steps) ou aninhado, audit trail/replay obrigatório, deploys frequentes com sagas em voo   |
| **AWS Step Functions**          | ~2092 ms\*   | ~7.5/s     | já em stack AWS-native, free tier (≤4k transições/mês) suficiente, lock-in aceitável                      |

\*Step Functions medido em LocalStack — números absolutos não refletem AWS real, mas o ranking relativo se sustenta.

Achados estruturais que não mudam conforme o cenário:

- **Temporal × banco:** MariaDB **não suportado** (Multi-Valued Indexes, JSON path); MySQL 8 confirmado funcional empiricamente. Backends oficiais: PostgreSQL 12+, MySQL 8.0+, Cassandra 3.11+.
- **RabbitMQ 4.3 (Khepri/Raft):** mirrored queues removidas; quorum queues são única opção HA suportada e custam **−25% de throughput** em single-node.
- **Custo 12 meses:** RabbitMQ self-hosted ~$2.4-4.8k; Temporal Cloud em escala (~17M sagas/mês × 7 actions) ~$58k/ano.
- **Escritas no banco por saga:** Temporal 38 INSERTs (happy) / 53 (com compensação); RabbitMQ orquestrado 1; coreografado 0 / 2.

## Estrutura do repositório

PoCs (cada uma roda o mesmo workflow de 3 passos com `FORCE_FAIL=step3` para exercitar reversão LIFO):

- [`saga-rabbitmq/`](./saga-rabbitmq) — RabbitMQ + lib interna **orquestrada** (orchestrator central + `saga_states`).
- [`saga-rabbitmq-coreografado/`](./saga-rabbitmq-coreografado) — RabbitMQ no estilo **coreografia**, sem orquestrador, lib mínima publicando `saga.failed` em fanout.
- [`saga-temporal/`](./saga-temporal) — Temporal + RoadRunner + esboço de wrapper Laravel para o SDK PHP.
- [`saga-step-functions/`](./saga-step-functions) — AWS Step Functions em LocalStack + activity workers PHP poll-based.

## Workflow de referência

| Step | Serviço         | Ação              | Compensação    |
| ---- | --------------- | ----------------- | -------------- |
| 1    | order-service   | `ReserveStock`    | `ReleaseStock` |
| 2    | payment-service | `ChargeCredit`    | `RefundCredit` |
| 3    | order-service   | `ConfirmShipping` | — (último)     |

Com `FORCE_FAIL=step3` o passo 3 falha → roda `RefundCredit` → `ReleaseStock` em ordem inversa.

## Documentação canônica

Documentos de **decisão**:

- [`docs/recomendacao-saga.md`](./docs/recomendacao-saga.md) — recomendação consolidada como árvore de decisão por cenário (fluxo, time, infraestrutura, requisitos não-funcionais), tabela comparativa final das 4 combinações, scorecard, anti-padrões.
- [`docs/consideracoes.md`](./docs/consideracoes.md) — prós e contras detalhados por ferramenta, incluindo §8.0 (Saga Aggregator) e §8.1 (TCO em 3 cenários de volume).

Documentos de **medição**:

- [`docs/findings-rabbitmq.md`](./docs/findings-rabbitmq.md) — medições da PoC RabbitMQ orquestrado, com revalidação em 4.3 + análise de quorum queues.
- [`docs/findings-rabbitmq-coreografado.md`](./docs/findings-rabbitmq-coreografado.md) — medições da PoC coreografada (357 LOC final, latência, retry, reconnect).
- [`docs/findings-temporal.md`](./docs/findings-temporal.md) — medições da PoC Temporal + achado MariaDB × MySQL 8.
- [`docs/findings-step-functions.md`](./docs/findings-step-functions.md) — medições da PoC Step Functions/LocalStack.
- [`docs/checklist-testes.md`](./docs/checklist-testes.md) — matriz dos 20 testes Tier 1-6 com resultados anotados.

Documentos de **processo**:

- [`docs/fechamento.md`](./docs/fechamento.md) — narrativa do estudo, iterações, decisões registradas (incluindo a 5ª PoC descartada).
- [`docs/estudo.md`](./docs/estudo.md) — pesquisa inicial e como Step Functions foi reincorporado.
- [`docs/glossario.md`](./docs/glossario.md) — siglas e termos.
- [`docs/compreensao-saga.md`](./docs/compreensao-saga.md) — o que é SAGA na literatura, o que **não é**.
- [`docs/saga-rabbitmq-deep-dive.md`](./docs/saga-rabbitmq-deep-dive.md) — fundamentos AMQP/RabbitMQ.

Guias de **integração** (como adotar cada ferramenta na prática):

- [`docs/integracao-rabbitmq.md`](./docs/integracao-rabbitmq.md), [`docs/integracao-temporal.md`](./docs/integracao-temporal.md), [`docs/integracao-step-functions.md`](./docs/integracao-step-functions.md).

## Como reproduzir

Cada PoC tem README próprio com setup, comandos para rodar happy path, simular falhas e coletar métricas. Os critérios de avaliação foram congelados antes da implementação e estão em `docs/recomendacao-saga.md` §3 — cada `findings-*.md` preenche a mesma matriz para permitir comparação direta lado a lado.
