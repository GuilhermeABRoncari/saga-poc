# Estudo de SAGA: introdução técnica

> Documento de entrada do estudo. Resume o problema, o ambiente alvo, os critérios e as ferramentas que foram comparadas. Para a definição do padrão e dos modelos (orquestração/coreografia), ver [`compreensao-saga.md`](./compreensao-saga.md). Para terminologia, ver [`glossario.md`](./glossario.md). Para a recomendação consolidada, ver [`recomendacao-saga.md`](./recomendacao-saga.md).

---

## 1. Por que esse estudo existe

Em arquiteturas com múltiplos serviços, operações de negócio frequentemente atravessam mais de um recurso transacional (banco próprio do serviço A, banco do serviço B, sistema externo C). Não há transação ACID que cubra essa fronteira; o caminho viável é o padrão **SAGA** — sequência de transações locais com **compensação semântica** em caso de falha.

O estudo não busca resolver um fluxo de negócio específico. O objetivo é avaliar **infraestrutura genérica** para coordenar workflows multi-serviço com compensação consistente, aplicável a vários domínios (pedidos, pagamentos, estoque, logística etc.).

Hoje, na arquitetura de partida:

- Serviços PHP/Laravel se comunicam por HTTP M2M e por filas.
- Não há padrão explícito de SAGA: jobs disparam outros jobs, sem orquestração central nem compensação automática.
- Falhas parciais costumam ser tratadas ad-hoc (status manual, reprocesso manual, retries via `failed()`).

A pergunta do estudo: **qual é a melhor opção de plataforma/padrão para introduzir SAGA de forma sistemática nesta organização?**

---

## 2. Premissas do ambiente alvo

As premissas abaixo foram congeladas no início e revisitadas durante o estudo. São o filtro que separa "tecnicamente possível" de "viável aqui".

### 2.1 Stack atual

- PHP 8.x + Laravel nos serviços.
- Comunicação síncrona: HTTP M2M; em alguns trechos, GraphQL na borda.
- Comunicação assíncrona: filas (SQS/ElasticMQ ou broker AMQP).
- Bancos: SQL (Postgres/MySQL/MariaDB) por serviço; nenhum compartilhado.

### 2.2 Infra futura — migração gradual

A premissa de infra é **migração para Kubernetes (EKS) gradual, não em bloco**. Existe a expectativa de um período híbrido — Docker Swarm + EKS — por tempo indeterminado. Critérios são avaliados nesse contexto: uma plataforma que só seja viável em K8s nativo não é descartada, mas paga o preço de só estar disponível para os serviços que já migraram.

### 2.3 Volume e escala

- Workflows de poucas dezenas de passos no máximo (não pipelines de big data).
- Latência típica aceitável de segundos para minutos por saga (não real-time).
- Volume da ordem de milhares a dezenas de milhares de sagas por dia, com picos.
- Persistência de longo prazo de history não é requisito (auditoria operacional sim, retenção indefinida não).

### 2.4 Restrições de time

- Time confortável com PHP/Laravel; baixa exposição a Go/Java.
- Pouca experiência prévia com workflow engines dedicadas.
- Operação de infra centralizada em poucas pessoas — qualquer aumento de superfície operacional pesa.

---

## 3. Critérios de avaliação

A tabela completa fica em [`recomendacao-saga.md`](./recomendacao-saga.md) §3.2. Em resumo, cada PoC é avaliada sob:

- **Correção** — happy path, falha em cada step, compensação LIFO, idempotência sob retry.
- **DX** — LOC para o mesmo workflow, legibilidade, facilidade de debug local.
- **Observabilidade** — capacidade de reconstruir o que aconteceu em uma saga arbitrária.
- **Operação** — esforço de deploy, requisitos de runtime extras, custo de infra (self-host e managed).
- **Resiliência** — comportamento sob kill de worker mid-flight, deploy mid-flight, falha de broker, falha de banco.
- **Volume de escritas no banco** — medido em INSERTs/UPDATEs por saga (relevante para custo de Aurora e para análise de impacto sob alto throughput).
- **Lock-in** — quão fácil é trocar de tecnologia depois.
- **Maturidade do ecossistema PHP** — qualidade do SDK, número de installs, atividade no GitHub.

