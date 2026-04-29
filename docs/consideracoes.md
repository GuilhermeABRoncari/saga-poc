# Considerações: pros e contras detalhados por abordagem

> Documento analítico para alimentar a decisão final em [`recomendacao-saga.md`](./recomendacao-saga.md). Complementa o comparativo de alto nível em [`estudo.md`](./estudo.md) e as medições concretas em [`findings-rabbitmq.md`](./findings-rabbitmq.md). É deliberadamente balanceado — cada ferramenta tem custo real e benefício real, e a recomendação só fechará após findings simétricos do PoC Temporal.

---

## 1. RabbitMQ + biblioteca interna `mobilestock/saga`

### 1.1 Pros

#### 1.1.1 Continuidade com a stack atual

- Time já domina filas, AMQP, padrão de consumer/producer, Laravel queues.
- Sem runtime novo (sem RoadRunner, sem segundo modelo de execução).
- Sem `yield`, sem determinismo, sem versionamento de workflow — o código é PHP comum.
- Code review entra na rotina existente; reviewer não precisa aprender API nova.

#### 1.1.2 Controle total de API e ergonomia

- A "casa" desenha o pacote. Convenções de logging, naming, configuração, integração com `laravel-resilience`, integração com Sentry — tudo nosso.
- Customizações específicas do nosso negócio (ex.: integração nativa com `users-api` via HTTP M2M, headers `User-Agent` próprios, telemetria interna) são triviais.

#### 1.1.3 Sem lock-in

- AMQP é padrão aberto (RabbitMQ, ActiveMQ, AWS MQ).
- Self-hosted, controle total de operação.
- Migrar para outra implementação AMQP é re-deploy, não rewrite.

#### 1.1.4 Convive bem com infra híbrida

- Roda em Swarm hoje, em EKS amanhã sem mudança de código.
- Uma única clusterização (RabbitMQ) atende todos os apps, mesmo apps em substratos diferentes.

#### 1.1.5 Durable transport entrega muito sem código nosso

Validado em [`findings-rabbitmq.md`](./findings-rabbitmq.md) §6:

- **Cenário A (kill service-a mid-handler):** RabbitMQ requeue + retomada automática.
- **Cenário B (kill orchestrator com evento em voo):** queue durável absorve mensagens; quando orchestrator volta, consome o backlog.

Manual ack + queue durable + estado em SQLite **já entregam grande parte** do que se espera de "durable execution". Não é necessário escrever toda essa lógica.

#### 1.1.6 RabbitMQ é maduro e battle-tested

- 18+ anos de produção em larga escala.
- Comportamento previsível, documentação extensa, troubleshooting bem documentado.
- Bug residual no broker é raro e geralmente já tem ticket público.

---

### 1.2 Contras

#### 1.2.1 Tudo o que não é transport, você constrói

- State machine de saga (idempotência por step, sequenciamento, recuperação).
- Tabela `saga_state` + repositório.
- Outbox transacional (escrita atômica DB + publish).
- DLX handler + alerting.
- Lógica de retry com backoff exponencial.
- Mecanismo de resume on boot (varrer sagas RUNNING e republicar comandos).
- Observabilidade: dashboard "sagas em andamento / compensadas / órfãs".
- Estimativa em [`findings-rabbitmq.md`](./findings-rabbitmq.md) §4: **3-5 dias engenheiro** só para chegar ao "mínimo aceitável" de observabilidade.

#### 1.2.2 At-least-once obriga idempotência por construção

