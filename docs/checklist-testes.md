# Checklist de testes comparativos — RabbitMQ vs Temporal vs Step Functions

> Lista viva de testes para gerar evidência objetiva. Cada teste tem **como executar**, **o que medir** e **espaço para anotar resultado**. Resultados consolidados depois alimentam [`findings-rabbitmq.md`](./findings-rabbitmq.md), [`findings-temporal.md`](./findings-temporal.md), [`findings-step-functions.md`](./findings-step-functions.md) e a tabela §3.2 de [`recomendacao-saga.md`](./recomendacao-saga.md).
>
> ## Escopo dos testes
>
> Os 20 testes Tier 1-6 abaixo foram projetados para o **modelo orquestrado** (orquestrador central + state machine). Uma segunda iteração do estudo trouxe o **modelo coreografado** para a comparação (sem orquestrador, lib mínima de compensação por evento), e identificou-se que falta cobertura comparável para esse caso.
>
> Quando a 4ª PoC (`saga-rabbitmq-coreografado/`) for construída, alguns testes não se aplicarão (T5.1 reordenamento) e novos testes serão adicionados (compensação out-of-order, handler não-idempotente, loop de eventos). Cada teste afetado tem nota explícita.
>
> **Histórico de execução:** os 20 testes foram primeiro rodados contra **RabbitMQ + Temporal** (Tier 1-6). Cada teste abaixo registra "Resultado RabbitMQ" e "Resultado Temporal". Após decisão preliminar, a **3ª PoC (Step Functions/LocalStack)** foi executada com os mesmos critérios; os resultados consolidados estão em [`findings-step-functions.md`](./findings-step-functions.md) (não duplicados aqui para manter o documento legível).
>
> Convenção de status:
>
> - `[ ]` não executado
> - `[~]` em andamento
> - `[x]` executado, resultado anotado
> - `[!]` executado, identificou bloqueio/bug que precisa ser tratado antes de continuar
> - `[skip]` pulado / não-executável neste contexto (ex.: requer dev externo, requer credenciais Cloud)

---

## Tier 1 — Alto valor, baixo custo

### T1.1 Versionamento de Workflow code (sagas em voo)

- [x] **Executado.**
- **Como executou:**
  - Temporal: disparar saga com `yield Workflow::timer(20)` adicionado no início do workflow. Durante esses 20s de espera, remover o `timer(20)` do código, copiar para o container, restartar workflow-worker. Quando timer expira, Temporal tenta replay com novo código.
  - RabbitMQ: disparar saga com `SLOW_RESERVE_STOCK=15`. Durante o sleep do step 0, editar `definition()` da saga inserindo novo step `audit_log` entre `reserve_stock` e `charge_credit`, copiar para container, restartar orchestrator.
- **Resultado Temporal:**
  - Workflow `fa9e6292…f93d358` ficou travado em loop de replay panic. Logs mostraram `[TMPRL1100] During replay, a matching Timer command was expected in history event position 5. However, the replayed code did not produce that.`
  - Temporal continuou retentando indefinidamente (Attempt 4, 5, 6, 7, 8…) até ser terminado manualmente via `tctl workflow terminate`.
  - **Comportamento explícito e seguro**: workflow não avança até dev resolver a divergência (com `Workflow::getVersion()` ou rebote de versão).
- **Resultado RabbitMQ:**
  - Saga `f91e9266…d989e4b` **completou silenciosamente** com a definição ANTIGA, ignorando o step `audit_log` adicionado.
  - completed_steps final: `[reserve_stock, charge_credit, confirm_shipping]` (3 steps, sem audit_log apesar do código novo definir 4 steps).
  - Nenhum erro, nenhum aviso, nenhum log. A saga seguiu o fluxo antigo até o fim.
  - **Comportamento implícito e perigoso**: orchestrator não detecta divergência; em produção, sagas em voo durante deploy executariam a definição antiga sem qualquer sinal.
- **Veredito:** Temporal força o dev a tratar versionamento (custoso, mas honesto). RabbitMQ esconde o problema (ergonômico, mas armadilha silenciosa). Validação empírica direta da hipótese registrada em [`consideracoes.md`](./consideracoes.md) §1.2.8.
- **Notas:**
  - O cenário comum em produção (deploy enquanto sagas executam) afeta as duas plataformas. A diferença é apenas qual delas avisa.
  - Para Temporal, mitigar com `Workflow::getVersion()` é prática documentada e bem suportada.
  - Para RabbitMQ, mitigar exige disciplina manual: campo `saga_version` na tabela + lógica condicional no orchestrator (custo: ~1 dia de eng + risco permanente de esquecer em algum deploy).

### T1.2 At-least-once / execução dupla no RabbitMQ (Cenário C empírico)

- [!] **Executado — não reproduziu duplicação na janela testada.**
- **Como executou:**
  - Injetado `sleep(8)` em `SagaOrchestrator::onEvent` entre `repo->advance()` e `dispatchStep()` via env var `INJECT_SLEEP_AFTER_ADVANCE=8`.
  - Disparado saga (sem `SLOW_RESERVE_STOCK`).
  - `docker kill saga-rabbitmq-orchestrator-1` aos 4s (durante o sleep injetado).
  - Restart do orchestrator 2s depois.
- **Resultado:** saga `ad3aea28` completou normalmente com `completed_steps=[reserve, charge, confirm]` — **sem duplicação observável em DB ou logs de service-b**.
- **Hipótese para o resultado:**
  - Default heartbeat AMQP é 60s. `docker kill` (SIGKILL) fecha TCP imediatamente, mas RabbitMQ broker pode levar até 60s para detectar conexão morta e requeue mensagem unacked.
  - Se reentregue dentro dos 60s, mensagem ainda está "delivered to dead consumer" — broker não redistribui para o novo consumer ainda.
  - Saga ficou stuck em RUNNING até a mensagem ser eventualmente requeued; aí novo orchestrator processou normal sem segundo advance porque (provavelmente) ele já tinha avançado a saga antes do kill e a re-execução não encontrou step.completed pendente.
- **Conclusão:** o gap §6.3 de [`findings-rabbitmq.md`](./findings-rabbitmq.md) é teoricamente válido mas a janela de reprodução exige timing específico (heartbeat AMQP curto, restart muito rápido). Em produção real isso pode acontecer com `consumer_timeout`/`heartbeat` configurados de forma diferente — vale registrar como **risco residual**, não certeza.
- **Notas:**
  - Em Temporal, o problema é estruturalmente eliminado: history event sourcing + workflow determinístico garantem exactly-once de activity execution. Cenário C é praticamente impossível.
  - Recomendação para a recomendação final: rebaixar este risco de "certeza sem idempotência" (linguagem dos findings atuais) para "janela de risco condicional ao timing AMQP" — mas manter a recomendação de idempotência por construção.

### T1.3 100 sagas concorrentes — throughput e latência

- [x] **Executado.**
- **Como executou:** scripts `bin/batch-trigger.php` em ambos PoCs disparam 100 sagas em sequência rápida e aguardam todas finalizarem (RabbitMQ via poll de SQLite, Temporal via `getResult()`).
- **Resultado RabbitMQ:**
  - Fire (publicar 100 trigger commands): **313ms**
  - Tempo total até 100 COMPLETED: **705ms** (~142 sagas/s) — _re-medido em RabbitMQ 4.3 + Khepri; em 3.13 era 2076ms / 48 sagas/s_
  - Tempo após fire: **1763ms**
  - Falhas: 0
  - Memória total stack após teste: **~145 MiB** (rabbitmq 110 + orchestrator 14 + service-a 8 + service-b 8 + alerter 6) — _re-medido em 4.3; em 3.13 era ~187 MB_