---

## 4. Ferramentas comparadas

Quatro PoCs foram implementadas neste repositório, em diretórios isolados. Todas executam o **mesmo workflow de referência de 3 passos com compensação LIFO** (`ReserveStock` → `ChargeCredit` → `ConfirmShipping`), descrito em [`compreensao-saga.md`](./compreensao-saga.md) §3.

| PoC                             | Diretório                     | Modelo                       | Findings                                                                   |
| ------------------------------- | ----------------------------- | ---------------------------- | -------------------------------------------------------------------------- |
| RabbitMQ + lib custom           | `saga-rabbitmq/`              | Orquestração                 | [`findings-rabbitmq.md`](./findings-rabbitmq.md)                           |
| Temporal                        | `saga-temporal/`              | Orquestração (durable exec.) | [`findings-temporal.md`](./findings-temporal.md)                           |
| AWS Step Functions (LocalStack) | `saga-step-functions/`        | Orquestração managed         | [`findings-step-functions.md`](./findings-step-functions.md)               |
| RabbitMQ coreografado           | `saga-rabbitmq-coreografado/` | Coreografia (lib mínima)     | [`findings-rabbitmq-coreografado.md`](./findings-rabbitmq-coreografado.md) |

A 4ª PoC (coreografada) foi adicionada após o estudo identificar que as três primeiras cobriam apenas orquestração — o padrão merecia uma avaliação simétrica antes de qualquer recomendação fechada.

### 4.1 O que cada PoC investiga

- **RabbitMQ + lib custom** — quanto código é necessário para construir saga em cima de transport puro (state machine, outbox, DLQ, compensação). Validar se "construir em cima do que já temos" é tratável.
- **Temporal** — quanto se ganha em DX/observabilidade ao adotar uma engine dedicada de durable execution. Validar restrições de runtime (RoadRunner) e determinismo no contexto PHP.
- **Step Functions** — avaliar a opção managed AWS, com foco em "zero-ops" e integração nativa com SQS/Lambda/EventBridge.
- **RabbitMQ coreografado** — validar saga sem coordenador central, com lib mínima (<100 LOC), cada serviço dono do seu passo e da sua compensação. Foco em acoplamento mínimo e simplicidade operacional.

---

## 5. Workflow de referência das PoCs

Para que a comparação seja honesta, todas as PoCs implementam o mesmo workflow:

```
ReserveStock  →  ChargeCredit  →  ConfirmShipping
   ↓ falha       ↓ falha          ↓ falha
ReleaseStock  ←  RefundCredit  ←  CancelShipping
```

Cenários executados em todas as PoCs:

1. **Happy path** — três passos completam, saga termina em `COMPLETED`.
2. **Falha no step 2** — `ReleaseStock` é executado; saga termina em `COMPENSATED`.
3. **Falha no step 3** — `RefundCredit` e depois `ReleaseStock` (LIFO); saga termina em `COMPENSATED`.

Falhas são injetadas via variável `FORCE_FAIL=step2|step3` para tornar os testes determinísticos.

---

## 6. Documentos relacionados

- [`compreensao-saga.md`](./compreensao-saga.md) — definição técnica do padrão SAGA, modelos (orquestração/coreografia), quando NÃO usar.
- [`glossario.md`](./glossario.md) — siglas e termos.
- [`recomendacao-saga.md`](./recomendacao-saga.md) — tabela de critérios congelada e recomendação consolidada.
- [`consideracoes.md`](./consideracoes.md) — riscos, trade-offs e decisões transversais.
- `findings-*.md` — relatórios das PoCs.
- [`checklist-testes.md`](./checklist-testes.md) — bateria de testes executada em cada PoC.
- [`fechamento.md`](./fechamento.md) — síntese final do estudo.