Identificado no [Cenário C](./findings-rabbitmq.md#63-cenário-c-at-least-once--execução-dupla-gap-identificado-não-testado) do PoC: se o orchestrator morre **entre** atualizar SQLite e publicar o próximo comando (ou entre publicar e ackar a msg que está sendo consumida), no restart a msg é redelivered e o comando republicado → handler executa duas vezes.

Implicações reais em produção:

- `chargeCredit` cobrado duas vezes do cliente.
- `reserveStock` reservando estoque em duplicidade.
- `OAuthClient` criado em duplicidade no `users-api`.

A mitigação **só existe via disciplina**: cada handler precisa checar antes de agir (idempotency_key, dedup table, unique constraints). Nunca é default da plataforma — é responsabilidade permanente do dev. **Em Temporal essa classe de bug não existe** porque o engine garante exactly-once de activity execution via event sourcing.

#### 1.2.3 Sem timeline visual nativa

- RabbitMQ Management UI mostra filas, mensagens, throughput — mas **não** mostra "saga X passou pelos steps Y, Z, W".
- Para saber o que aconteceu numa saga específica, precisamos correlacionar logs de N containers pelo `saga_id` manualmente, ou construir UI custom.
- Postmortem vira arqueologia em log + query SQL na `saga_state`.

#### 1.2.4 Sem replay de execução passada

- Logs efêmeros (ou em ELK, depende do investimento).
- Estado SQLite só guarda o **snapshot atual**, não o histórico completo.
- Para "ver exatamente o que aconteceu na saga 1234 ontem às 14h", precisamos juntar logs de N serviços.
- Precisaria adicionar tabela `saga_events` append-only para ter algo equivalente — **1-2 dias de engenharia** ([`findings-rabbitmq.md`](./findings-rabbitmq.md) §4).

#### 1.2.5 Saga "órfã" sem mecanismo de resume

- Se orchestrator morre permanentemente (deploy quebrado, container OOM ciclico), sagas em estado `RUNNING` ficam paradas indefinidamente.
- A lib **não consulta** `sagas WHERE status='RUNNING'` no boot.
- Mensagens podem estar em filas (recuperáveis) ou já terem sido processadas/ackadas (perdidas).
- **Estimativa para fechar o gap:** 1-2 dias de engenharia + testes.

#### 1.2.6 Bus factor da lib interna

- Quem escreve a lib é a mesma pessoa/time que mantém. Se sai, conhecimento sai junto.
- Roadmap é ad-hoc; sem garantia de manutenção contínua.
- Documentação precisa ser construída e mantida do zero.
- Casos extremos (concorrência, race conditions sutis, edge cases de retry) precisamos descobrir sozinhos — cada um custa engenharia.

#### 1.2.7 "Padronização real" depende de disciplina permanente

- A lib só funciona como padrão organizacional se TODOS os times a usarem corretamente.
- Cada app vai querer divergir em algo: "no nosso caso é diferente porque...". Cada divergência erode o padrão.
- Sem governança ativa (code review centralizado, lint customizado, ADR atualizada), em 12-18 meses cada app tem sua própria versão "modificada" da lib.

#### 1.2.8 Mudança de shape de saga é problema implícito

- Se hoje `completed_steps[].step_index` é um inteiro `[0,1,2]`, e amanhã inserimos um step novo na posição 1, sagas em voo apontam para step errado.
- A solução existe, mas vira responsabilidade da lib OU do dev: migração de dados, status enums versionados, lógica condicional no orchestrator ("se saga começou antes da migração X, siga caminho velho").
- O problema **não desaparece em RabbitMQ** — é apenas implícito (e por isso mais perigoso, porque é fácil esquecer).

#### 1.2.9 Operação em produção

- Clustering em Swarm é doloroso (hostname pinning, volume constraints, peer discovery via classic config).
- RabbitMQ 4.x exige Quorum Queues para HA — Classic Mirrored foram removidas.
- Mínimo 3 nodes para tolerância a falhas reais.
- Recursos: 4GB RAM + 4 cores por node em produção (estimativa).
- Monitoring: Prometheus + Grafana + alertas custom.

---

### 1.3 Como mitigar os contras

| Contra                       | Mitigação                                                                                                                                                               |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Idempotência manual em tudo  | Helper na lib que oferece `IdempotencyKey` derivado de `saga_id + step_name`. Tabela `processed_keys` consultada no início de cada handler. Custo: ~1 dia + disciplina. |
| Sem timeline visual          | Tabela `saga_events` append-only + script de export para Grafana ou UI minimalista. Custo: 1-2 dias.                                                                    |
| Sagas órfãs                  | Método `resumeStuckSagas()` rodando no boot do orchestrator. Custo: 1-2 dias.                                                                                           |
| Bus factor                   | Documentação obrigatória + dois mantenedores formalmente designados + ADR + code review centralizado de mudanças na lib. Custo recorrente.                              |
| Disciplina erodindo o padrão | Lint customizado (PHPStan rule) que detecta uso não-padrão da lib. Custo: 1-2 dias inicial.                                                                             |
| Mudança de shape             | Versionamento explícito de saga_definition (ex.: coluna `saga_version` na tabela). Custo: ~1 dia.                                                                       |

**Custo total agregado para chegar a "produção responsável":** ~10-15 dias de engenharia inicial + manutenção recorrente. Não é proibitivo, mas é recorrente.

---

## 2. Temporal

### 2.1 Pros

#### 2.1.1 SAGA first-class

- API `Workflow\Saga` declarativa: cada `addCompensation()` registra reversão para o step que acabou de rodar.
- LIFO automático na hora da reversão. Sem código nosso.
- Compensação em paralelo opcional (`setParallelCompensation(true)`).

#### 2.1.2 Durable execution out of the box

- Estado da saga vive no engine (event-sourced em Postgres/MySQL/Cassandra), não no nosso banco.
- Sobrevive a crash de worker, restart de cluster, deploy do worker, deploy do server.
- "Mecanismo de resume on boot" é grátis — nem existe esse conceito explícito porque o engine sempre sabe onde retomar.

#### 2.1.3 Exactly-once de activity execution

- Engine garante (via event sourcing + workflow determinístico) que cada activity executa **exatamente uma vez** com sucesso.
- A classe de bug do Cenário C (RabbitMQ+lib) **não existe** aqui. Idempotência ainda é boa prática (em raros casos de timeout + recovery), mas não é responsabilidade central do dev.

#### 2.1.4 Observabilidade entrega timeline rica

- **Temporal Web UI**: cada workflow tem timeline completa, com payload de entrada/saída de cada activity, retries, compensações, decisões. **Postmortem é navegação visual, não SQL.**
- Search por workflow ID, por type, por tag.
- Replay de execução passada literalmente possível (re-rodar workflow do início com histórico antigo).
- Sem custo de engenharia para chegar nesse nível.

#### 2.1.5 Versionamento explícito (custo, mas honesto)

- `Workflow::getVersion()` força o dev a tratar mudanças de shape conscientemente.
- Em RabbitMQ+lib o mesmo problema existe **implícito** (e por isso pior — é fácil esquecer).
- Para sagas curtas (segundos-minutos como nosso `ActivateStoreSaga`), o uso de getVersion é raríssimo: deploy → fila drena → todas as novas sagas usam código novo.

#### 2.1.6 Worker pull model

- Workers fazem long-polling no Temporal server; não precisam de inbound firewall.
- Escala horizontal trivial (subir mais containers).
- Convive com qualquer topologia de rede.

#### 2.1.7 Temporal Cloud reduz overhead inicial

- Free tier para PoC; Essentials ~$100/mês até pequena escala.
- Sem operação de cluster (Postgres + ES + 4 serviços) durante adoção.
- Saída Cloud → self-hosted depois é re-aponte de namespace, sem rewrite de código.

#### 2.1.8 SDK PHP ativo e maduro o bastante

- v2.17.1 (mar/2026), 2.4M installs.
- Spiral Scout mantém sob contrato com Temporal Inc.
- Cobre 100% das primitivas que importam: Workflow, Activity, Saga, Signal, Query, Timer.

---

### 2.2 Contras

#### 2.2.1 Dialética diferente do Laravel — o dev programa em "Temporal", não em "Laravel"

Este é o custo de adoção mais subestimado. **Workflow code não é PHP comum.** É um subset rígido com regras próprias que existem para garantir determinismo (replay correto a partir do event history).

A própria documentação oficial do Temporal lista o que é proibido dentro de Workflow:

> _Always do the following in the Workflow implementation code:_
>
> - _Don't perform any IO or service calls as they are not usually deterministic. Use Activities for this._
> - _Only use `Workflow::now()` to get the current time inside a Workflow._
> - _Call `yield Workflow::timer()` instead of `sleep()`._
> - _Do not use any blocking SPL provided by PHP (i.e. `fopen`, `PDO`, etc) in Workflow code._
> - _Use `yield Workflow::getVersion()` when making any changes to the Workflow code. Without this, any deployment of updated Workflow code might break already open Workflows._
> - _Don't access configuration APIs directly from a Workflow because changes in the configuration might affect a Workflow Execution path. Pass it as an argument to a Workflow function or use an Activity to load it._

Traduzindo para o que o dev sente no dia a dia:

- **`date()`, `time()`, `microtime()` proibidos** dentro de Workflow → usar `Workflow::now()`.
- **`sleep()`, `usleep()`, `time_nanosleep()` proibidos** → usar `yield Workflow::timer()`.
- **`rand()`, `random_int()`, `uniqid()` proibidos** → usar `yield Workflow::sideEffect(fn() => ...)`.
- **DB queries (`PDO`, Eloquent) proibidos** → mover para Activities.
- **HTTP calls (`Guzzle`, `Http::get()`) proibidos** → mover para Activities.
- **`var_dump()`, `dd()`, `echo` para debug não funcionam** (workflow é replay).
- **`config('app.timezone')` proibido** — passar como argumento ou ler via Activity.
- **Iterações sobre `$_ENV`, `getenv()`, `file_get_contents()` proibidas** dentro de Workflow.

Consequências práticas:

1. **Curva de aprendizado real**: dev Laravel-first precisa "desligar" reflexos antigos. Os primeiros 1-2 meses são propensos a bugs sutis ("por que minha saga quebrou no replay se rodou ontem?"). Erro contraintuitivo.
2. **Code review precisa de novo critério**: reviewer agora precisa garantir que o código de Workflow não tem nenhum dos pecados acima. Sem lint, é certo que algo escapa.
3. **Onboarding de novos devs aumenta**: além de "aprender o monorepo", aprende-se "aprender Temporal-PHP". Não é monstruoso, mas é real.
4. **Activities, em compensação, são PHP comum**: dentro delas, tudo é permitido. A dialética só vale para Workflow code. Como a maior parte da regra de negócio mora em Activities, isso atenua mas não elimina o custo.
5. **`yield` em todo lugar muda o estilo de programação**: para quem não tem familiaridade com generators (caso típico em equipe Laravel), é estranho no início. `yield $activities->reserveStock(...)` é a chamada de uma activity.

**Mitigação possível** (mas sempre custo):

- Pacote interno (`mobilestock/laravel-temporal-saga`) que esconde parte das arestas atrás de uma API mais Laravel-ish.
- Lint customizado (PHPStan rule) que detecta `date()`, `rand()`, `PDO`, etc. dentro de classes marcadas com `#[WorkflowInterface]` e falha CI.
- Treinamento + exemplos canônicos no apps/_template_.
- Code review centralizado de mudanças em Workflow code nas primeiras N semanas.

**Avaliação honesta**: este é provavelmente o **maior custo de adoção** do Temporal numa empresa Laravel-first. Não é dealbreaker, mas é semestre de calibração antes de virar background noise.

#### 2.2.2 RoadRunner obrigatório nos workers

- Temporal PHP SDK exige RoadRunner como runtime (long-lived workers, não FPM/CLI tradicional).
- Cada app que orquestra workflows vira "meio Laravel/meio RoadRunner".
- Configuração `.rr.yaml` é mais um arquivo a entender e manter.
- Em EKS, é só mais um Deployment — não é problema em si, mas é mais uma peça móvel.
- Workers em containers separados dos containers HTTP da API → não há contaminação do runtime FPM, mas é mais um set de containers a operar.

#### 2.2.3 SDK PHP é "segunda classe"

- Mantido pela Spiral Scout (sob contrato), não pelo Temporal core team que mantém Go/Java.
- Features novas chegam primeiro em Go/Java. Em 2-3 anos pode haver gap de 6-12 meses.
- Se Temporal Inc decidir cortar contrato com Spiral Scout, situação fica precária — embora o SDK seja OSS e tenha 2.4M installs (custo de fork seria viável).
- **Risco residual**: time PHP fica atrás na curva.

#### 2.2.4 Ecosystem PHP do Temporal é magro

- `temporal/sdk`: 384 stars no GitHub (vs milhares em Go/Java).
- `keepsuit/laravel-temporal`: 50 stars (single mantenedor).
- StackOverflow / GitHub issues / blog posts: ordens de magnitude menos conteúdo que Java/Go.
- Quando algo der errado, "googlar" o problema retorna menos resultados úteis.

#### 2.2.5 Self-hosting é não-trivial

- Cluster Temporal: 4 serviços (Frontend, History, Matching, Worker) + Postgres/MySQL + (opcional) Elasticsearch.
- Helm chart oficial existe, mas operar persistência (Postgres) e indexação (ES) virou trabalho de SRE.
- **Em EKS o overhead é menor** (managed Postgres via Aurora, OpenSearch managed) — mas ainda assim mais peças que RabbitMQ.
- **Em Swarm é não-suportado oficialmente** — precisa Cloud ou EKS.

#### 2.2.6 Lock-in moderado

- API é OSS, mas a "forma de pensar" (workflow + activity + signals + saga) é específica do Temporal.
- Migrar workflows de Temporal para outro engine seria **rewrite**, não re-deploy.
- **Mitigação**: pacote interno isola apps do SDK. Trocar SDK fica concentrado num ponto.

#### 2.2.7 Custo financeiro em Cloud

- Temporal Cloud Essentials: ~$100/mês.
- Growth: ~$200/mês.
- Acima desse volume, vira ~$500-1000/mês rapidamente (cobrança por actions).
- Self-hosted em EKS evita o custo Cloud, mas adiciona ops.

---

### 2.3 Como mitigar os contras

| Contra                 | Mitigação                                                                                                                        |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Dialética diferente    | Pacote interno + lint PHPStan + treinamento + exemplos canônicos. Investimento concentrado nos primeiros 3-6 meses.              |
| RoadRunner             | Containers de worker separados; time não toca config no dia a dia. Apps/_template_ pronto.                                       |
| SDK PHP segunda classe | Pacote interno isola apps. Se SDK estagnar, trocar é trabalho concentrado. Forkar é viável (Apache 2.0).                         |
| Ecosystem magro        | Comprar suporte do Temporal (se Cloud) ou contratar consultoria pontual nas primeiras semanas. Investir em documentação interna. |
| Self-hosting complexo  | Começar Cloud. Migrar para EKS self-hosted **só** quando volume justificar (regra de bolso: >$500/mês de Cloud).                 |
| Lock-in                | Aceitar como custo. API é OSS — não há fornecedor único trancando o produto.                                                     |
| Custo Cloud            | Self-host quando justificar.                                                                                                     |

---

## 3. Cruzamento: o que cada um faz "melhor"

| Critério                             | RabbitMQ + lib interna    | Temporal                                              |
| ------------------------------------ | ------------------------- | ----------------------------------------------------- |
| **Adoção rápida com time atual**     | ✅✅                      | ⚠️ (curva inicial real)                               |
| **Sem lock-in**                      | ✅✅                      | ⚠️ (lock-in moderado, OSS)                            |
| **Durable execution out-of-the-box** | ⚠️ (parcial: queue)       | ✅✅                                                  |
| **Exactly-once de activity**         | ❌                        | ✅✅                                                  |
| **Observabilidade visual de saga**   | ❌ (precisa construir)    | ✅✅                                                  |
| **Compensação first-class**          | ⚠️ (constrói em ~30 LOC)  | ✅✅                                                  |
| **Replay/postmortem**                | ❌ (precisa construir)    | ✅✅                                                  |
| **Versionamento de saga**            | ⚠️ (implícito → perigoso) | ✅ (explícito → custoso mas honesto)                  |
| **DX em code review**                | ✅ (PHP comum, espalhado) | ⚠️ (saga em 1 arquivo, mas precisa entender Temporal) |
| **Operação em produção**             | ⚠️ (clustering RabbitMQ)  | ⚠️ (Temporal cluster ou Cloud)                        |
| **Custo financeiro 12 meses**        | ✅ (só infra)             | ⚠️ (Cloud ou ops EKS)                                 |
| **Bus factor**                       | ❌ (lib interna)          | ✅ (SDK público com 2.4M installs)                    |
| **Maturidade da plataforma**         | ✅✅ (RabbitMQ 18+ anos)  | ✅ (Temporal 5+ anos, mas crescendo rápido)           |

---

## 4. O que ainda só vai ficar claro depois do PoC Temporal completo

- LOC reais de saga + activities + workers (vs 632 do RabbitMQ).
- Tempo de onboarding de um dev Laravel para escrever primeiro workflow (em dias).
- Frequência de erros de determinismo durante desenvolvimento.
- Comportamento real durante deploy mid-flight (rolling restart de workers).
- Cenários de resiliência simétricos aos rodados em RabbitMQ.
- Custo Cloud projetado no volume real esperado.
- DX de code review na prática (uma coisa é a teoria, outra é dois reviewers olhando código real).

Esses números fecham a tabela §3.2 da [`recomendacao-saga.md`](./recomendacao-saga.md) para o lado Temporal. Aí a recomendação pode sair do "em aberto" para "fechada com evidência".

---

## 5. Ângulo que pode mudar tudo

**Pergunta concreta que vale fazer ao tech lead antes de fechar:**

> Com que frequência você espera mudar a **forma** de uma saga (adicionar step no meio, reordenar, mudar compensação) vs mudar **regras de negócio dentro dos passos**?

- Se **a forma muda raramente** (típico): os 6 contras acima do versionamento Temporal somem na prática. Mudanças de regra de negócio vivem em Activities, que são PHP comum, e podem ser deployadas sem `getVersion()`.
- Se **a forma muda toda semana** (atípico, mas possível em ambiente experimental): o custo de versionamento Temporal vira fricção real. Mas isso sinaliza que a saga não é uma abstração estável — e o problema vai existir em qualquer orquestrador (RabbitMQ+lib idem, só que escondido).

A resposta calibra o peso desse critério no comparativo final.