- **Resultado Temporal:**
  - Fire (start 100 workflows via gRPC): **826ms**
  - Tempo total até 100 COMPLETED: **3569ms** (~28 sagas/s)
  - Tempo após fire: **2744ms**
  - Falhas: 0
  - Memória total stack após teste: **~629 MB** (postgres 149 + temporal 156 + ui 27 + workers 96+99+102)
- **Comparação:**
  - **Throughput:** RabbitMQ 4.3 **~5x mais rápido** que Temporal (142 vs 28 sagas/s). Em 3.13 era ~1.7x — o salto vem do Khepri/Raft removendo overhead de Mnesia.
  - **Memória:** Temporal ~3.4x mais pesado em RAM total da stack.
  - **Overhead da plataforma:** Temporal paga gRPC + decision tasks + history persistence em Postgres; RabbitMQ é `publish + consume + UPDATE SQLite` direto.
  - Para volume agregado < 100 sagas/min: **ambos são adequados**. Throughput não é diferencial real no volume esperado.
- **Notas:**
  - p50/p95/p99 individuais não medidos (script atual só captura wall clock total). Para conseguir percentis, modificar trigger para timestampar cada saga.
  - Memória idle pré-teste não capturada — útil para Tier 3 (T3.2).

### T1.4 Falha do Postgres do Temporal mid-flight (e análogo RabbitMQ)

- [x] **Executado.**
- **Como executou (Temporal):** `SLOW_RESERVE_STOCK=15`, disparou saga, `docker stop saga-temporal-postgresql-1` aos 4s, esperou 30s, restartou Postgres.
- **Como executou (RabbitMQ — análogo):** mesma saga lenta, mas matando RabbitMQ broker em vez de Postgres aos 4s, esperou 30s, restartou.
- **Resultado Temporal:**
  - Saga `c384ddae…07cfe67d` completou normalmente após Postgres voltar.
  - Workers Temporal continuaram rodando (ficaram em retry de gRPC).
  - Após Postgres voltar, Temporal retomou estado e completou a saga em poucos segundos.
  - **Durable execution validado empiricamente.**
- **Resultado RabbitMQ:**
  - Quando RabbitMQ caiu, **TODOS os 3 workers PHP morreram** (orchestrator, service-a, service-b) com `php-amqplib` lançando exception fatal:
    ```
    PhpAmqpLib\Exception\AMQPProtocolConnectionException: CONNECTION_FORCED - shutdown
    ```
  - Containers ficaram em status `Exited (255)` indefinidamente — **não auto-reconectam**.
  - Após RabbitMQ voltar, sagas em voo ficam stuck até `docker compose up -d` manual.
  - Mensagens permanecem na queue durable, então após restart manual a saga retomou normalmente. Mas requereu intervenção operacional.
- **Comparação:**
  - **Temporal:** workers resilientes a falha de servidor; reconexão automática; saga retoma sozinha.
  - **RabbitMQ (PoC):** lib não trata reconexão; falha de broker = workers caídos = saga stuck até intervenção humana.
  - **Mitigação para RabbitMQ:** envolver `consume()` em try/catch + loop de reconexão com backoff. Custo: ~0.5 dia de eng + testes. Não é trabalho gigante, mas é mais um item da lista "tudo que você precisa construir" da lib interna.
- **Notas:**
  - Esta é uma **assimetria importante** que não estava clara no findings inicial.
  - Em produção, "RabbitMQ caído" raramente acontece em cluster com 3 nodes + quorum queues, mas SDKs maduros como `vladimir-yuldashev/laravel-queue-rabbitmq` têm reconexão automática. A lib que foi feita no PoC não tem.
  - Atualizar [`consideracoes.md`](./consideracoes.md) §1.2.1 e §1.3 para incluir "reconexão automática de workers" como item da lista do que a lib precisa entregar para produção.

---

## Tier 2 — Mais trabalho, fecha lacunas grandes

### T2.1 Dashboard Grafana mínimo no RabbitMQ

- [skip] **Não executado nesta sessão. Estimativa preservada como prevista.**
- **Por que não foi executado:** construir um dashboard "mínimo aceitável" honesto requer:
  1. Subir Prometheus + Grafana no compose (~30 min).
  2. Instrumentar a lib (`SagaOrchestrator`, `ServiceWorker`) com counters e histograms expostos via HTTP por uma extensão PHP (`/metrics` endpoint). Não é trivial em PHP CLI puro — geralmente exige `prometheus_client_php` + servidor HTTP embutido. (~3-4 horas)
  3. Definir e validar 4-5 métricas mínimas: `saga_started_total`, `saga_completed_total`, `saga_compensated_total`, `saga_failed_total`, `saga_duration_seconds_bucket`. (~1-2 horas)
  4. Construir dashboard Grafana com queries PromQL razoáveis (sagas em andamento, % compensadas, p95 duração, breakdown por saga type). (~2-3 horas)
  5. Validar que números fazem sentido com carga real (rodar T1.3 de novo e ver dashboard popular). (~1 hora)
  - **Total honesto: 1-1.5 dia engenheiro só para mínimo dashboard local. Para chegar a "produção" (alertas, SLOs, retention, multi-tenant) os 3-5 dias da estimativa original ficam em pé.**
- **O que mudou na visão depois dos testes T1-T2.3:** a estimativa original de "3-5 dias para mínimo aceitável" em [`findings-rabbitmq.md`](./findings-rabbitmq.md) §4 **continua válida** — a complexidade não está em construir cada peça, está em integrá-las e descobrir os edge cases (cardinality de labels, granularidade de buckets, semântica de "saga em andamento" sem race contra DB). Em Temporal Web UI esses 1.5 dias custam zero.
- **Por que importa o número:** o "tempo até observabilidade decente" é o critério que mais pesa contra RabbitMQ no padrão sustentável. Não basta ter ferramentas (Prometheus + Grafana são free); o trabalho de instrumentar + manter dashboards é recorrente, e a lib precisa não regredir nos counters cada vez que muda.
- **Resultado:** estimativa validada em mente, não em prática. Caso necessário implementar para reforçar o argumento, pode ser executado num spike isolado de 1-2 dias.
- **Notas:** atualmente Temporal Web UI já entrega timeline/replay/search out-of-the-box. RabbitMQ Mgmt UI mostra **filas** (transport-level), não **sagas** (semantic-level). Construir o segundo é o trabalho recorrente.

### T2.2 Alerta "compensação falhou" em ambos

- [x] **Implementado e validado.**
- **Como executou:**
  - Criados dois alerters (`saga-rabbitmq/bin/alerter.php` e `saga-temporal/bin/alerter.php`), cada um adicionado como service no respectivo `docker-compose.yml`. Output formatado em arquivo `/app/storage/alerts.log` + stderr.
  - **RabbitMQ alerter:** poll em SQLite a cada 2s — `SELECT id, status, current_step, updated_at FROM sagas WHERE status='FAILED'` + cache de IDs já alertados em memória.
  - **Temporal alerter:** poll via `WorkflowClient::listWorkflowExecutions()` com filtro `WorkflowType='ActivateStoreSaga' AND ExecutionStatus='Failed'` a cada 2s + cache de IDs já alertados.
  - Disparado cenário de falha terminal em ambos (`FORCE_FAIL=step3 FAIL_COMPENSATION=refund`), os dois alerters detectaram e logaram.
- **Resultado RabbitMQ:**
  - LOC do alerter: **41 linhas** (script standalone, sem dependências adicionais — usa `PDO` que já estava no projeto).
  - Saga `b826792b` disparada em `16:45:25.979`, alerta em `16:45:26` → **lag de detecção: ~1s** (dentro do polling de 2s + 0 visibility delay).
  - Service no compose: 1 container extra (~10 MB RAM idle).
  - Pré-condição: orchestrator precisa estar emitindo `compensation.failed` E setando `status='FAILED'` no DB (mudança da lib feita em T1.2).
