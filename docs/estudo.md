# Estudo: SAGA para Microsserviços

> Estudo iniciado em 24/04/2026.

## Objetivo

Avaliar ferramentas para orquestração genérica de workflows entre microsserviços. O foco não é resolver um fluxo específico isolado, mas escolher uma infraestrutura geral capaz de coordenar workflows multi-serviço com compensação consistente em vários domínios da plataforma.

## Decisões tomadas

- **Infra futura**: Migração para Kubernetes considerada como cenário provável (ver §1.1 de `recomendacao-saga.md` para premissas atualizadas — migração gradual, possivelmente com período híbrido entre Docker Swarm e Kubernetes).
- **Abordagem**: PoC mínimo por ferramenta com workflow de 3 passos com compensação LIFO.
- **AWS Step Functions descartado inicialmente, reaberto em 2026-04-29**: o foco original foi RabbitMQ + lib interna e Temporal. Após a decisão preliminar pró-Temporal, optamos por executar uma 3ª PoC (`saga-step-functions/`) em LocalStack para fechar a comparação 3-way. Resultado em [`findings-step-functions.md`](./findings-step-functions.md): Step Functions adiciona "zero-ops" como atrativo, mas perde nos critérios qualitativos críticos (correção sob mudança de código, latência, lock-in).
- **Organização**: Diretórios isolados (`saga-rabbitmq/`, `saga-temporal/`, `saga-rabbitmq-coreografado/`, `saga-step-functions/`) em repositório dedicado de estudo.

## Arquitetura de referência considerada

- Stack PHP/Laravel com múltiplos serviços comunicando-se por HTTP M2M e filas.
- Comunicação de fundo: filas (SQS/ElasticMQ ou broker AMQP).
- Possível camada de federação GraphQL no topo.
- Sem saga explícito hoje — jobs disparam outros jobs sem compensação automática, padrão a ser superado.

---

## Comparação: RabbitMQ vs Temporal

| Aspecto                            | RabbitMQ + Laravel                                                         | Temporal                                              |
| ---------------------------------- | -------------------------------------------------------------------------- | ----------------------------------------------------- |
| **O que é**                        | Message transport (a saga é construída por cima)                           | Durable execution engine (saga built-in)              |
| **Compensação**                    | 100% custom (state machine + DB table)                                     | First-class: `Workflow\Saga` com LIFO automático      |
| **Legibilidade do workflow**       | Implícito (switch/events entre serviços)                                   | Explícito (código sequencial com `yield`)             |
| **Observabilidade de saga**        | Custom (query DB + logs)                                                   | Temporal Web UI (timeline completa por workflow)      |
| **PHP ecosystem**                  | Maduro para transport (9.4M installs)                                      | SDK funcional mas 2ª classe (384 stars)               |
| **Runtime extra**                  | Nenhum (Laravel queues padrão)                                             | RoadRunner obrigatório para Workers                   |
| **Curva de aprendizado**           | Baixa no transport, alta no saga pattern                                   | Alta (yield, determinismo, RoadRunner)                |
| **Self-hosting Swarm**             | Possível mas doloroso (clustering)                                         | Não suportado oficialmente                            |
| **K8s**                            | Helm charts maduros                                                        | Helm charts oficiais                                  |
| **Managed option**                 | CloudAMQP (~$20-100/mês)                                                   | Temporal Cloud (~$100-200/mês)                        |
| **O que é construído pelo time**   | Tudo: state machine, outbox, DLQ handler, compensação, idempotência, retry | Activities + Workflow definition. Infra é do Temporal |
| **Deploy com workflows in-flight** | Sem restrição                                                              | Requer `Workflow::getVersion()` (determinismo)        |

---

## Pesquisa: RabbitMQ + Laravel

### O que RabbitMQ fornece

- Filas duráveis, exchanges, DLX, publisher confirms, consumer acks, management UI.

### O que RabbitMQ NÃO fornece

- Saga state persistence, sequenciamento de steps, compensação, retry com backoff, correlation/tracing.

### PHP/Laravel Ecosystem

- **`vladimir-yuldashev/laravel-queue-rabbitmq`** — padrão de facto (9.4M installs, v14.4, Laravel 12).
- **`vandarpay/orchestration-saga`** — único pacote de saga, mas 6 installs, 2 stars. NÃO production-ready.
- **Conclusão**: Não existe framework de saga maduro para PHP. Tudo é código custom.

### O que precisa ser construído

- `SagaOrchestrator` (state machine).
- Tabela `saga_state` (id, type, current_step, payload, status).
- Tabela `outbox_messages` (transactional outbox — obrigatório).
- `SagaStateRepository`.
- Comandos de compensação por step.
- DLQ monitoring/alerting.

### Operacional

- Swarm: clustering doloroso (hostname pinning, volume constraints, peer discovery).
- Quorum Queues obrigatórias no RabbitMQ 4.0+ (mirrored queues removidas).
- Mínimo 3 nodes para HA real.
- Recursos: 4GB RAM + 4 cores por node (produção).
- Monitoring: Management UI + Prometheus/Grafana.

