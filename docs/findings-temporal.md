# Findings: PoC Temporal — medições para [`recomendacao-saga.md`](./recomendacao-saga.md) §3.2

> Documento simétrico ao [`findings-rabbitmq.md`](./findings-rabbitmq.md). Permite preencher a tabela §3.2 com os mesmos critérios. Usado para fechar a recomendação.
>
> ## Achado decisivo (2026-05-04) — Temporal NÃO suporta MariaDB
>
> Tentativa de trocar o backend do Temporal de Postgres para MariaDB (já que o ambiente alvo usa MariaDB em produção) **falhou** em 2026-05-04. Detalhes:
>
> - Imagem: `mariadb:11.4` + `temporalio/auto-setup:1.26` com driver `mysql8`.
> - Migrations 1.0 → 1.14 do schema do Temporal **passam**, mas a partir daí há `CREATE INDEX … ((CAST(json_extract(data, '$.TemporalChangeVersion') AS CHAR(255) ARRAY)))` (sintaxe Multi-Valued Index do MySQL 8) que **MariaDB 11.4 não implementa**.
> - Erro: `Error 1064: You have an error in your SQL syntax; ... near '>"$.TemporalChangeVersion"), ADD COLUMN BinaryChecksums JSON ...'`.
>
> **Backends suportados oficialmente pelo Temporal:** PostgreSQL 12+, MySQL 8.0+, Cassandra 3.11+. **MariaDB não está na lista.** Apesar de MariaDB ser fork do MySQL, divergências em features posteriores (Multi-Valued Indexes, JSON path syntax, generated columns) tornam o Temporal incompatível na prática. Confirmado lendo a [matriz de persistência oficial](https://docs.temporal.io/self-hosted-guide/defaults).
>
> **Implicação para a decisão (impacto fortemente negativo para Temporal):**
>
> 1. Adotar Temporal **obriga** ter um 2º SGBD dedicado ao engine (PostgreSQL ou MySQL 8). O banco principal do ambiente alvo continua MariaDB. Isso significa:
>    - **+1 SGBD para o time de plataforma operar** (backup, replicação, upgrade, monitoring, alertas).
>    - **+1 fonte de divergência** entre dev/staging/prod, considerando que a expertise estabelecida do time é em MariaDB/MySQL e não em Postgres.
>    - **Aurora MySQL/PostgreSQL** se EKS — managed, mas custo operacional adicional vs reusar RDS MariaDB existente.
> 2. Em ambiente de dev local, devs precisam subir Postgres/MySQL para mexer em workflows — não é só "docker compose up". Onboarding fica mais pesado.
> 3. Para equipes sem familiaridade com Postgres, qualquer issue de produção do Temporal vira "preciso aprender Postgres antes de debugar". Time-to-fix piora.
> 4. **A premissa original do estudo era "stack uniforme"**. Temporal quebra essa premissa de forma irreversível.
>
> **Esse achado pode virar a decisão sozinho, mesmo com todos os outros critérios qualitativos a favor de Temporal.** A PoC continua rodando em Postgres apenas para preservar comparabilidade dos números — não como sinalização de que Postgres é viável em produção.

PoC vivo: [`../saga-temporal/`](../saga-temporal/).

---

## 1. Esforço até happy path

| Métrica                                                                | Valor                                                 | Comparação RabbitMQ                       |
| ---------------------------------------------------------------------- | ----------------------------------------------------- | ----------------------------------------- |
| Sessão de implementação                                                | 1 (~3h, contando bugs e build)                        | 1 (~2h)                                   |
| LOC totais (PHP, sem config)                                           | **237**                                               | 632                                       |
| LOC do Workflow (`ActivateStoreSaga`)                                  | 77                                                    | n/a — saga estava espalhada em 6 arquivos |
| LOC dos 4 arquivos de Activities (interface + impl × 2)                | 96                                                    | n/a — handlers em 5 arquivos = 73 LOC     |
| LOC dos 4 scripts em `bin/`                                            | 64                                                    | 140                                       |
| LOC de config (Dockerfile + .rr.yaml + composer.json + docker-compose) | 125                                                   | 80                                        |
| Composer deps                                                          | 3 (`temporal/sdk`, `spiral/roadrunner-cli`, ext-grpc) | 3                                         |
| Containers Docker                                                      | **6** (postgres + temporal + UI + 3 workers)          | 4 (rabbitmq + 3 processos)                |
| Tempo do primeiro `docker compose up --build`                          | **~25 min** (PECL grpc compile)                       | ~2 min                                    |
| Build incremental após cache                                           | < 30s                                                 | < 30s                                     |

**Observação importante:** o Workflow (saga) é **um único arquivo de 77 linhas** em Temporal. Em RabbitMQ, a "lib" precisou de 381 LOC distribuídas em 6 arquivos para entregar a mesma capacidade. O custo está **deslocado para o setup** (PECL compile, RoadRunner, gRPC), não para o código de aplicação.

### Bugs encontrados durante o build

1. **PECL `grpc` compile falha sem `zlib-dev`** no Alpine — header `zconf.h` ausente. `linux-headers` também é necessário.
2. **`apk del $PHPIZE_DEPS` removeu `libstdc++`** junto, e `grpc.so` (extensão C++) precisa dela em runtime. Fix: adicionar `apk add --no-cache libstdc++ zlib` num RUN separado depois do compile.
3. **Race condition na inicialização**: workers tentaram conectar ao Temporal antes do server estar pronto, morreram com `connection refused`. Sem `healthcheck` no temporal-server (auto-setup), `depends_on` não espera. **Resolvido em 2026-04-30** adicionando `healthcheck` no service `temporal` com `tctl --address temporal:7233 cluster health` (start_period 20s, 30 retries) e mudando `depends_on` dos workers/alerter para `condition: service_healthy`. Sobe limpo no primeiro `docker compose up -d`.
4. **Versão de RoadRunner**: `temporal/sdk` v2.17 sugere RoadRunner 2025.1.5+. Funciona com 2024.3.5 mas com warning. Acoplamento SDK ↔ runtime é real.
5. **Import `ActivityProxy`**: tipo precisa vir de `Temporal\Internal\Workflow\ActivityProxy` — namespace `Internal` é "API privada" da SDK. Atrito de DX (e potencial breaking change futura sem aviso).

**Total cumulativo de tempo gasto resolvendo bugs**: ~40 min (vs ~25 min do RabbitMQ). PECL compile sozinho dominou.

---

## 2. Esforço de compensação completa

| Métrica                                     | Valor                                                | Comparação RabbitMQ                     |
| ------------------------------------------- | ---------------------------------------------------- | --------------------------------------- |
| LOC dedicadas a compensação no Workflow     | 4 (duas chamadas `addCompensation` + 1 `compensate`) | ~30 (handlers + lógica no orchestrator) |
| LOC de handlers de compensação (activities) | 12 (releaseStock + refundCredit)                     | ~30                                     |
| Padrão LIFO automático                      | via `Workflow\Saga`                                  | implementado na lib                     |
| Compensação paralela disponível             | via `setParallelCompensation(true)`                  | teria que adicionar                     |

Compensação **funcionou** ponta a ponta no smoke test com `FORCE_FAIL=step3`:

- `confirm_shipping` falhou → retry esgotou (`MAXIMUM_ATTEMPTS_REACHED`) → exception bubbled → `catch` chamou `yield $saga->compensate()` → engine executou `refund_credit` (step 1) → `release_stock` (step 0) em LIFO automático.
- `result: {"status":"COMPENSATED",...}`.
- IDs propagados corretamente: closures de compensação capturaram `$reserve` e `$charge` por valor — engine cuida da serialização determinística.

**Sem código de plumbing manual para o LIFO.** Ganho real vs RabbitMQ.

---

## 3. Observabilidade out-of-the-box

### O que se enxerga sem nenhum investimento extra

- **Temporal Web UI** em http://localhost:8088 entrega:
  - Lista de workflows com status (Running, Completed, Failed, Compensated, Terminated, etc.).
  - Timeline visual de cada execução: cada activity com payload de entrada/saída, retry attempts, timing.
  - History completo (event sourcing): cada decisão de workflow, scheduled/started/completed events, signals, queries.
  - Filtros: por workflow type, status, time range, ID.
  - Replay: pode-se literalmente re-rodar um workflow do início com o histórico antigo.
- **Métricas Prometheus** já expostas pelo Temporal server (workflow rate, latency, retries, etc.).
- **Logs de activities** via stdout dos workers (PHP normal).

### O que NÃO se enxerga sem construir

- Métricas custom de negócio (ex.: "% de sagas que falharam no step de pagamento").
- Alerta crítico "compensação falhou" — Temporal expõe via `WorkflowExecutionFailed`, mas precisa configurar alerting externo.

---

## 4. Esforço para observabilidade aceitável (estimativa)

| Componente                      | Esforço estimado                                    | Comparação RabbitMQ                         |
| ------------------------------- | --------------------------------------------------- | ------------------------------------------- |
| Timeline visual + replay        | **gratuito** (UI Temporal)                          | 1-2 dias (tabela `saga_events` + UI custom) |
| Logs estruturados em activities | 1-2 horas (handlers já são PHP comum)               | 2-3 horas                                   |
| Métricas custom de negócio      | 4-6 horas (instrumentar activities + Grafana)       | mesmo                                       |
| Alerta de compensação falha     | 2-3 horas (Temporal SDK metrics + Prometheus alert) | 4-6 horas (DLX + alerting)                  |
| Search/dedup por saga ID        | gratuito                                            | 4-6 horas (logs estruturados + ELK)         |
| **Total**                       | **~1 dia**                                          | **3-5 dias**                                |

Diferença real: o **trabalho pesado** (timeline + replay + search) que custaria 2-3 dias em RabbitMQ vem **pronto** no Temporal.

---

## 5. DX em code review

### Pontos a favor

- O fluxo da saga **mora num único arquivo** (77 linhas). Reviewer abre `ActivateStoreSaga.php` e lê de cima a baixo: passo 1, sua compensação, passo 2, sua compensação, passo 3, catch + compensate. **Linear**.
- Compensação aparece **junto** da activity correspondente — não é necessário pular para outro arquivo para entender "o que reverte X".
- `yield` é incomum mas se torna repetitivo em poucos dias — fluxo da saga fica claro depois.
- Activities (handlers) são PHP comum — ergonomia idêntica a RabbitMQ no nível do handler.

### Pontos contra

- **Dialética diferente do Laravel** (ver [`consideracoes.md`](./consideracoes.md) §2.2.1): o reviewer agora precisa garantir que código de Workflow não usa `date()`, `sleep()`, `rand()`, `PDO`, `Http::`, `dd()`, `config()`, etc. Sem lint customizado, é certo que algo escapa.
- Imports de tipo internos (`Temporal\Internal\Workflow\ActivityProxy`) — reviewer precisa entender por que estão lá. Atrito.
- Stack trace em compensação tem ~10 frames internos do Temporal — leitura inicial é confusa.

### Comparação direta

Lendo o `ActivateStoreSaga.php` de Temporal vs ler `ActivateStoreSaga.php` (definição) + `SagaOrchestrator.php` + handlers no RabbitMQ: o **primeiro** é perceptivelmente mais legível para entender "o que essa saga faz". O **segundo** tem mais ferramentas para entender "o que essa saga faz quando falha em produção" (porque o fluxo está mais explicito no orquestrador).

Empate qualitativo. Temporal vence em primeira leitura, RabbitMQ vence em "como debugar prod". Mas o RabbitMQ exige observabilidade construída para esse "vence" se concretizar — e essa não está pronta.

---

## 6. Resiliência simulada

### 6.1 Cenário A: kill `service-a-worker` mid-handler

**Setup:** `SLOW_RESERVE_STOCK=12`. Trigger saga, mata `service-a-worker` enquanto está no sleep, restart sem delay.

**Resultado:** saga completou.

**Detalhes:**

- workflow_id `68add43f-…` disparado.
- `reserveStock` começou a sleep no `service-a-worker`.
- Container morto durante sleep.
- Temporal aguardou `StartToCloseTimeout` (20s) → activity declarada timeout → retry agendado.
- `service-a-worker` revivido (sem delay).
- Retry da `reserveStock` executou no worker novo → sucesso (`res_f3a5e992`).
- Saga continuou: `chargeCredit` → `confirmShipping` → COMPLETED.

**Por quê:** Temporal usa task queue + start-to-close timeout. Worker que pega activity é dono dela até completar OU bater timeout. Se worker morre, timeout fira eventualmente e activity é re-agendada. Idêntico ao RabbitMQ requeue, **mas sem precisar configurar nada manualmente** — é comportamento default.

### 6.2 Cenário B: kill `workflow-worker` mid-flight

**Setup:** `SLOW_RESERVE_STOCK=10`. Trigger saga, mata `workflow-worker` ~3s depois (durante o sleep do step 0). Espera 15s, revive.

**Resultado:** saga completou.

**Detalhes:**

- workflow_id `face3fb4-…` disparado.
- `reserveStock` rodando (sleeping 10s) no `service-a-worker`.
- `workflow-worker` morto durante esse sleep.
- `reserveStock` completou (`res_47319b52`) e enviou resultado para o Temporal server.
- Temporal server registrou em history, agendou próximo decision task no task queue `saga-orchestrator`.
- Decision task aguardou ~15s no queue (sem worker).
- `workflow-worker` revivido.
- Workflow worker pega decision task, faz **replay** do workflow (re-executa código a partir do início consultando history) — código produz mesma sequência de chamadas → confirma `chargeCredit` next.
- `chargeCredit` (`chg_01808eef`) → `confirmShipping` (`BR714441`) → COMPLETED.

**Por quê:** **durable execution** — o estado do workflow vive no Temporal server, não no worker. Worker é stateless; é apenas um processo que executa workflow code. Quando volta, replay reconstroi o estado a partir do history. **Sem código de aplicação para isso.**

### 6.3 Cenário C: at-least-once / execução dupla

**Não testado** porque é teoricamente quase impossível em Temporal:

- Activity execution exactly-once é **garantia da plataforma** via event sourcing + workflow determinismo.
- Janela narrow de risco: worker completa activity, mas morre **antes** de enviar o resultado ao server. Server eventualmente declara timeout e re-agenda → activity executa duas vezes.
- A documentação do Temporal explicitamente diz: "**Activities should be idempotent for this rare case**", mas a janela é pequena (segundos) vs RabbitMQ+lib onde a janela é o tempo inteiro de processamento + ack.

**Comparação com RabbitMQ:** no [`Cenário C de findings-rabbitmq.md`](./findings-rabbitmq.md#63-cenário-c-at-least-once--execução-dupla-gap-identificado-não-testado), execução dupla é **certeza** se o orchestrator morrer entre `repo->advance()` e `dispatchStep()` e o handler não for idempotente. Em Temporal, execução dupla exige que o worker morra na janela milisegundos entre completar a activity e enviar o resultado — bem mais raro, e ainda assim mitigável com idempotency_key.

**Veredito:** Temporal **reduz** o problema de at-least-once de "responsabilidade central de todo dev" para "boa prática para rara janela de falha". Isso muda a postura geral da engenharia.

### 6.4 Cenário D: workflow órfão

**Não aplicável.** Em Temporal não existe conceito de "workflow órfão":

- Estado do workflow vive no Temporal server.
- Workers podem morrer todos. Workflow continua "vivo" no server, esperando worker.
- Quando qualquer worker volta, pega decision tasks pendentes e continua.
- Para perder workflow, seria necessário perder o Postgres + não ter backup — catástrofe operacional do server, não preocupação do dev.

**Comparação com RabbitMQ:** órfãs no RabbitMQ+lib são preocupação real e exigem `resumeStuckSagas()` no boot do orchestrator (estimativa: 1-2 dias). **Em Temporal, gap não existe.**

---

## 7. Operação simulada

| Aspecto                | Observação                                                                                                             |
| ---------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Setup local            | `docker compose up --build -d` no primeiro run leva ~25 min (PECL grpc compile)                                        |
| Containers em produção | Temporal: 4 serviços (Frontend/History/Matching/Worker) + Postgres ou MySQL 8 (**não MariaDB** — §2.2.6) + opcional Elasticsearch |
| Cloud option           | Temporal Cloud Free → Essentials ($100/mês) → Growth ($200/mês) → custo escala com actions                             |
| Self-host EKS          | Helm chart oficial + Aurora Postgres ou Aurora MySQL + opcional OpenSearch                                             |
| Self-host Swarm        | **Não suportado oficialmente** — viável só Cloud em ambiente Swarm                                                     |
| Healthcheck            | Resolvido com `tctl cluster health` no service temporal + `depends_on: condition: service_healthy` nos workers/alerter |
| Logs                   | Pelo SDK PHP, via stdout do worker — fácil de integrar com qualquer log aggregator                                     |

### 7.1 Volume de escritas no banco — medido em 2026-04-30

Critério levantado após observar 31+ inserções por workflow em `laravel-workflow`. Medição direta no Postgres do `saga-temporal` (1 saga isolada, contagem antes/depois em todas as tabelas):

| Cenário              | INSERTs por saga | Detalhamento                                                                                          |
| -------------------- | ---------------- | ----------------------------------------------------------------------------------------------------- |
| Happy-path (3 steps) | **38**           | history_node +12, history_tree +1, executions +1, current_executions +1, timer_tasks +12, transfer_tasks +8, visibility_tasks +3 |
| Com compensação (FORCE_FAIL=step3) | **53** | Sobe principalmente em history_node (+18 — retries antes de MAXIMUM_ATTEMPTS_REACHED + compensações) e tasks |

**Comparação:**
- RabbitMQ-orquestrado (`saga-rabbitmq/`): **1 INSERT + 4 UPDATEs** por saga.
- RabbitMQ-coreografado (`saga-rabbitmq-coreografado/`): **0 happy / 2 com compensação**.

**Em escala de produção** (múltiplos serviços × ~1k-10k sagas/dia = 4k-40k sagas/dia):
- Temporal: **152k-2.1M INSERTs/dia** só de metadados.
- Cada retry de Activity adiciona eventos — uma saga que demora pra falhar pode ter 100+ rows em `history_node`.
- Custo Cloud: Cloud cobra por action; cada evento conta. Em volume alto, agrava o custo de $58k/ano em escala já documentado.
- Latência: cada evento exige round-trip de persistência — explica parcialmente o p99 de 351ms vs 22ms do RabbitMQ medido em testes anteriores.

**Veredito do critério:** não desqualifica Temporal sozinho, mas é fator quantitativo concreto que entra no peso da decisão final. Combinado com custo de adoção (~1 semestre) e necessidade de lib interna, fortalece o caso de coreografia em volumes altos.

---

## 2.2.6 Incompatibilidade com MariaDB obriga 2º SGBD na adoção (NOVO — 2026-05-04)

> Detalhes técnicos da falha estão no banner no topo deste documento. Esta seção foca em **impacto na decisão**.

O ambiente alvo deste estudo usa **MariaDB em produção** como banco principal. A premissa original do estudo era manter stack uniforme: tudo o que entrar na arquitetura deve ser opera­cionalmente coerente com o que já existe. **Temporal quebra essa premissa.**

| Dimensão                           | Custo concreto                                                                                                                    |
| ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| **Operação de prod**               | +1 cluster Postgres/MySQL para SREs operarem (backup, restore, replicação, patching, alertas). Não trivial.                       |
| **Custo financeiro**               | Aurora Postgres/MySQL gerenciado: ~$200-500/mês para um cluster pequeno HA. Por sistema, multiplicar.                             |
| **Familiaridade do time**          | Devs e SREs têm fluência em MariaDB/MySQL. Postgres exige curva de aprendizado real (queries, EXPLAIN, vacuum, lock-modes).       |
| **Disaster recovery**              | Runbook do MariaDB principal está pronto. Postgres do Temporal vai precisar do seu próprio runbook, testes de DR, sazonalidades.  |
| **Dev local**                      | docker-compose precisa subir Postgres além do MariaDB do serviço principal. Mais memória, mais setup, mais surface de bug local. |
| **Coerência de observabilidade**   | Métricas de DB do prod (queries lentas, lock waits, deadlocks) usam ferramentas calibradas para MariaDB. Postgres exige outro stack ou adapter. |
| **Risco de divergência dev/prod**  | Se devs testarem em SQLite/MariaDB e prod usar Postgres, classes de bug aparecem só em produção (ex.: MERGE, RETURNING, JSONB).    |

**Custo total estimado de adoção** (incremental, **só por causa do banco**, sem contar Temporal em si): **~3-5 dias de eng** para setup inicial + **~0.5-1 dia/mês recorrente** de operação adicional (patching, monitoring, ajustes).

**Por que isso pode virar a decisão sozinho:**

1. RabbitMQ não tem essa dependência. A PoC RabbitMQ usa SQLite hoje, mas em produção usaria a **mesma instância MariaDB** que os serviços já usam — sem custo operacional adicional.
2. RabbitMQ-coreografado é ainda mais leve: cada serviço tem seu `step_log`/`compensation_log` local em **MariaDB** do próprio serviço (que já existe).
3. Argumentos a favor de Temporal (correção, durable execution, observabilidade) são **arquiteturais**, não compensam um custo **operacional permanente** de 2º SGBD.

**Mitigações possíveis (e suas limitações):**

- **Temporal Cloud** evita o problema de operar Postgres self-hosted. Mas: custo cresce com volume (~$58k/ano em escala já calculado), e não resolve dev local.
- **Mudar prod para Postgres** seria projeto separado de meses, com risco enorme. Fora de escopo.
- **Cassandra** em vez de Postgres: Temporal suporta. Mas Cassandra tem operação ainda mais difícil, sem familiaridade no time. Pior, não melhor.

**Veredito provisório:** este achado adiciona **~3-5 dias de eng + custo operacional permanente** ao TCO de adoção do Temporal, sem retorno para o produto. Combinado com gaps de PoC já documentados (versionamento, T1.4 em RabbitMQ-coreografado já mitigado), **inclina ainda mais a balança para coreografia em RabbitMQ + MariaDB local por serviço**.

> **Nota**: este achado precisa ser pesado **antes** de discutir critérios qualitativos (correção, durable execution). Se ficar acordado que "2º SGBD em prod é deal-breaker", critérios qualitativos viram secundários — Temporal sai. Se ficar acordado que "vale o custo pelo benefício de durable execution", aí sim os qualitativos voltam à mesa.

---

## 8. Custo projetado 12 meses

| Item                                            | RabbitMQ self-hosted            | Temporal Cloud | Temporal self-host EKS      |
| ----------------------------------------------- | ------------------------------- | -------------- | --------------------------- |
| Infra básica                                    | $200-400/mês (3 nodes RabbitMQ) | $100-200/mês   | $250-500/mês (Aurora + EKS) |
| Engenharia para construir lib + observabilidade | 3-5 dias inicial + manutenção   | 0 (SDK pronto) | 0 (SDK pronto)              |
| Engenharia para operar                          | ~1 dia/mês                      | 0 (managed)    | ~1-2 dias/mês               |
| Risco de outage por bug em lib interna          | médio-alto                      | baixo          | baixo                       |
| **Total estimado 12 meses**                     | $2400-4800 + ~12 dias eng       | $1200-2400     | $3000-6000 + ~15 dias eng   |

**Cloud é a opção financeira mais barata no curto prazo.** Self-host EKS empata ou supera RabbitMQ no longo prazo, dado que **economiza 12+ dias de engenharia** que iriam para construir a lib RabbitMQ.

---

## 9. Risco de SDK/lib decair

### `temporal/sdk`

- v2.17.1 (mar/2026), 2.4M installs, 384 stars no GitHub.
- Mantenedor: Spiral Scout sob contrato com Temporal Inc.
- Cobre 100% das primitivas que importam: Workflow, Activity, Saga, Signal, Query, Timer, getVersion.
- Acoplamento com RoadRunner é real (warning de versão observado no PoC).

### Risco residual identificado

- SDK PHP é "segunda classe": features novas vão primeiro para Go/Java. Gap pode chegar a 6-12 meses em features avançadas.
- Se Spiral Scout perder contrato com Temporal Inc, situação fica precária — mas SDK é OSS (Apache 2.0); fork é viável.
- Imports de tipos `Internal\` são API privada do SDK — pode quebrar entre minor versions sem aviso.

### Mitigação (mesma que [`consideracoes.md`](./consideracoes.md) §2.3)

- Pacote interno (lib de saga sobre Temporal) que isola apps do SDK.
- Treinamento focado em workflow code (determinismo, yield, getVersion).
- Lint customizado (PHPStan rule) para detectar `date()`, `rand()`, `PDO` em workflow code.

---

## 10. Comparação direta com RabbitMQ (tabela final)

| Critério                                   | RabbitMQ + lib interna             | Temporal                                       | Quem ganha                 |
| ------------------------------------------ | ---------------------------------- | ---------------------------------------------- | -------------------------- |
| LOC totais (PHP)                           | 632                                | 237                                            | **Temporal (-62%)**        |
| LOC do orquestrador                        | 381 (lib em 6 arquivos)            | 77 (workflow em 1 arquivo)                     | **Temporal (-80%)**        |
| Setup local 1ª vez                         | ~2 min                             | ~25 min (PECL grpc compile)                    | **RabbitMQ**               |
| Composer deps                              | 3                                  | 3                                              | empate                     |
| Containers                                 | 4                                  | 6                                              | **RabbitMQ** (marginal)    |
| Cenário A (kill service mid-handler)       | via requeue                        | via timeout+retry                              | empate                     |
| Cenário B (kill orchestrator mid-flight)   | via queue durable                  | via durable execution                          | empate                     |
| Cenário C (at-least-once / execução dupla) | certeza sem idempotência           | exactly-once garantido                         | **Temporal**               |
| Cenário D (saga órfã)                      | gap real (1-2 dias para fechar)    | não existe                                     | **Temporal**               |
| Observabilidade default                    | logs + Mgmt UI básico              | timeline + replay + search                     | **Temporal**               |
| Esforço para observabilidade aceitável     | 3-5 dias eng                       | ~1 dia                                         | **Temporal**               |
| DX em code review (1ª leitura)             | saga em 3-4 arquivos               | saga em 1 arquivo                              | **Temporal**               |
| DX em code review (manter)                 | PHP comum                          | exige lint para evitar bugs de determinismo    | **RabbitMQ**               |
| Curva de aprendizado                       | baixa                              | semestre de calibração                         | **RabbitMQ**               |
| Lock-in                                    | AMQP padrão aberto                 | moderado (OSS, mas API específica)             | **RabbitMQ**               |
| Custo financeiro 12 meses                  | ~$2400-4800 + 12 dias eng          | ~$1200-2400 (Cloud)                            | **Temporal** (curto prazo) |
| Operação self-host Swarm                   | viável                             | não suportado                                  | **RabbitMQ**               |
| Operação EKS                               | Helm                               | Helm                                           | empate                     |
| Bus factor                                 | lib interna                        | SDK público                                    | **Temporal**               |

**Score qualitativo:**

- **Temporal vence**: 9 critérios (durable execution, observabilidade, exactly-once, LOC, custo).
- **RabbitMQ vence**: 5 critérios (setup, curva, lock-in, Swarm, manter código).
- **Empate**: 3 critérios.

A vitória de Temporal é **na infraestrutura técnica**. A vitória de RabbitMQ é **na ergonomia humana** (curva, dialética, lock-in).

---

## 11. Pontos que ainda merecem teste

- [ ] Deploy mid-flight com **mudança de código de Workflow** (sem `getVersion()` → falha replay; com `getVersion()` → funciona). Crítico para validar a tese da §1 de [`consideracoes.md`](./consideracoes.md).
- [ ] Carga: 1000 sagas concorrentes em ambos PoCs.
- [ ] Comportamento sob falha de rede entre worker e Temporal server.
- [ ] Compensação que falha (atualmente retry esgota e workflow fica em estado custom — Temporal expõe alerta?).

---

## 12. Veredito preliminar (antes de fechar)

Com os critérios já medidos:

- Para a **infraestrutura de SAGA** propriamente dita, **Temporal entrega muito mais com muito menos código**, com observabilidade superior e gaps de resiliência (Cenário C, D) que o RabbitMQ+lib só fecha com investimento de engenharia recorrente.
- O **custo de adoção** do Temporal é real e concentrado em: (1) curva de aprendizado da dialética determinística, (2) PECL grpc no build, (3) RoadRunner como runtime extra. Mitigáveis com pacote interno + lint + treinamento — investimento concentrado no primeiro semestre.
- O **trade-off final** depende de quanto se valoriza: **(A) capacidade técnica out-of-the-box** (vence Temporal) vs **(B) familiaridade do time + sem fricção de adoção** (vence RabbitMQ).

A decisão final cabe à liderança técnica — estes findings dão a base, mas o peso relativo entre A e B é decisão de produto/cultura, não de engenharia pura.