- **Resultado Temporal:**
  - LOC do alerter: **43 linhas** (script standalone usando `temporal/sdk` que já estava no projeto).
  - Workflow `f401b33e` disparado em `16:46:03.437`, alerta em `16:46:10` → **lag de detecção: ~7s**.
  - Decomposição do lag de 7s: ~3s de retries de confirmShipping + ~3s de retries de refundCredit (compensação que falha) + ~1s de visibility do Temporal (delay padrão entre evento e listagem).
  - Service no compose: 1 container extra (~100 MB RAM idle, dominado por gRPC/RoadRunner).
  - Pré-condição: nenhuma — Temporal expõe `ExecutionStatus='Failed'` automaticamente quando workflow falha terminalmente.
- **Comparação direta:**
  | Métrica | RabbitMQ | Temporal |
  |---|---|---|
  | LOC do alerter | 41 | 43 |
  | Lag de detecção | ~1s (polling 2s) | ~7s (visibility delay + workflow finalização) |
  | Memória idle | ~10 MB | ~100 MB |
  | Mudanças necessárias na lib | sim (emitir `compensation.failed` + setar `status=FAILED`) | nenhuma |
  | Resilência se DB cair | poll falha graceful, retoma | poll falha graceful, retoma |
- **Análise:**
  - **Custo de implementação ficou parecido (40 LOC ambos)** porque a abordagem escolhida foi "polling de status" nos dois — é simples, robusta e não exige Prometheus/AlertManager.
  - **A vantagem que o Temporal mantém está em "out of the box"**: o workflow já fica `Failed` automaticamente quando compensação falha, sem precisar mudar código próprio. No RabbitMQ houve necessidade de adicionar emissão de `compensation.failed` na ServiceWorker + handler no Orchestrator que seta `FAILED` (~12 LOC extras feitas em T1.2). Sem isso, o alerter não tem nada para detectar.
  - **Tempo de detecção:** RabbitMQ é mais rápido aqui porque a finalização da saga não depende de retries demorados (compensação publish-and-forget); Temporal espera retries esgotarem por design. Para o uso "alertar quando algo dá errado", ambos são aceitáveis.
  - **Para ir até produção**: ambos precisam dispatch real para Slack/PagerDuty (~20 LOC + secret) + dedup persistente (atualmente em memória, perde estado se alerter reinicia). Custo: ~1-2 horas adicionais em qualquer um dos dois.
- **Notas:**
  - Tempo total para implementar T2.2 ponta a ponta (incluindo debug): ~12 minutos. Bem mais rápido que a estimativa anterior de 4-6 horas — porque o "trabalho pesado" (lib emitir compensation.failed) já foi feito no T1.2.
  - O resultado **enfraquece o argumento "Temporal entrega alertas grátis vs RabbitMQ exige construção"** que estava nos findings. Com a lib RabbitMQ minimamente correta (emitindo eventos de falha), implementar alerter é trivial.
  - O argumento que **se mantém forte** é: Temporal entrega a `ExecutionStatus='Failed'` AUTOMATICAMENTE para QUALQUER tipo de falha (timeout, error, panic, retries esgotados) sem código próprio; RabbitMQ exige que cada caminho de falha seja explicitamente convertido em `status='FAILED'` na lib. Quanto mais caminhos de falha, mais código + mais chance de esquecer um.

### T2.3 Compensação paralela (só Temporal tem)

- [x] **Executado.**
- **Como executou:**
  - Adicionado `SLOW_COMPENSATION=3` em `releaseStock` e `refundCredit` (ambas dormem 3s antes de retornar).
  - `FORCE_FAIL=step3` para forçar compensação.
  - Saga atual tem 2 compensações: release_stock (service-a) + refund_credit (service-b).
  - Temporal: rodado uma vez com `setParallelCompensation(false)`, outra vez com `setParallelCompensation(true)`.
  - RabbitMQ: rodado natural (arch atual: 1 compensação por service distinto).
- **Resultado Temporal:**
  - **Sequencial (`setParallelCompensation(false)`):** 9.55s wall total (~3s retries de confirmShipping + ~6s compensação sequencial 3+3).
  - **Paralelo (`setParallelCompensation(true)`):** 6.38s wall total (~3s retries + ~3s compensação paralela).
  - **Diferença: ~3s economizados** com 1 linha de código.
  - Switch entre os dois modos: literal mudança de `false` ↔ `true`. Sem rebuild de infra, sem migração.
- **Resultado RabbitMQ:**
  - Compensações naturalmente **paralelas** porque cada uma roda em service distinto (release_stock → service-a, refund_credit → service-b). Cada service consome de queue independente.
  - Logs de service-a e service-b mostraram início simultâneo (ambos `16:29:09.400`) e fim simultâneo (ambos `16:29:12.400`).
  - **Duração real: ~3s** (paralelo).
  - **Mas:** orchestrator marcou DB como `COMPENSATED` em **103ms** (publicou compensações na fila e seguiu — não esperou ack). Estado no DB **mente sobre completion real da compensação**.
- **Análise da assimetria:**
  - **Temporal:** comportamento explícito por saga (`setParallelCompensation` é declaração intencional). DB do Temporal só marca COMPENSATED após todas as activities de compensação terminarem.
  - **RabbitMQ:** comportamento implícito pela topologia (parallel-across-services / sequential-within-service via `basic_qos=1`). DB do orchestrator marca COMPENSATED imediatamente após publish — diverge da realidade.
  - **Para forçar sequencial em RabbitMQ:** colocar todas as compensações no mesmo service (qos=1 serializa) OU adicionar lógica de wait-for-ack no orchestrator (~30 LOC + correctness testing).
  - **Para forçar paralelo dentro do mesmo service:** subir múltiplos consumers OU aumentar `basic_qos` (afeta correctness em outros fluxos).
  - **Para corrigir a divergência DB↔realidade:** mudar orchestrator para consumir eventos de `compensation.completed` e só marcar COMPENSATED quando todas chegarem (~25 LOC).
- **Notas:**
  - O ganho real do Temporal aqui não é "paralelo vs sequencial" — RabbitMQ pode dar paralelo por arquitetura. É a **explicitude** + **observabilidade correta**: em Temporal, `setParallelCompensation` é uma decisão consciente registrada no código; em RabbitMQ, o comportamento depende de quais services você atinge e o DB pode dizer "compensado" enquanto handlers ainda rodam.
  - Item para acrescentar nos critérios de produção do RabbitMQ: orchestrator deve esperar `compensation.completed` antes de setar COMPENSATED. Atual lib não faz.

### T2.4 Code review blind test

- [skip] **Não executável dentro do escopo da PoC.**
- **Por que:** o teste exige um dev externo que nunca viu o repo. Tem que ser pessoa real, presencial ou via call, com tempo de uns 30-45 minutos. Não dá para simular sozinho — viés do autor é total.
- **Como conduzir quando puder:**
  1. Pegar dev que nunca viu este repo (idealmente Laravel-first, intermediário/sênior — perfil típico do time).
  2. Pedir para explicar em voz alta o fluxo da saga lendo só os arquivos (sem rodar).
  3. Cronometrar tempo até dev dizer "entendi" e anotar perguntas que faz.
  4. Fazer um para `saga-rabbitmq` e outro para `saga-temporal` (ordem aleatória pra evitar viés de cansaço).