### Pros

- Maduro (18+ anos), battle-tested.
- Excelente integração Laravel.
- Baixa curva de aprendizado no transport layer.
- Self-hosted, controle total.

### Contras

- Sem framework de saga — tudo custom.
- Sem workflow visualization.
- Idempotência manual em tudo.
- Clustering em Swarm é doloroso.
- PHP ecosystem fino para saga.
- DX inferior a workflow engines dedicadas.

---

## Pesquisa: Temporal

### O que Temporal é

- Durable execution engine — workflow roda até completar mesmo com crashes.
- Event-sourcing de cada Workflow execution.
- Três primitivas: Workflow (orchestrator, determinístico), Activity (I/O real), Worker (processo que executa).

### PHP SDK

- **`temporal/sdk`** v2.17.1 (março 2026) — mantido ativamente.
- 2.4M installs, 384 stars no GitHub.
- Construído pela Spiral Scout (não pelo core team do Temporal).
- **RoadRunner obrigatório** para Workers.
- **`keepsuit/laravel-temporal`** — integração Laravel (50 stars), artisan commands, testing helpers.

### Saga built-in

Forma do API (referência da documentação oficial do Temporal):

```php
$saga = new Workflow\Saga();

$orderId = yield $activities->processOrder();
$saga->addCompensation(fn() => yield $activities->cancelOrder($orderId));

$reservationId = yield $activities->reserveStock($orderId);
$saga->addCompensation(fn() => yield $activities->releaseStock($reservationId));

// Se falhar, compensações rodam em LIFO automático:
yield $saga->compensate();
```

### Restrições de determinismo (CRÍTICO)

- Workflow é replayed do zero no recovery — código DEVE ser determinístico.
- PROIBIDO: `date()`, `time()`, `rand()`, `sleep()`, DB queries, HTTP calls, `echo`, `var_dump`.
- ALTERNATIVAS: `Workflow::now()`, `Workflow::timer()`, `Workflow::sideEffect()`, Activity classes.
- Workflow versioning com `Workflow::getVersion()` para deploys com workflows in-flight.

### Operacional

- 4 serviços internos: Frontend, History, Matching, Worker.
- Persistence: PostgreSQL/MySQL 8/Cassandra + Elasticsearch (opcional). **MariaDB não suportado** — confirmado em 2026-05-04 (ver `findings-temporal.md` §2.2.6).
- Docker Swarm NÃO suportado oficialmente — Kubernetes ou Temporal Cloud.
- Dev local: `temporal server start-dev` (single binary, zero deps).
- Temporal Cloud: Free tier → Essentials ~$100/mês → Growth ~$200/mês.

### Pros

- Durable execution out of the box.
- SAGA compensation first-class.
- Observabilidade excelente (Temporal Web UI).
- Worker pull model (sem firewall inbound).
- Temporal Cloud elimina ops burden.

### Contras

- RoadRunner obrigatório — runtime desconhecido para times acostumados a FPM.
- `yield` syntax incomum.
- Determinismo é uma restrição rígida.
- PHP SDK segunda classe (384 stars vs milhares para Go/Java).
- Self-hosting complexo, K8s recomendado.
- `dd()`/`var_dump`/`echo` não funcionam dentro de Workflows.

---

## PoC: Workflow de referência

### Cenários de teste

1. **Happy path**: Step 1 → Step 2 → Step 3 completam.
2. **Falha no step 2**: Compensa step 1.
3. **Falha no step 3**: Compensa steps 2 e 1 em ordem reversa.

### Critérios de avaliação pós-PoC

Ver `recomendacao-saga.md` §3.2 para a tabela completa de critérios congelada antes do PoC. Resumo:

- LOC para o mesmo workflow.
- Tempo de setup (`docker-compose up` → workflow rodando).
- Legibilidade do workflow.
- Facilidade de debug quando algo falha.
- Recursos consumidos.
- Esforço para atingir observabilidade aceitável.
- Resiliência (kill de worker mid-flight, deploy mid-flight).

---

## Estado atual

- **saga-rabbitmq**: PoC funcional. Happy path e cenário de compensação validados ponta a ponta. Detalhes em `../saga-rabbitmq/README.md`.
- **saga-temporal**: PoC funcional, com bateria de medições registrada em `findings-temporal.md`.
- **saga-step-functions**: PoC executada em LocalStack para fechar a comparação 3-way; resultados em `findings-step-functions.md`.
- **saga-rabbitmq-coreografado**: 4ª PoC, executada após o estudo identificar que coreografia merecia avaliação simétrica e não havia sido coberta pelas três primeiras.

### Próximos passos

1. Consolidar critérios faltantes da §3.2 nas PoCs já implementadas.
2. Comparar lado a lado as quatro abordagens com base nos `findings-*.md`.
3. Recomendação fechada baseada em evidência, atualizando o "em aberto" atual de `recomendacao-saga.md`.
