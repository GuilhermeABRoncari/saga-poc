# Recomendação de ferramenta para SAGA — estudo comparativo

Documento consolidado de **decisão**. Sintetiza a evidência produzida por quatro PoCs (RabbitMQ orquestrado, RabbitMQ coreografado, Temporal, AWS Step Functions) submetidas aos mesmos 20 testes Tier 1-6, e propõe uma **árvore de decisão** orquestração ⇄ coreografia × ferramenta — não uma escolha única.

Complementar a [`fechamento.md`](./fechamento.md) (narrativa de processo do estudo), [`consideracoes.md`](./consideracoes.md) (prós/contras detalhados, §8.0 Saga Aggregator, §8.1 TCO em 3 cenários) e [`checklist-testes.md`](./checklist-testes.md) (matriz Tier 1-6).

---

## 1. Enquadramento

A pergunta que o estudo persegue não é "qual ferramenta resolve um caso pontual?", mas **qual modelo + qual ferramenta servem como padrão sustentável para múltiplos serviços** que precisam coordenar transações distribuídas. Esse enquadramento muda os critérios de avaliação:

| Critério                      | Caso pontual  | Padrão para múltiplos serviços              |
| ----------------------------- | ------------- | ------------------------------------------- |
| Custo de adoção inicial       | Alto          | Diluído (paga uma vez, vários consumidores) |
| Curva de aprendizado          | Custo direto  | Investimento que se amortiza                |
| Lock-in de fornecedor         | Pouco importa | Crítico                                     |
| Determinismo / debugabilidade | "Bom ter"     | Obrigatório (auditoria, postmortems)        |
| Padronização entre apps       | Marginal      | É **o objetivo** do estudo                  |
| Substrato de execução         | Único hoje    | Stack híbrida por tempo indeterminado       |

A arquitetura alvo envolve domínios que interagem via SAGAs do tipo "pedido cria reserva no estoque, dispara pagamento, agenda transporte" — fluxo clássico que exercita commit local, compensação semântica e reversão LIFO.

### 1.1 Premissas de infraestrutura