- **Hipóteses a validar quando rodar:**
  - Temporal: dev demora mais inicialmente para entender o `yield` + `Workflow::` + decorators, mas depois explica fluxo de cima a baixo lendo um arquivo.
  - RabbitMQ: dev entende rápido cada peça (PHP comum), mas demora a ligar todas: `definition()` → `dispatchStep` → `ServiceWorker.dispatch` → `emit` → `onEvent`. Mais perguntas do tipo "onde isso é tratado?".
- **Sugestão de checklist de perguntas para o dev:**
  - O que acontece quando step 3 falha?
  - Como step 1 sabe o `reservation_id` do step 0?
  - Onde mora a regra "compensa em ordem LIFO"?
  - O que acontece se o orchestrator/workflow morre no meio?
  - Onde olhar pra ver o que aconteceu na saga 1234?
- **Resultado RabbitMQ:**
- **Resultado Temporal:**

---

## Tier 3 — Operacional / custo real

### T3.1 Tempo até dev novo subir o ambiente

- [skip/~] **Não executado totalmente — purgar cache Docker e re-baixar/re-compilar imagens consumiria ~30-40min de CI/eng. Estimativa baseada em medições parciais e dados do [`findings-temporal.md`](./findings-temporal.md) §1.**
- **Componentes da medição (estimados):**
  - **RabbitMQ:**
    - `git clone`: ~5s (rede)
    - `docker compose up --build`: ~2 min (php:8.3-cli-alpine + apk install + composer install + COPY)
    - Primeira saga ponta a ponta: < 1s (transporte é direto)
    - **Total: ~2-3 minutos**
  - **Temporal:**
    - `git clone`: ~5s (rede)
    - `docker compose up --build`: ~25 min (PECL `grpc` compile domina; medido em [`findings-temporal.md`](./findings-temporal.md) §1, bug 1)
    - Race condition de inicialização (workers tentam conectar antes do server pronto) → workers morrem na primeira tentativa, precisam re-up. Adiciona ~30s de retry manual.
    - Primeira saga ponta a ponta: ~3s (gRPC + decision task overhead)
    - **Total: ~25-26 minutos**
  - **Comparação rápida (ambos com imagens cacheadas, T3.2):**
    - RabbitMQ cold start: **5.93s** + healthcheck → ~10s até saga rodar
    - Temporal cold start: **6.07s** mas workers morrem por race → ~25s adicionais para re-up + workers conectarem → **~30s até saga rodar**
- **Conclusão:** estimativa do findings (`~25 min` Temporal vs `~2 min` RabbitMQ) **está em pé**. Mas para devs cotidianos (que já têm cache), a diferença encolhe para ~3x (10s vs 30s).
- **Notas:** o "PECL grpc compile" do Temporal é one-time per machine. Na prática um time inteiro só sente uma vez por dev. Não é trivial reduzir — RoadRunner + grpc são requisitos do SDK PHP.

### T3.2 Footprint de memória idle e cold start

- [x] **Executado.**
- **Como executou:** `docker compose down` em ambos, `docker compose up -d` cronometrado, esperar 30s idle, `docker stats --no-stream`.
- **Cold start (imagens cacheadas):**
  - **RabbitMQ:** `wall_seconds=5.93`, todos os 5 containers up + rabbitmq healthy em ~10s.
  - **Temporal:** `wall_seconds=6.07`, mas race condition matou os 3 workers (já documentada em [`findings-temporal.md`](./findings-temporal.md) §1 bug 3). Precisou de `docker compose up -d` extra (~15s) para subir workers depois do server estar healthy. **Total efetivo: ~25-30s.**
- **Tamanho das imagens:**
  - RabbitMQ stack: 5 imagens × ~133 MB = **~665 MB total** (a maioria é PHP + composer deps)
  - Temporal stack: 5 imagens × ~762-787 MB = **~3800 MB total** (dominado por RoadRunner + PECL grpc)
- **Memória idle (após 30s sem tráfego):**

| Container                    | RabbitMQ stack                | Temporal stack                                    |
| ---------------------------- | ----------------------------- | ------------------------------------------------- |
| broker / server              | rabbitmq: **108 MiB** (4.3)   | postgres: 143 MB + temporal: 78 MB = **221 MB**   |
| ui                           | (Mgmt UI embarcada no broker) | temporal-ui: **4 MB**                             |
| orchestrator/workflow worker | orchestrator: **6.6 MB**      | workflow-worker: **64.6 MB**                      |
| service-a worker             | service-a: **8.2 MB**         | service-a-worker: **64.5 MB**                     |
| service-b worker             | service-b: **8.2 MB**         | service-b-worker: **64.2 MB**                     |
| alerter                      | alerter: **5.8 MB**           | alerter: **18.9 MB**                              |
| **Total idle**               | **~137 MiB** (4.3)            | **~439 MB** (~3.2x)                               |

- **Comparação:** Temporal stack ~2.6x mais pesado em idle. Imagens ~6x maiores no disco. RabbitMQ tem footprint de "transport puro"; Temporal carrega Postgres + history + RoadRunner workers.
- **Notas:** para devs locais com 16GB+ RAM, ambos rodam sem problema. Para CI runners com 4GB de RAM, Temporal pode forçar tuning. RabbitMQ não tem essa preocupação no volume avaliado.

### T3.3 Sustained load — memory leak detection

- [x] **Executado.**
- **Como executou:** scripts `bin/sustained-load.php` em ambos PoCs. 5 minutos × 10 sagas/s. Stats capturados a cada 60s.
- **Resultado RabbitMQ:**
  - Sagas disparadas: **2959** em 300s (~9.86 sagas/s real) — _4.3 atual; 3.13 tinha 2909_
  - Sagas COMPLETED: 2909 (0 falhas)
  - Memória durante load (orchestrator): 6.6 → 14-15 MB pico, **voltou para 6.7 MB ao fim do load**
  - rabbitmq broker: **108 → 105 MiB** (não cresceu mensuravelmente em 4.3 — Khepri tem footprint mais previsível que Mnesia; em 3.13 era 141 → 159 MB pico)
  - alerter: cresceu lentamente 5.8 → 7.2 MB (cache em memória de IDs alertados — minor leak conhecido, esperado para um demo)
  - **Veredito: sem leak detectado**, comportamento estável.
- **Resultado Temporal:**
  - Workflows disparados: **2847** em 300s (~9.5 wfs/s real)
  - Workflows COMPLETED: 2847 (0 falhas)
  - Memória durante load:
    - postgres: 143 → 250 MB (**+107 MB**, history events persistidos)
    - temporal server: 79 → 241 MB (**+162 MB**)
    - cada worker: ~64 → ~110-128 MB (+50 MB cada × 3 workers = +150 MB)
    - Total stack: 439 → ~750 MB (**+311 MB durante load**)
  - **Não voltou para baseline em 5 min após load ended** (não esperado, mas não testado por mais tempo)
  - **Veredito: não é leak; é storage de history events (feature, não bug)**. Postgres acumula porque retention default é 7 dias para Completed. Em produção, lifecycle policy do Temporal limpa.
- **Comparação:**
  | | RabbitMQ | Temporal |
  |---|---|---|
  | Memória durante load (pico) | +20-30 MB | +311 MB |
  | Memória após load | volta a baseline | continua alta (history retention) |
  | Throughput sustentado real | 9.7/s | 9.5/s |
  | Falhas | 0 | 0 |
- **Análise:**
  - **RabbitMQ** trata sagas como "transport ephemeral": mensagens são ackadas e removidas, SQLite tem rows leves, memória de processos volta ao normal.
  - **Temporal** trata sagas como "audit trail durável": history events ficam em Postgres. Memória do server cresce durante load porque acumula state/cache. Cresce linear com volume — **previsível, não vazamento**.
  - **Implicação prática:** RabbitMQ é mais leve em RAM mas perde memória de longo prazo (postmortem ruim). Temporal carrega o custo da observabilidade no Postgres. Ambos OK no volume esperado.
- **Notas:** não foram medidos file descriptors crescendo nem GC do RoadRunner explicitamente. Para validar comportamento real em produção (24h+ de carga), seria preciso teste mais longo.

### T3.4 Replay completo de saga antiga

- [x] **Executado.**
- **Como executou:** pegou saga aleatória do load test T3.3 em cada PoC e simulou um postmortem cronometrado.
- **Resultado RabbitMQ (saga `000ba3bf-...`):**
  - **Q1: status atual + caminho percorrido** → SQLite query (~90ms): retorna `COMPLETED`, current_step=3, completed_steps com `[reserve_stock(res_831241ce), charge_credit(chg_a40a8aa1), confirm_shipping(BR716425)]`. Rápido, mas só snapshot.
  - **Q2: timestamps de cada evento** → grep nos logs do orchestrator (~58ms): 6 linhas com timestamps; mostra fluxo step-by-step + COMPLETED. Funcional, mas dependente de logs estarem retidos.
  - **Q3: payloads de entrada de cada step** → NÃO disponível. Lib não persiste o payload de entrada das chamadas downstream (só o `result` no DB).
  - **Q4: replay programático** → não existe. Lib não tem mecanismo de re-rodar uma saga a partir do histórico.
  - **Q5: reconstrução cross-service (logs de service-a + service-b por saga_id)** → grep manual em N containers; em produção exigiria ELK/Loki. Tempo prático: 5-15 min por incidente.
  - **Total para "entender o que aconteceu":** ~2-5 minutos no melhor caso (saga simples, COMPLETED). Para incidentes com falha/compensação, dobra ou triplica.
- **Resultado Temporal (workflow `98834c7f-...`):**
  - **Q1: history completo com payloads** → `tctl workflow show` (~80ms): 97 linhas, todos os eventos com payload de entrada/saída de cada activity, retry attempts, timing, identity do worker. Tudo num lugar.
  - **Q2: navegação visual** → Temporal Web UI (porta 8088) mostra timeline gráfica. Click no workflow_id → vê cada activity expandido com input/output. DX superior.
  - **Q3: replay programático** → `tctl workflow show --output_filename ...` exporta JSON; SDK Replayer pode re-executar workflow code contra histórico antigo (útil para validar mudanças retroativamente).
  - **Q4: search/filter** → `tctl workflow list --query "WorkflowType='X' AND ExecutionStatus='Failed' AND StartTime > '2026-04-28'"`. Search rico.
  - **Total para "entender o que aconteceu":** ~30s-1min via UI ou tctl. Postmortem é navegação visual, não arqueologia.
- **Comparação direta:**
  | Capacidade | RabbitMQ | Temporal |
  |---|---|---|
  | Status atual da saga | DB query | tctl/UI |
  | Caminho percorrido (steps) | DB completed_steps | history events |
  | Timestamps de cada evento | via logs (efêmeros) | history nativo |
  | Payload de entrada de cada step | não persiste | history nativo |
  | Payload de saída (result) | DB | history nativo |
  | Retry attempts | silenciosos | explícitos |
  | Replay programático | não | sim |
  | Search/filter | SQL custom | List Filter Query syntax |
  | Tempo prático para postmortem | 2-15min (varia muito) | 30s-1min |
- **Veredito:** Temporal vence claramente em **profundidade de informação** e **DX**. RabbitMQ entrega o básico rápido (status+path) mas depende de logs e instrumentação extra para ir além. Para múltiplos serviços com vários times diferentes investigando incidentes, a diferença vira **dias de produtividade ao longo de meses**.
- **Notas:**
  - Para chegar à paridade, RabbitMQ precisaria: tabela `saga_events` append-only (~1-2 dias eng), payloads persistidos em colunas adicionais, integração com ELK/Loki para correlacionar logs cross-service. Custo cumulativo.
  - **Item-chave para reforçar nas considerações:** o gap não é "Temporal tem timeline visual"; é "Temporal tem TODOS os payloads consultáveis sem ter previsto que iam ser consultados".

---

## Tier 4 — Resiliência adicional (não bloqueante)

### T4.1 Falha de rede worker↔server (Temporal)

- [x] **Executado.**
- **Como executou:** `SLOW_RESERVE_STOCK=12`, disparou saga, `docker network disconnect` no `service-a-worker` aos 3s (durante o sleep do reserveStock), aguardou 10s, reconectou.
- **Resultado:**
  - Saga `43f14d45` completou normalmente como `COMPLETED`.
  - Tempo total: ~15s do trigger até COMPLETED (apenas 2s após reconexão).
  - History: `reserveStock` completou no **Attempt:1** (sem retry).
  - Interpretação: o worker estava no meio do `sleep(12)` quando a rede foi desconectada. Activity completou localmente, resultado ficou em buffer interno do worker. Quando rede voltou, worker enviou resultado para Temporal server. Sem retry, sem timeout.
  - **Veredito:** worker sobreviveu a 10s de network outage sem perder estado nem reexecutar a activity.
- **Notas:**
  - Para testar pior cenário (outage longa que excede `StartToCloseTimeout`), seria preciso > 20s de disconnect — aí o servidor declara timeout, agenda retry, novo worker (ou mesmo após reconexão) reexecuta. Não testado.
  - **Análogo RabbitMQ não testado** porque T1.4 já mostrou comportamento mais grave: workers caem com broker (sem reconexão automática). Aqui o broker é "rede + Temporal server" — comportamento equivalente esperado: workers PHP da PoC RabbitMQ cairiam com `AMQPProtocolConnectionException` quando reconectam à rede e descobrem que a conexão original morreu.

### T4.2 Disco cheio no SQLite (RabbitMQ)

- [~] **Executado parcialmente.** Não foi possível encher 45 GB do volume host com segurança; substituído por simulações análogas de "write failure" no SQLite.
- **Tentativas:**
  1. `chmod 444` na SQLite → **não falhou**: orchestrator roda como root no container, ignora permissão.
  2. `mv saga.sqlite saga.sqlite.moved` mid-flight → trigger CLI criou novo arquivo vazio; orchestrator daemon (com fd antigo) ficou consultando o arquivo movido.
  3. `PRAGMA max_page_count = 5` → não persiste entre conexões PDO; novo connect retorna ao default 1073741823.
- **Resultado da tentativa 2 (mais informativa):**
  - Trigger criou saga `6a05283e` no NOVO arquivo vazio.
  - Daemon orchestrator emitiu `saga not found: 6a05283e` no stderr.
  - **Saga ficou stuck em estado inconsistente, sem erro propagado para o usuário**.
  - Trigger script retornou exit 0 (sucesso) — usuário pensa que saga foi disparada com sucesso.
- **Análise:**
  - PoC RabbitMQ não tem **camada de health-check** do storage. Se SQLite estiver indisponível ou em estado inconsistente, lib não detecta nem alerta.
  - Em produção (com MariaDB ao invés de SQLite), o cenário equivalente seria DB unreachable: `PDOException`. A lib atualmente NÃO trata exceções de DB no orchestrator daemon — provável crash silencioso.
  - **Análogo Temporal:** se Postgres do Temporal cai, T1.4 mostrou que workers continuam, eventualmente erram, e RECUPERAM quando Postgres volta. Workflows ficam pausados, não corrompidos.