- Migração para orquestrador novo é **gradual**, não em bloco. Workers de qualquer ferramenta podem residir num substrato e disparar Activities contra serviços expostos no outro via HTTP. "Temporal não roda em Swarm" deixa de ser bloqueio técnico — vira escolha de onde colocar workers.
- A escolha precisa ser **sustentável em stack híbrida**, não só no estado-final.
- **Banco principal é MariaDB** em produção. Temporal **não suporta MariaDB** (Multi-Valued Indexes, JSON path syntax — confirmado empiricamente em 2026-05-04 e pela [matriz oficial de persistência](https://docs.temporal.io/self-hosted-guide/defaults)). Backends oficiais: PostgreSQL 12+, MySQL 8.0+, Cassandra 3.11+. **MySQL 8 foi validado** como caminho preferido (familiaridade do time, `mysql:8` ao lado de `mariadb:11.4` sem fricção). Detalhes em [`findings-temporal.md`](./findings-temporal.md) §2.2.6.

Resultado: nenhuma das opções está descartada por critério de infra. Todas são viáveis nessa topologia.

---

## 2. As 4 combinações ferramenta × modelo

Cada PoC implementou o **mesmo workflow** (3 passos: `ReserveStock` → `ChargeCredit` → `ConfirmShipping`, com `FORCE_FAIL=step3` exercitando reversão LIFO) sob os **mesmos 20 testes Tier 1-6**. A tabela abaixo é a comparação direta lado a lado.

### 2.1 Tabela comparativa final

| Eixo                                                   | RabbitMQ orquestrado                          | RabbitMQ coreografado           | Temporal                                       | Step Functions                          |
| ------------------------------------------------------ | --------------------------------------------- | ------------------------------- | ---------------------------------------------- | --------------------------------------- |
| **LOC da lib**                                         | 381                                           | 357                             | wrapper estimado ~5-7 dias eng                 | 119 (state-machine.json) + workers      |
| **T6.2 latência sequencial p50**                       | 21.8 ms                                       | **10.2 ms**                     | 59.9 ms                                        | ~600 ms (LocalStack)                    |
| **T6.2 latência sequencial p99**                       | 23.8 ms                                       | **20.4 ms**                     | 351.2 ms                                       | ~2092 ms (LocalStack)                   |
| **Throughput sequencial**                              | ~46/s                                         | **~94/s**                       | ~7.4/s                                         | ~7.5/s (LocalStack)                     |
| **T1.3 burst (100 sagas concorrentes)**                | 142/s (4.3 classic durable)                   | dependente do consumer          | 28/s                                           | 10.9/s                                  |
| **RAM idle (stack)**                                   | ~108 MiB broker (4.3)                         | ~110 MiB broker                 | 439 MB (~4×)                                   | LocalStack ~600 MB                      |
| **Imagens Docker**                                     | 665 MB                                        | 665 MB                          | 3800 MB                                        | LocalStack ~1 GB                        |
| **INSERTs por saga (happy)**                           | 1                                             | **0**                           | 38                                             | depende da ASL                          |
| **INSERTs por saga (com compensação)**                 | 1                                             | 2                               | 53                                             | depende da ASL                          |
| **HA em produção**                                     | Quorum queues (−25% throughput)               | Quorum queues (−25% throughput) | Replicação nativa do engine                    | Multi-AZ AWS-managed                    |
| **SGBD necessário**                                    | nenhum dedicado                               | nenhum dedicado                 | Postgres / **MySQL 8** / Cassandra (≠ MariaDB) | nenhum (managed)                        |
| **Audit trail / replay**                               | Construir (Saga Aggregator)                   | Construir (Saga Aggregator)     | Nativo, retention configurável                 | Nativo (CloudWatch + Execution history) |
| **T5.1 silent corruption sob reorder**                 | **Sim** — saga COMPLETED com state corrompido | N/A — sem definição central     | Panic LOUD `[TMPRL1100]`                       | Pinning vs migration silenciosa         |
| **T1.4 worker survives broker outage**                 | Não (sem auto-reconnect)                      | **Sim** (reconnect na lib)      | Sim (gRPC retry nativo)                        | N/A                                     |
| **T4.4 conceito de timeout**                           | Não tem                                       | Não tem                         | 4 tipos distintos                              | Definido na ASL                         |
| **Lock-in**                                            | Nenhum (AMQP padrão)                          | Nenhum (AMQP padrão)            | Médio (engine + SDK)                           | Profundo (AWS-only)                     |
| **Custo Cloud em escala (~17M sagas/mês × 7 actions)** | $2.4-4.8k/12 meses self-host                  | $2.4-4.8k/12 meses self-host    | **~$58k/ano** Cloud / ~$3-6k/ano self-host     | ~$51k/ano                               |
| **Custo de adoção (eng × dias)**                       | ~17-23 dias inicial                           | ~10-12 dias inicial             | ~10 dias + 1 semestre calibração               | ~5-7 dias                               |

> Notas:
>
> - LOC contam código da lib/wrapper; **não** contam state machine declarativa (ASL) nem application code.
> - Latências medidas em T6.2 com 1000 sagas sequenciais. Temporal apresenta distribuição **bimodal** (p50 baixo, p99 alto pelo `WorkflowTaskHeartbeat`). RabbitMQ tem distribuição apertada.
> - Step Functions rodou em LocalStack 3.8; valores absolutos não representam AWS real, mas ranking relativo se sustenta.
> - INSERTs por saga medidos contando todas as escritas em todos os bancos da stack (engine + lib + apps). Temporal escreve no `temporal` schema cada Activity start/complete/heartbeat.

### 2.2 Scorecard consolidado

Cada combinação foi pontuada nos critérios qualitativos do estudo. Vermelho = perde, verde = vence, amarelo = empate ou condicional.

| Critério                                    | RabbitMQ-orq | RabbitMQ-cor | Temporal      | Step Functions |
| ------------------------------------------- | ------------ | ------------ | ------------- | -------------- |
| Latência baixa                              | Vence        | **Vence**    | Perde         | Perde          |
| Throughput sustentado                       | Vence        | **Vence**    | Empate        | Perde          |
| Footprint (RAM, imagem, cold start)         | Vence        | Vence        | Perde         | Empate         |
| Custo financeiro 12 meses                   | Vence        | Vence        | Perde escala  | Perde escala   |
| DX em code review (fluxo lê-se sem rodar)   | Empate       | Perde        | **Vence**     | Empate         |
| Audit trail / postmortem rico               | Perde        | Perde        | **Vence**     | Vence          |
| Replay determinístico                       | Não tem      | Não tem      | **Vence**     | Empate         |
| Conceito nativo de timeout                  | Não tem      | Não tem      | **Vence**     | Vence          |
| Segurança contra silent corruption (T5.1)   | **Perde**    | N/A          | **Vence**     | Empate         |
| Durable execution sob falha de infra (T1.4) | Perde        | Vence        | **Vence**     | Vence          |
| Operação (sem cluster próprio)              | Empate       | Empate       | Perde         | **Vence**      |
| Acoplamento entre serviços                  | Empate       | **Vence**    | Empate        | Empate         |
| Lock-in (vendor / proprietário)             | **Vence**    | **Vence**    | Empate        | Perde          |
| Compatibilidade com banco existente         | **Vence**    | **Vence**    | Perde MariaDB | Vence          |

A leitura honesta:

- **Critérios qualitativos críticos** (correção sob mudança, durabilidade, observabilidade, replay) tendem ao Temporal.
- **Critérios quantitativos** (latência, throughput, RAM, custo Cloud em escala) tendem ao RabbitMQ — em qualquer modelo.
- **Critério de governança** (lock-in, compatibilidade de SGBD existente) tende ao RabbitMQ.
- **Critério de operação** (zero cluster próprio) tende ao Step Functions.

Não existe "vencedor universal". Existe **vencedor por cenário** — daí a árvore de decisão.

---

## 3. Critérios de qualidade que qualquer SAGA precisa garantir

Independente da combinação escolhida:

1. **Idempotência por Activity / handler** — qualquer engine pode reentregar; toda Activity recebe `idempotency_key` derivada de `saga_id + step_name`.
2. **Estado persistido** — saga sobrevive a crashes/restarts (no engine ou no DB próprio, depende da ferramenta).
3. **Compensação documentada** — explícita por step, sem "TODO: compensar depois".
4. **Correlation ID** — `saga_id` propagado como header HTTP em todas as chamadas downstream.
5. **Observabilidade default** — saga em andamento / compensada / falha órfã / compensação que falhou são todos visíveis sem investigação.
6. **Timeout explícito** — em workflow inteiro e em cada step.
7. **Alertas** — falha de compensação **sempre** vira alerta crítico (sistema fica realmente inconsistente, humano precisa ver).

Cada combinação atende esses critérios de forma diferente — algumas nativamente (Temporal: 3, 5, 6, 7), outras via código próprio (RabbitMQ: todas exigem implementação).

---

## 4. Árvore de decisão por cenário

A escolha "RabbitMQ orquestrado vs RabbitMQ coreografado vs Temporal vs Step Functions" depende fortemente do contexto. As tabelas abaixo enumeram cenários comuns e indicam qual modelo+ferramenta é tipicamente preferido. Esta é a seção principal do documento — uma decisão apoiada nesta árvore deve sempre referenciar qual linha foi aplicada.

### 4.1 Por característica do fluxo

| Característica do fluxo                                  | Recomendação                   | Justificativa                                                                                                 |
| -------------------------------------------------------- | ------------------------------ | ------------------------------------------------------------------------------------------------------------- |
| **Fluxo curto (≤ 3 steps), poucos serviços (≤ 2)**       | RabbitMQ coreografado          | Custo de DX baixo (cadeia de eventos cabe na cabeça); ganho de latência (~2× vs orquestrado).                 |
| **Fluxo médio (4-7 steps), múltiplos serviços (3-5)**    | Empate Temporal / orquestrado  | Coreografia começa a perder em DX (cadeia de eventos vira grafo); orquestração centralizada vence em revisão. |
| **Fluxo longo (8+ steps) ou aninhado (sub-sagas)**       | **Temporal**                   | Workflow code centralizado é a única forma sã de manter; coreografia vira spaghetti.                          |
| **Fluxo com decisões condicionais complexas**            | **Temporal** ou Step Functions | Branching/loops são primeira-classe; em coreografia exige máquina de estado implícita por evento.             |
| **Fluxo curtíssimo (1-2 steps) e quase sempre síncrono** | **Não usar SAGA**              | HTTP request síncrono + idempotência local resolve. SAGA é overhead.                                          |

### 4.2 Por característica não-funcional

| Requisito                                            | Recomendação                   | Justificativa                                                                                     |
| ---------------------------------------------------- | ------------------------------ | ------------------------------------------------------------------------------------------------- |
| **SLO de latência p99 < 50ms**                       | RabbitMQ (qualquer modelo)     | Temporal p99=351ms está fora do envelope; Step Functions é pior ainda (p99=2092ms em LocalStack). |
| **Compliance / audit trail estrito**                 | **Temporal**                   | Audit trail nativo, replay determinístico, retention configurável. RabbitMQ exige construir.      |
| **Sagas em voo durante deploy frequentes**           | **Temporal** ou Step Functions | Versionamento explícito; RabbitMQ orquestrado tem T5.1 (silent corruption).                       |
| **Throughput burst alto sustentado (1000+ sagas/s)** | RabbitMQ coreografado          | ~94 sagas/s sequencial; coreografia distribui carga sem coordenador central como gargalo.         |
| **Volume baixo (≤ 100 sagas/min)**                   | Qualquer um — escolher por DX  | Throughput não é decisor; outros critérios pesam mais.                                            |
| **Volume muito alto (10k+ sagas/s sustentados)**     | **Temporal com Cassandra**     | Único combo que escala horizontalmente sem reengineering; mas custo operacional alto.             |

### 4.3 Por característica do time

| Característica                                      | Recomendação                  | Justificativa                                                                                                    |
| --------------------------------------------------- | ----------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| **Time pequeno (≤ 5 devs)**                         | Empate orquestrado / Temporal | Coreografia exige discipline de eventos; com time pequeno qualquer disciplina vira gargalo de uma pessoa.        |
| **Time médio (6-15 devs), múltiplos squads**        | RabbitMQ coreografado         | Cada squad opera seu serviço; coreografia maximiza desacoplamento; sem orquestrador central como gargalo.        |
| **Time grande (15+ devs) com tooling maduro**       | **Temporal**                  | Investimento em determinismo/lib interna se amortiza; observabilidade out-of-the-box vence custo de aprendizado. |
| **Time sem expertise prévia em filas/event-driven** | **Temporal Cloud**            | Curva de RabbitMQ + lib interna é maior que aprender Temporal. Cloud abstrai operação.                           |
| **Time AWS-native, com expertise Step Functions**   | **Step Functions**            | Re-aproveitar conhecimento existente vence ganho marginal de outros.                                             |

### 4.4 Por característica de infraestrutura

| Característica                                                | Recomendação               | Justificativa                                                                                        |
| ------------------------------------------------------------- | -------------------------- | ---------------------------------------------------------------------------------------------------- |
| **Stack monolítica em MariaDB**                               | RabbitMQ (qualquer modelo) | Reusa banco existente; Temporal exige 2º SGBD (MySQL 8 ou Postgres — `findings-temporal.md` §2.2.6). |
| **Stack já em MySQL 8 ou Postgres**                           | Empate Temporal / RabbitMQ | Sem custo de SGBD adicional; decisão por outros eixos.                                               |
| **Multi-DC ativo-ativo obrigatório**                          | Temporal com Cassandra     | Único combo com primitivas multi-DC nativas.                                                         |
| **Sem capacidade SRE para operar Postgres+ES self-hosted**    | **Temporal Cloud**         | Cloud absorve operação. Custo financeiro vira aceitável pelo zero-overhead operacional.              |
| **Budget cloud apertado**                                     | RabbitMQ self-hosted       | ~$2.4-4.8k/12 meses vs ~$58k/ano de Temporal Cloud em escala.                                        |
| **Step Functions free tier suficiente (≤ 4k transições/mês)** | **Step Functions**         | Custo zero até esse limite; cobrança previsível; lock-in AWS aceitável se já é stack AWS.            |

### 4.5 Como navegar a árvore

1. **Pergunte primeiro:** "preciso mesmo de SAGA?" Se for fluxo curto e quase sempre síncrono, idempotência local + retry resolve.
2. **Filtro por SLO de latência.** Se p99 < 50ms é requisito, Temporal sai.
3. **Filtro por SGBD.** Se a stack é MariaDB e adicionar MySQL 8 ou Postgres é deal-breaker, Temporal sai (ou vai pra Cloud).
4. **Filtro por escala de fluxo.** Steps ≤ 3 → coreografia ganha; ≥ 8 → Temporal ganha; entre 4-7, decidir por outros critérios.
5. **Filtro por time/governance.** Compliance estrito → Temporal. Time pequeno + DX simples → orquestrado em RabbitMQ. Múltiplos squads → coreografia.
6. **Decisor final:** custos 12 meses (TCO em 3 cenários em [`consideracoes.md`](./consideracoes.md) §8.1). Faz a conta.

---

## 5. Anti-padrões

Quando NÃO usar a recomendação principal de cada combinação:

- **Não use RabbitMQ orquestrado** se há mudanças frequentes na ordem dos steps. T5.1 (silent corruption) é um risco silencioso e cumulativo — saga marcada `COMPLETED` com state corrompido (estoque 2x, pagamento perdido) sem alerta nem log.
- **Não use coreografia** se o fluxo cresce além de 5-6 steps sem fronteira clara entre serviços. Vira spaghetti. Em coreografia operacional, **construir um Saga Aggregator é trabalho real** (consumer + `saga_view` + UI; ver [`consideracoes.md`](./consideracoes.md) §8.0) — não é "coreografia é grátis".
- **Não use Temporal** se p99 < 60ms é requisito real do produto. O engine adiciona ~40ms baseline.
- **Não use Temporal** se o stack é MariaDB e adicionar 2º SGBD é vetado. MariaDB **não funciona** (Multi-Valued Indexes). MySQL 8 funciona, mas adiciona ~$30-150/mês de RDS/Aurora + ~3 dias eng.
- **Não use Step Functions** se você não está em AWS ou planeja sair. Lock-in é profundo (ASL, IAM, ARNs, CloudWatch, EventBridge).
- **Não use Cassandra** como escolha de SGBD para Temporal "porque é NoSQL". É o mais complexo de operar do trio suportado; só se justifica em escala extrema (10k+ workflows/s, multi-DC).
- **Não use quorum queues em single-node** "para ficar pronto pra HA". Quorum custa **−25% de throughput** mesmo em single-node (consenso Raft em cada `basic_publish`); só se justifica quando há cluster real com replicação multi-node.
- **Não use mirrored queues** (RabbitMQ ≤ 3.x): foram **removidas em 4.0**. Migração para quorum é mandatória ao subir para 4.x.

---

## 5.1 Decisão de design da lib (caso RabbitMQ seja a escolha)

Se a recomendação final apontar para RabbitMQ, a lib interna que precisa ser construída de qualquer forma segue duas regras claras:

1. **v1 implementa apenas o modelo coreografado.** Coreografia resolve a maior parte dos casos esperados (fluxos curtos, multi-squad, baixo acoplamento) e é o caminho que o estudo recomenda como default por anti-padrões já registrados. Construir orquestrado em v1 é trabalho que ninguém vai usar imediatamente.

2. **Design da v1 não fecha a porta para orquestrado em v2.** Significa código defensável hoje — não código especulativo:

   | Princípio                                                    | O que entra na v1                                         | O que NÃO entra                                          |
   | ------------------------------------------------------------ | --------------------------------------------------------- | -------------------------------------------------------- |
   | Transport genérico (publish/consume/reconnect/dedup)         | sim                                                       | —                                                        |
   | Convenção `saga_id` + `saga_name` em todo evento e job       | sim (necessário para idempotência da coreografia)         | —                                                        |
   | `failed()` publica `saga.<id>.failed`; consumers compensam   | sim                                                       | —                                                        |
   | `step_log` + `compensation_log` local por serviço            | sim (validado em PoC)                                     | —                                                        |
   | Interface comum `SagaModelInterface` para os dois modelos    | —                                                         | **não** — não há segundo modelo para justificar          |
   | Tabela `saga_definitions` ou `saga_states` central           | —                                                         | **não** — coreografia não usa, e v1 é só coreografia     |
   | Orquestrador "minimal" sem usuário                           | —                                                         | **não** — código sem usuário é dívida, não preparação    |
   | Hierarquia de classes pré-criada para o segundo modelo       | —                                                         | **não** — refator quando o segundo modelo entrar é menor |

3. **Gatilho explícito para reabrir o orquestrado.** Adicionar suporte a orquestração na lib só vira escopo se aparecer um caso real concreto: 3+ sagas com 5+ steps em fluxos cross-team com requisito de timeline visual centralizada. Sem gatilho, a porta aberta vira encruzilhada permanente — e a equipe acaba abstraindo no ar para o caso que nunca chega.

4. **Versionamento semântico explícito.** v1.x cobre coreografado. Se um dia entrar orquestrado, vai como v2.0 — major version bump, com aviso de breaking change se for o caso. Isso impede a tentação de smuggling silencioso de novo modelo via minor release.

**Resumo da decisão:** v1 é "coreografado bem feito"; "expansão futura" é princípio de design (transport genérico, naming convention), não código adicional. YAGNI é regra firme — se não está sendo usado hoje, não entra.

---

## 6. Quando reavaliar a recomendação

A árvore acima reflete o estado do estudo em 2026-05-04. Reavaliar quando:

- **Spiral Scout perder contrato com Temporal Inc** → re-avaliar SDK PHP (custo de fork é viável; Apache 2.0, 2.4M installs).
- **AWS lançar Step Functions com SDK PHP nativo + custo razoável** → reconsiderar.
- **Volume real se confirmar muito baixo** (<1000/dia agregadas) → reconsiderar SQS + lógica simples + idempotência manual; SAGA dedicada pode ser overhead.
- **Volume crescer além de 10M actions/mês** → Cloud vira financeiramente inviável; planejar self-host com antecedência.
- **MariaDB ganhar Multi-Valued Indexes** → re-testar Temporal × MariaDB; bloqueio atual deixa de existir.
- **RabbitMQ adicionar reconnect automático no `php-amqplib`** → T1.4 deixa de ser gap arquitetural; alternativa minoritária da lib orquestrada fica mais fácil.

---

## 7. Resumo de uma frase

A recomendação final do estudo é uma **árvore de decisão**: para fluxos curtos e múltiplos squads, **RabbitMQ coreografado**; para fluxos longos ou audit trail estrito, **Temporal** (com MySQL 8); para casos AWS-native dentro do free tier, **Step Functions**; e **RabbitMQ orquestrado** como caminho médio quando coreografia não cabe e Temporal é overkill — sabendo de T5.1 e mitigando com lint + code review.