- **Veredito:** lib RabbitMQ atual **não é robusta a falhas de storage**. Requer pelo menos:
  1. Try/catch em todas as queries com retry exponencial. (~10 LOC)
  2. Health-check periódico do DB com circuit breaker. (~30 LOC)
  3. Modo "degradado" que para de aceitar novas sagas quando storage indisponível, em vez de aceitar e perder. (~20 LOC)
  - **Estimativa total: ~1 dia de eng**, mais um item para a lista de débitos pré-produção.

### T4.3 Falha em step 1 (compensação trivial)

- [x] **Executado.**
- **Como executou:** Adicionado check `FORCE_FAIL=step1` em ambos handlers de `reserveStock` (RabbitMQ e Temporal). Recriado workers com `FORCE_FAIL=step1`, disparada saga.
- **Resultado RabbitMQ (saga `5c23490b`):**
  - Status final: **COMPENSATED**, current_step=0, completed_steps=`[]` (vazio).
  - Logs: `step=0 FAILED → compensating` seguido imediatamente por `COMPENSATED`. Nenhuma compensação despachada (loop iterou de stepIndex-1=-1 para baixo, encontrou nada).
  - **Veredito:** comportamento correto. Sem reverter nada porque nada havia sido feito.
- **Resultado Temporal (workflow `4cd69d06`):**
  - Status final: `COMPENSATED`, history com 11 events.
  - reserveStock falhou após 3 retry attempts (RetryPolicy padrão: 3 tentativas).
  - Workflow caiu no `catch`, chamou `yield $saga->compensate()` — Saga estava vazia (nenhum `addCompensation` foi reached porque reserveStock nunca retornou). Compensate retornou no-op.
  - Resultado: `{status: COMPENSATED, error: ...MAXIMUM_ATTEMPTS_REACHED}`.
  - **Veredito:** comportamento correto. Mesmo padrão.
- **Comparação:**
  - **RabbitMQ:** falha imediata após 1 tentativa do handler (lib não tem retry policy default). Saga compensada em ms.
  - **Temporal:** retry com backoff (3 tentativas com 1s + 2s = 3s mínimo). Saga compensada em ~3s.
  - **Para falhas determinísticas (ex.: bug no handler), retry do Temporal é desperdício**; o time precisa configurar `RetryPolicy::withMaximumAttempts(1)` para certos tipos de falha. RabbitMQ não tem retry → não há essa decisão a tomar.
  - Por outro lado, para falhas **transientes** (rede momentânea, DB busy), RabbitMQ-PoC dá uma única chance e desiste; lib precisaria implementar retry com backoff (~15-20 LOC + config).
- **Notas:** este teste valida edge case importante e nenhum dos dois apresenta regressão. Compensação trivial funciona em ambos.

### T4.4 Activity timeout vs activity error (Temporal)

- [x] **Executado.**
- **Como executou:** `SLOW_RESERVE_STOCK=25` (excede `StartToCloseTimeout=20s`). Disparada saga, observado history.
- **Resultado Temporal (workflow `f1940654`):**
  - Tempo total wall: **63 segundos** (3 attempts × 20s timeout + backoff).
  - History event 7: **`ActivityTaskTimedOut`** (NÃO é `ActivityTaskFailed`).
  - Detalhes: `Failure:{Message: 'activity StartToClose timeout', Source:Server, FailureInfo:{TimeoutFailureInfo:{TimeoutType:StartToClose}}}`.
  - **Distinção clara da plataforma:**
    - Erro normal (T4.3): event `ActivityTaskFailed` + `ApplicationFailureInfo:{Message:...}`.
    - Timeout (T4.4): event `ActivityTaskTimedOut` + `TimeoutFailureInfo:{TimeoutType:StartToClose}`.
  - Tipos de timeout disponíveis: `StartToClose` (activity demorou demais), `ScheduleToClose` (queue+exec total demorou), `ScheduleToStart` (não pegou worker), `Heartbeat` (activity não bateu heartbeat).
  - RetryPolicy aplica em ambos os casos por default; pode-se customizar com `NonRetryableErrorTypes` para fazer retry diferente.
- **Análogo RabbitMQ:**
  - Lib **não tem conceito de timeout de handler**. Handler roda sincronamente até retornar ou throw.
  - Se handler trava (ex.: HTTP call sem timeout), **bloqueia o consumer indefinidamente** (qos=1).
  - Outras mensagens na fila ficam aguardando.
  - Para detectar handler travado, lib precisaria implementar:
    - Timeout via `pcntl_alarm` ou exec em subprocess. (~30-50 LOC + edge cases).
    - Distinção entre "timeout" e "exception" no event emitido. (~5 LOC).
    - Documentação para devs definirem timeouts apropriados por handler.
  - **Custo estimado para atingir paridade: ~1 dia de eng + disciplina permanente.**
- **Comparação:**
  | | Temporal | RabbitMQ (PoC) |
  |---|---|---|
  | Conceito de timeout | Nativo (4 tipos diferentes) | Inexistente |
  | Distinção timeout vs error | Sim, no history e UI | Não |
  | Handler travado | Reagenda ao bater timeout | Bloqueia consumer indefinidamente |
  | Tempo para detectar travamento | Configurável (default 20s no PoC) | Indeterminado |
  | Retry policy diferenciada | Sim (`NonRetryableErrorTypes`) | Não tem retry |
- **Notas:** este é mais um item onde a "infraestrutura grátis" do Temporal aparece. Para múltiplos serviços, ter timeouts explícitos e diferenciáveis em todos os steps é importante para postmortem e SLOs. Em RabbitMQ, isso teria que ser disciplina + biblioteca de helpers.

---

### T1.5 Versionamento com `Workflow::getVersion()` (mitigação Temporal)

- [x] **Executado.**
- **Como executou:**
  - Adicionado método `auditLog` na `ServiceBActivitiesInterface` + impl em `ServiceBActivities`.
  - `SLOW_RESERVE_STOCK=15` no service-a-worker.
  - Disparada saga A (V0 do código, sem getVersion).
  - Durante o sleep de 15s do reserveStock, edição do workflow para envolver `auditLog` em `Workflow::getVersion('add-audit-step', Workflow::DEFAULT_VERSION, 1)`:
    ```php
    $version = yield Workflow::getVersion('add-audit-step', Workflow::DEFAULT_VERSION, 1);
    if ($version >= 1) {
        yield $this->serviceB->auditLog([...]);
    }
    ```
  - `docker cp` + `docker restart` no workflow-worker.
  - Após saga A completar, disparada saga B (com V1 já carregado).
- **Resultado Temporal:**
  - **Saga A** (em voo durante o deploy): completou normalmente como `COMPLETED` SEM rodar auditLog. History: reserveStock (event 5) → chargeCredit (event 11) → confirmShipping (event 17). **Nenhum MarkerRecorded** porque getVersion retornou DEFAULT_VERSION para execução existente.
  - **Saga B** (iniciada após o deploy): completou normalmente como `COMPLETED` rodando auditLog. History: reserveStock (event 5) → **MarkerRecorded {MarkerName:Version}** (event 11) → **auditLog (event 13)** → chargeCredit (event 19) → confirmShipping (event 25). Marker registrou versão=1.
  - **Comportamento verificado:** sagas antigas e novas coexistem no mesmo deploy sem panic, sem perda. Mitigação funciona como documentada.
  - **LOC adicional para suportar versionamento:** 4 linhas no Workflow code (1 linha de getVersion + 3 do bloco condicional). Custo desprezível.
- **Resultado RabbitMQ (versionamento manual — não implementado, estimado):**
  - Para alcançar o mesmo comportamento (sagas em voo continuam fluxo antigo, sagas novas usam fluxo novo), o trabalho **não previsto pela lib atual** envolve:
    1. Migração de schema: adicionar coluna `saga_version INTEGER NOT NULL DEFAULT 1` na tabela `sagas`. (~2 linhas SQL)
    2. Repositório (`SagaStateRepository::create`): aceitar `version` e gravar. (~3 LOC)
    3. Saga base (`Acme\Saga\Saga`): expor método `currentVersion(): int` (default 1) e permitir override por saga concreta. (~5 LOC)
    4. `SagaOrchestrator::start`: ler `currentVersion()` e gravar no DB. (~2 LOC)
    5. `SagaOrchestrator::handleEvent` e `dispatchStep`: ler `$state['version']` e despachar para `definition($version)` em vez de `definition()`. (~5 LOC)
    6. `Saga::definition` vira `definition(int $version): array` e cada saga concreta passa a ter `match` ou `if` selecionando a versão. (~10 LOC por saga + boilerplate)
    7. Convenção de "promoção de versão" para garantir que dev incremente `currentVersion()` ao adicionar branch nova. (sem código — disciplina + ADR + lint custom)
  - **Estimativa total:** ~25-30 LOC na lib + ~10 LOC por saga concreta + lint PHPStan para detectar mudanças sem promoção de versão (~1-2 dias de eng).
  - **Custo recorrente:** cada saga nova precisa pensar em versionamento desde o dia 1 (mesmo se nunca for usar). Em Temporal, custo aparece só quando precisa.
- **Comparação direta:**
  - **Detecção de divergência:** Temporal detecta automaticamente; RabbitMQ exige disciplina humana (lint).
  - **LOC para mitigar:** Temporal 4 LOC inline; RabbitMQ 25-30 LOC de infra + 10 LOC por saga.
  - **Quando o custo aparece:** Temporal só quando precisa versionar; RabbitMQ paga upfront (boilerplate em todas as sagas).
  - **DX em code review:** ambos exigem reviewer atento (em Temporal, ver se getVersion foi usado; em RabbitMQ, ver se currentVersion foi promovido). Empate.
- **Notas:**
  - O resultado do Temporal **valida totalmente** a hipótese 2.1.5 de [`consideracoes.md`](./consideracoes.md): versionamento explícito é custoso mas honesto.
  - O contraste com T1.1 (sem mitigação): Temporal panic loud × RabbitMQ silent failure. Com mitigação aplicada (T1.5): Temporal funciona com 4 LOC × RabbitMQ exigiria infra extra de 1-2 dias.
  - **A vitória de Temporal aqui não é sobre o "happy path" de versionamento (ambos podem ser feitos)**: é que Temporal entrega a infra grátis enquanto RabbitMQ exige construção + disciplina permanente.

---

## Tier 5 — Versionamento ampliado

### T5.1 Reordenar compensações

> **Aplicabilidade:** este teste só faz sentido em **modelos orquestrados** com saga_definition central. Em saga **coreografada** não há ordem global de steps para reordenar — cada serviço só conhece sua própria subscription. Quando a 4ª PoC (`saga-rabbitmq-coreografado/`) for executada, os testes equivalentes serão: (a) compensação chega antes do evento de sucesso, (b) handler de compensação não-idempotente, (c) loop de eventos. Resultado abaixo refere-se ao ramo orquestrado.

- [x] **Executado.**
- **Como executou:** SLOW_RESERVE_STOCK=15 em ambos PoCs. Disparada saga A com V0 (ordem reserveStock → chargeCredit). Durante o sleep do reserveStock, swap do código para V1 (chargeCredit → reserveStock — ordem invertida). Push do código + restart do orchestrator/workflow-worker.
- **Resultado Temporal (workflow `bbb1f5bf`):**
  - Worker registrou panic explícito após reserveStock completar:
    ```
    [TMPRL1100] nondeterministic workflow:
    history event is ActivityTaskScheduled: ServiceA.reserveStock
    replay command is ScheduleActivityTask: ServiceB.chargeCredit
    ```
  - Workflow ficou em loop de retry (Attempt 1, 2, 3...) — não avança até intervenção.
  - Mensagem mostra **exatamente o que esperava no history vs o que o código novo está produzindo**. Postmortem claro.
  - Workflow precisou ser terminado manualmente via `tctl workflow terminate`.
- **Resultado RabbitMQ (saga `9b1213c2`):**
  - Saga marcada como **`COMPLETED`** com state CORROMPIDO:
    ```
    completed_steps:
      [{"index":0,"name":"charge_credit","result":{"reservation_id":"res_73461e96"}},
       {"index":1,"name":"reserve_stock","result":{"reservation_id":"res_fa3b08dd"}},
       {"index":2,"name":"confirm_shipping","result":{"tracking_code":"BR387995"}}]
    ```
  - Anomalias visíveis:
    - **Index 0 nomeado `charge_credit` mas o `result` veio do reserveStock** (`reservation_id`, não `charge_id`). Lib salvou a step.completed do step 0 com o NOME da nova definição, mas o RESULT veio da execução antiga.
    - **Index 1 = reserve_stock executou DE NOVO** (`res_fa3b08dd` ≠ `res_73461e96`) — duplicação de reserva.
    - **chargeCredit nunca foi executado** — pagamento perdido.
  - Saga **marcada como sucesso ("COMPLETED")** mesmo com state inconsistente.
- **Comparação direta:**
  - **Temporal:** falha LOUD, explícita, com mensagem clara. Workflow stuck (precisa intervenção). State preserved.
  - **RabbitMQ:** falha SILENT, marca SUCESSO falso, state corrompido. Em produção: estoque reservado 2x, pagamento NUNCA cobrado, pedido marcado completo. **Pior cenário possível para padrão sustentável.**
- **Veredito:** este teste é o argumento mais forte a favor do Temporal no eixo "correção sob mudança de código". Demonstrou empiricamente que **RabbitMQ-PoC produz silent corruption em mudança comum (reordenar steps)**. Mitigação requer disciplina permanente + lint + saga_version (~1-2 dias eng inicial), e mesmo assim depende de o dev lembrar.
- **Notas:**
  - Mesmo padrão de panic visto no T1.1 (adição de step), mas T5.1 mostra mais especificamente: a mensagem do TMPRL1100 inclui ActivityType esperado vs encontrado, facilitando diagnose.
  - Em RabbitMQ, sem o `saga_version` da §1.2.8 / mitigação 1.3, qualquer reordenação de step durante deploy reproduz esta corrupção.

### T5.2 Mudar shape do payload de step

- [x] **Executado.**
- **Como executou:** SLOW_RESERVE_STOCK=15 (Temporal) / 30 (RabbitMQ). Disparada saga com payload antigo. Durante o sleep do step 0, modificado handler de `chargeCredit` (step 1) para exigir campo `currency` no payload (que não existe). Push do código + restart do service-b/service-b-worker.
- **Resultado Temporal (workflow `3bf60097`):**
  - Activity `chargeCredit` falhou no event 13 com `ApplicationFailureInfo:{Message: "ChargeCredit: missing required field 'currency'"}`.
  - Retry 3 attempts (RetryPolicy default), todos falham.
  - Workflow caiu no `catch`, executou `compensate()` → `releaseStock` rodou (events 17-19).
  - Saga ended COMPENSATED com error message claro preservado.
  - **History mostra cada attempt com mesma mensagem** — facilita postmortem.
- **Resultado RabbitMQ (saga `ef482a72`):**
  - Service-b log: `[service-b] saga=ef482a72... step=1 FAILED: ChargeCredit: missing required field "currency"` (1 attempt apenas).
  - ServiceWorker emitiu `step.failed` no saga.events.
  - Orchestrator consumiu, despachou compensação `release_stock`.
  - Saga ended **COMPENSATED**, current_step=1, completed_steps=`[{reserve_stock}]`.
  - Comportamento correto para este caso — compensação trivial funcionou.
- **Comparação direta:**
  - **Ambos compensaram corretamente** com mesma mensagem de erro.
  - **Diferença em retry:** Temporal tenta 3 vezes (default RetryPolicy) → para falhas DETERMINÍSTICAS (campo faltando), 3 retries é desperdício de ~3s. RabbitMQ tenta 1 vez. Para esse cenário específico, RabbitMQ é mais eficiente.
  - **Diferença em postmortem:** Temporal tem cada attempt no history com timestamp + identity do worker que falhou. RabbitMQ tem 1 linha de log no service. Empate prático para falha simples; vantagem Temporal para incidentes complexos.
  - **Comportamento similar nos dois** quando o handler atual da saga em voo bate com o handler novo (V1).
- **Veredito:** ao contrário de T5.1 (reordenamento), este teste mostra que **mudança de shape de payload é tratada similarmente bem em ambos**. Diferença é marginal (retry default + qualidade de postmortem), não estrutural.
- **Notas:**
  - Para falhas DETERMINÍSTICAS, configurar `RetryPolicy::withMaximumAttempts(1)` no Temporal evita os 3s de retry. RabbitMQ não precisa dessa preocupação.
  - Para falhas TRANSIENTES (rede, DB busy), Temporal fica melhor por default — RabbitMQ-PoC não retenta nada (sem retry policy implementada).

---

## Tier 6 — Custo financeiro real

### T6.1 1000 sagas em Temporal Cloud free tier

- [skip] **Não executado nesta sessão.** Razão: requer criar conta na Temporal Cloud e configurar credenciais — fora do escopo da execução automatizada local.
- **Como conduzir quando puder:**
  1. Criar namespace gratuito em https://cloud.temporal.io (free tier 14 dias + Essentials a partir de $100/mês).
  2. Configurar SDK para apontar para o endpoint Cloud + autenticação mTLS (cert + key).
  3. Rodar `bin/p99-bench.php 1000` (mesmo script do T6.2).
  4. Na UI da Cloud, abrir "Account Usage" → ver "Actions" consumidas.
  5. Calcular: actions × custo unitário × volume estimado (volume agregado ~17M sagas/mês).
- **O que esperar (estimativa baseada na documentação Temporal):**
  - Cada workflow consome ~5-10 "actions" (start, decision tasks, activity scheduling, completion).
  - Volume mensal estimado: ~17M sagas × ~7 actions = **~120M actions/mês**.
  - Tier "Essentials" cobre ~10M actions/mês ($100). Acima disso, ~$0.04 por 1000 actions.
  - Cálculo grosseiro: 120M actions × $0.04/1000 = **~$4800/mês** em Cloud.
  - Comparado a self-host estimado de $250-500/mês infra + 1-2 dias eng/mês.
- **Implicação:** para o volume esperado, **Temporal Cloud fica caro rapidamente** (~$58k/ano). Self-host é a opção financeira sensata para escala >10M actions/mês. A vantagem operacional do Cloud aparece só durante adoção (primeiros 6-12 meses).
- **Resultado:** validar números com teste real depois que decisão for tomada (se Temporal escolhido, rodar antes de assinar).

### T6.2 Overhead de p99 fim-a-fim

- [x] **Executado.**
- **Como executou:** scripts `bin/p99-bench.php` em ambos PoCs disparam 1000 sagas SEQUENCIAIS (uma após a outra completar) e capturam latência fim-a-fim por saga. Calculam p50/p95/p99/max.
- **Pré-condição:** habilitado `PRAGMA busy_timeout=5000` + `journal_mode=WAL` na lib RabbitMQ (mudança de ~2 LOC) para evitar deadlocks SQLite entre orchestrator daemon e bench script. Sem isso, exception "database is locked" matava o bench após poucas iterações.
- **Resultado RabbitMQ:**
  - n=1000, **p50=21.3ms p95=21.8ms p99=22.1ms max=25.2ms avg=21.4ms**
  - Tempo total: 21.5s (~46 sagas/s sequencial)
  - Distribuição extremamente tight: max é apenas 4ms acima de p50.
- **Resultado Temporal:**
  - n=1000, **p50=59.9ms p95=349.8ms p99=351.2ms max=356.0ms avg=134.8ms**
  - Tempo total: 135s (~7.4 workflows/s sequencial)
  - Distribuição bimodal: maioria em 60ms, minoria em ~350ms (provavelmente decision task batching ou worker pool warmup).
- **Comparação direta:**
  | Métrica | RabbitMQ | Temporal | Diferença |
  |---|---|---|---|
  | p50 | 21.3ms | 59.9ms | **2.8x** mais lento |
  | p95 | 21.8ms | 349.8ms | **16x** mais lento |
  | p99 | 22.1ms | 351.2ms | **16x** mais lento |
  | Throughput sequencial | 46/s | 7.4/s | **6.2x** menor |
  | Variância | mínima (Δ4ms) | alta (Δ290ms) | RabbitMQ mais previsível |
- **Análise:**
  - **RabbitMQ:** o "happy path" é direto: trigger → publish → consume → handler → publish event → orchestrator handle → publish next → repeat. Cada salto é local, sem rede externa. ~21ms é dominado por 3 publish + 3 consume + SQLite UPDATEs.
  - **Temporal:** cada workflow tem overhead de gRPC roundtrip ao server + decision tasks + history persistence em Postgres + activity scheduling. ~60ms é o "preço da plataforma" no caso normal; ~350ms aparece quando há latência de processamento de decision task (worker pool não está pronto, batching de eventos).
  - Para **fluxos críticos com SLO tight** (ex.: pagamento síncrono), **RabbitMQ é claramente vantagem**. Para **fluxos batch/async** (90% dos casos de e-commerce), 60-350ms é negligenciável vs latência da chamada HTTP que segue.
- **Veredito:** mais um critério em que RabbitMQ ganha em **performance pura**, mas a vantagem só importa para uso síncrono. Para sagas async (que são a vasta maioria), a diferença é invisível ao usuário final.
- **Notas:**
  - O fix WAL+busy_timeout aplicado na lib é um achado paralelo: a lib **não tinha proteção contra deadlocks SQLite** sob concorrência, e isso só aparece em testes de carga. Item para a lista de débitos pré-produção (~2 LOC, mas representa "uma classe de bug que ninguém testou").
  - Em produção com MariaDB ao invés de SQLite, o problema de lock existe mas é gerenciado pelo DB engine — ainda assim, lib precisa setar `PDO::ATTR_TIMEOUT` apropriado.

---

## Resumo de execução

| Tier | Testes                       | Status                                                                        |
| ---- | ---------------------------- | ----------------------------------------------------------------------------- |
| 1    | T1.1, T1.2, T1.3, T1.4, T1.5 | **5/5** (T1.2 sem reprodução; T1.5 RabbitMQ estimado, não implementado)       |
| 2    | T2.1, T2.2, T2.3, T2.4       | **3/4** (T2.1 estimado, T2.2 implementado, T2.3 OK, T2.4 não-executável aqui) |
| 3    | T3.1, T3.2, T3.3, T3.4       | **3/4** (T3.1 estimado parcial; T3.2/T3.3/T3.4 OK)                            |
| 4    | T4.1, T4.2, T4.3, T4.4       | **4/4** (T4.2 com simulação parcial; demais OK)                               |
| 5    | T5.1, T5.2                   | **2/2**                                                                       |
| 6    | T6.1, T6.2                   | **1/2** (T6.1 não-executável sem credenciais Cloud; T6.2 OK)                  |

**Recomendação de ordem:** Tier 1 primeiro (responde perguntas de maior peso na decisão), depois Tier 2 + Tier 3 em paralelo, Tiers 4–6 conforme tempo permitir.
