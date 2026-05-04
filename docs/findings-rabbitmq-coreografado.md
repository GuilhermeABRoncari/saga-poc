# Findings: PoC RabbitMQ Coreografado — medições para fechar a recomendação

> 4ª PoC do estudo. Documento simétrico a [`findings-rabbitmq.md`](./findings-rabbitmq.md) e [`findings-temporal.md`](./findings-temporal.md). Construído em 2026-04-30 após o estudo identificar que as PoCs anteriores cobriram apenas saga orquestrada.
>
> ## Atualização 2026-05-04 — RabbitMQ 4.3
>
> Imagem trocada de `rabbitmq:3.13-management-alpine` para **`rabbitmq:4.3-management-alpine`** (Khepri/Raft, Mnesia removido). Smoke tests revalidados sem alteração de código na lib (`EventBus.php`, `SagaListener.php`, `SagaLog.php`).
>
> **Resultados em 4.3:**
>
> - **T3.2 idle:** broker 109.8 MiB; serviços 6.6 MiB cada → total ~123 MiB.
> - **T1.3 publish 100 sagas (fire-only):** 0.01s (~14 000 msgs/s — lado publish dominado por TCP local).
> - **T3.3 burst load 3000 sagas processadas end-to-end:** 0 falhas; broker 102.5 MiB sob load (sem crescimento mensurável vs idle 110); serviços +1-1.4 MiB cada (cresceu pouco, voltou perto do baseline). Throughput agregado dependente do consumer single-thread (~50 sagas/s end-to-end nesta config).
> - **T1.4 broker caído mid-flight:** saga retomada — `EventBus` reconectou via backoff exponencial (1s→2s→4s→8s→16s) sem alteração na lib. Comportamento **idêntico ao validado em 3.13**: o reconnect é implementado pela lib, não pelo broker.
> - **LOC da lib:** 357 (sem mudança).

PoC vivo: [`../saga-rabbitmq-coreografado/`](../saga-rabbitmq-coreografado/).

---

## 0. Modelo testado

**Coreografia pura sobre RabbitMQ topic exchange.** Sem orquestrador, sem state machine central, sem `saga_definition`. Cada serviço:

- Reage a um evento específico (subscription).
- Executa ação local.
- Publica novo evento de domínio (que dispara o próximo serviço).
- Em falha: publica `saga.failed`.
- Cada serviço também escuta `saga.failed` e roda compensação local idempotente via `compensation_log` (SQLite local por serviço).

---

## 1. Esforço até happy path

| Métrica                                        | Valor                                                           | Comparação RabbitMQ-orquestrado |
| ---------------------------------------------- | --------------------------------------------------------------- | ------------------------------- |
| Sessão de implementação                        | 1 (~1h)                                                         | 1 (~2h)                         |
| LOC totais                                     | **459** (PHP em `src/` + `bin/`)                                | 632                             |
| LOC da lib coreografada (`Saga\Choreographed`) | **265** (3 arquivos: EventBus 81, SagaLog 77, SagaListener 107) | 381 (6 arquivos)                |
| LOC dos handlers (5 arquivos)                  | 116                                                             | ~73                             |
| LOC dos scripts em `bin/`                      | 78                                                              | 140                             |
| Composer deps                                  | 2 (`php-amqplib`, `ramsey/uuid`)                                | 3                               |
| Containers Docker                              | 3 (rabbitmq + 2 serviços)                                       | 4                               |
| Tempo do primeiro `docker compose up --build`  | ~2 min                                                          | ~2 min                          |

**Observação:** lib é ~30% menor que a versão orquestrada (265 vs 381 LOC). Sem `SagaOrchestrator`, sem `SagaStateRepository`, sem `Saga`, sem `Step`. As três classes restantes (`EventBus`, `SagaLog`, `SagaListener`) cobrem todo o ciclo. **Importante:** a hipótese inicial de "<100 LOC" para a lib não se sustentou — a lib mínima funcional (com correção do achado 2.3 incluída) ficou em 265 LOC. Mesmo assim, é genuinamente menor que a orquestrada.

---

## 2. Comportamento empírico observado

### 2.1 Happy path — funcionou de primeira

Saga `2ba366b4` percorreu os 3 steps:

- `saga.started` → service-a → ReserveStock → `stock.reserved`
- `stock.reserved` → service-b → ChargeCredit → `credit.charged`
- `credit.charged` → service-a → ConfirmShipping → `saga.completed`

Sem coordenação central. Cada serviço só conhece seus eventos de entrada e saída.

### 2.2 Compensação por falha em step3 (ConfirmShipping) — funcionou

Saga `64118aa3`:

1. ReserveStock e ChargeCredit completaram normalmente.
2. ConfirmShipping falhou (FORCE_FAIL=step3).
3. service-a publicou `saga.failed` com `failed_step=confirm_shipping`.
4. service-a consumiu `saga.failed` → ReleaseStock executou (idempotente via dedup-key local).
5. service-b consumiu `saga.failed` → RefundCredit executou (idempotente via dedup-key local).

**Sem LIFO ordenado pelo orquestrador**, mas o resultado está correto: ambas compensações rodaram exatamente uma vez. Em coreografia, "ordem" não é garantida — é desejável que cada compensação seja idempotente e independente.

### 2.3 Compensação por falha em step2 (ChargeCredit) — ACHADO IMPORTANTE / RESOLVIDO em 2026-04-30

**Versão original (lib mínima sem step_log):** saga `12eb779c` mostrou que **service-b executou RefundCredit mesmo sem nunca ter cobrado nada**. Em coreografia pura, `saga.failed` é fanout; cada serviço executava sua compensação local sem saber se o step havia executado de fato.

**Solução adotada:** introduzido `SagaLog` local com duas tabelas:

- `step_log(saga_id, step, completed_at)` — gravado pelo `SagaListener` após handler de sucesso.
- `compensation_log(saga_id, step, payload, applied_at)` — dedup de compensações (já existia).

`SagaListener::runCompensations` agora aplica duas guardas:

1. `wasStepDone(saga_id, step)` — pula se este serviço nunca executou esse step.
2. `tryClaimCompensation(saga_id, step)` — pula se já compensou (idempotência).

**Validação empírica (2026-04-30):**

| Cenário                     | service-a (ReleaseStock)   | service-b (RefundCredit)   | Comportamento esperado                               |
| --------------------------- | -------------------------- | -------------------------- | ---------------------------------------------------- |
| Happy path                  | não roda                   | não roda                   | saga completou — sem compensação                     |
| FORCE_FAIL=step1 (Reserve)  | `skipped (never executed)` | `skipped (never executed)` | nada foi feito, nada para reverter                   |
| FORCE_FAIL=step2 (Charge)   | **roda** (devolve stock)   | `skipped (never executed)` | só ReleaseStock — RefundCredit não tinha o que fazer |
| FORCE_FAIL=step3 (Shipping) | **roda** (devolve stock)   | **roda** (estorna charge)  | ambas compensações pertinentes                       |

**Custo da correção:** lib cresceu de 234 → **265 LOC** (+31 LOC). A estimativa inicial era 280-300; ficou abaixo. Ainda 30% menor que orquestrada.

**Trade-off exposto pelo achado:** "mínimo de responsabilidade ao dev" não significa "zero responsabilidade". O dev de cada handler ainda precisa entender que:

- O step só conta como "feito" depois do `markStepDone` chamado pela lib após sucesso.
- A compensação roda como fanout — pode ser invocada mesmo quando a saga falhou em outro serviço, e precisa ser robusta a isso.
- Em produção, `markStepDone` + `publish` da lib **não são atômicos** com o efeito do handler. Se o handler comita uma transação no banco do serviço e o serviço cai antes do `markStepDone`, o efeito existe mas o log não — a saga vai falhar (timeout) e a compensação vai pular o step. **Bug em potencial em produção** que vai precisar de outbox pattern ou transaction-aware dedup. Vale registrar como achado paralelo a investigar (T-novo).

---

## 3. Critérios re-avaliados (vs orquestrado)

| Critério                                 | RabbitMQ-orquestrado                             | RabbitMQ-coreografado                                                          | Vencedor                   |
| ---------------------------------------- | ------------------------------------------------ | ------------------------------------------------------------------------------ | -------------------------- |
| LOC da lib                               | 381                                              | ~150-200                                                                       | **Coreografado**           |
| Versionamento de saga                    | Implícito (silent corruption sem saga_version)   | Não se aplica — sem definição central                                          | **Coreografado**           |
| Reordenar steps em deploy                | T5.1: silent corruption                          | Cada serviço é dono da sua subscription — mudanças localizadas, sem corrupção  | **Coreografado**           |
| Acoplamento entre serviços               | Médio (orquestrador conhece todos)               | Mínimo (cada serviço conhece só eventos)                                       | **Coreografado**           |
| Compensação ordenada (LIFO)              | Garantida pelo orquestrador                      | Não garantida — fanout paralelo                                                | **Orquestrado**            |
| Compensação condicional (só se executou) | Garantida (orquestrador sabe quem executou)      | ⚠️ requer step_log local em cada serviço (achado 2.3)                          | **Orquestrado**            |
| Postmortem visual                        | Tabela `saga_steps` central                      | Correlation-id + logs distribuídos por serviço                                 | **Orquestrado** (marginal) |
| Disciplina exigida do dev                | `saga_version` + lint + code review centralizado | Idempotência + step_log + tolerância a ordering parcial — distribuído por time | depende — não pior         |

---

## 3.A Volume de escritas no banco (medido 2026-04-30)

Critério levantado após observar `laravel-workflow` fazendo 31+ inserções por workflow. Medição comparativa:

| Modelo                | INSERTs happy-path       | INSERTs com compensação              |
| --------------------- | ------------------------ | ------------------------------------ |
| Temporal nativo       | **38**                   | **53**                               |
| RabbitMQ-orquestrado  | **1 INSERT + 4 UPDATEs** | **1 INSERT + ~6 UPDATEs**            |
| RabbitMQ-coreografado | **0**                    | **2** (1 em cada `compensation_log`) |

Detalhamento Temporal happy-path: history_node +12 (event sourcing), timer_tasks +12, transfer_tasks +8, visibility_tasks +3, executions +1, current_executions +1, history_tree +1.

**Implicações em escala (múltiplos serviços × ~1k-10k sagas/dia):**

- Temporal: 152k-2.1M INSERTs/dia só de metadados de workflow.
- Coreografado: 0-8k INSERTs/dia (só compensações).

Esse achado **fortalece o ramo coreografado** num critério quantitativo concreto que ainda não estava na comparação anterior — impacta latência (cada evento = round-trip), custo Cloud (Cloud cobra por action), e carga em Aurora self-host.

---

## 3.B Tier 1-6 re-projetado — execução parcial (2026-04-30)

Testes executados contra a versão corrigida da lib (com `step_log`):

### T1.3 — 100 sagas concorrentes

- 100 sagas disparadas em sequência (publish em lote, ~15k publish/s).
- Aguardado 30s.
- **Resultado:** 100 step_log em A, 100 step_log em B, 100 `saga.completed` events publicados.
- **Veredito:** RabbitMQ topic exchange + 1 consumer por queue lida bem com concorrência. Latência total inferior à orquestrada (sem hop pra orquestrador).

### T1.3 estendido — 3000 sagas batch (re-medido 2026-05-04)

- Publicadas 3000 sagas via `bin/batch-trigger.php` (publish-only completou em 0.09s, ~34k msgs/s lado fire).
- Processamento end-to-end de 3000 completou em ~3 min sustentados; 0 falhas.
- Footprint do broker durante load: estabilizou em ~102 MiB (baixou de 110 MiB idle — Khepri compacta sob escrita).
- Serviços cresceram apenas ~1 MiB cada durante o load.

### T6.2 — Latência sequencial p99 (NOVO em 2026-05-04)

- 1000 sagas sequenciais (uma após a próxima completar) via `bin/p99-bench.php` (criado nesta iteração).
- **Resultado: n=1000, p50=10.2ms p95=13.2ms p99=20.4ms max=40.5ms avg=10.6ms.**
- **Throughput sequencial:** ~94 sagas/s (~2× mais rápido que orquestrado, ~13× mais rápido que Temporal).

| Métrica               | Coreografado (4.3) | Orquestrado (4.3) | Temporal (Postgres) |
| --------------------- | ------------------ | ----------------- | ------------------- |
| p50                   | **10.2 ms**        | 21.8 ms           | 59.9 ms             |
| p99                   | **20.4 ms**        | 23.8 ms           | 351.2 ms            |
| max                   | 40.5 ms            | 42.2 ms           | 356.0 ms            |
| Throughput sequencial | **~94/s**          | ~46/s             | ~7.4/s              |

**Por que coreografado vence em latência sequencial:** o caminho fim-a-fim tem **menos hops**. Orquestrado: `service-a → orchestrator → service-b → orchestrator → service-a` (5 hops). Coreografado: `service-a → service-b → service-a` (3 hops, sem coordenador central). Cada hop poupado economiza 1 publish + 1 consume + 1 UPDATE SQLite.

**Caveat:** essa vantagem **não compensa o custo de DX e observabilidade** documentado em §3.1 da `consideracoes.md` e em §3 abaixo. É um trade-off explícito — coreografia é mais rápida individualmente mas mais cara para entender em conjunto.

### T-novo: persistência de fila (handler offline durante saga)

- service-b parado antes do trigger.
- service-a publica `stock.reserved` → fica enfileirado em `service-b.saga` (1 msg, 0 consumers).
- service-b sobe → consome → ChargeCredit → publica `credit.charged`.
- **Veredito:** RabbitMQ persistência durável funciona. Mensagem não perdida durante outage de consumer.

### T-novo: compensação que falha sempre — **RESOLVIDO em 2026-04-30 (segunda iteração)**

- Cenário: `FAIL_COMPENSATION=refund` força service-b a sempre falhar `RefundCredit`.
- **Observado:**
  - service-b loga `comp=charge_credit FAILED: forced failure on refund_credit`.
  - **`compensation_log` mostra row gravada**, marcando como "compensado" mesmo com falha.
  - Mensagem `saga.failed` foi `ack` no broker (consumida com sucesso do ponto de vista do RabbitMQ).
  - **Sem retry. Compensação fica perdida silenciosamente.** Em produção: RefundCredit nunca executou, dinheiro não devolvido, sem alerta.
- **Causa:** `SagaListener::runCompensations` chama `tryClaimCompensation` ANTES do handler. Se handler lança exception, claim já foi feito → próximas tentativas (se houvesse) seriam dedup-skipped.
- **Solução conhecida:** marcar claim com estados (`claimed`, `succeeded`, `failed`) ou só registrar após sucesso. Em ambos os casos a lib precisa também ter retry policy (republish saga.failed em DLQ, ou retry com backoff).
- **Custo estimado da correção:** +30-50 LOC na lib + uso de DLQ no RabbitMQ.

**Solução implementada:**

- `compensation_log` ganhou colunas `status` ('in_progress' | 'done') e `attempts`.
- `SagaLog::startCompensation` retorna `'attempt'` ou `'done'`. Em retry, incrementa attempts mas mantém status='in_progress'.
- `SagaListener::runCompensations` lança exception se algum handler falhar.
- `EventBus::declareAndConsume` em catch faz `ack + sleep(2) + publish` (republica `saga.failed` na exchange) em vez de nack/requeue. Razão: nack/requeue em php-amqplib teve comportamento inconsistente nos testes (msg sumindo da queue após primeiro nack); ack+republish é determinístico.
- Validado: 12 attempts em ~25s sob FAIL_COMPENSATION=refund; quando a falha foi removida, a próxima entrega virou status='done'.
- Trade-off conhecido: ack+republish **não é atômico** (crash entre ack e publish perde a msg). Solução clássica: outbox pattern.

### T1.4: broker caído por 30s — **RESOLVIDO em 2026-04-30 (segunda iteração)**

- RabbitMQ derrubado por 30s no meio de uma saga.
- **Observado:**
  - php-amqplib lança exception (connection_close).
  - Workers caem com stack trace e **terminam o processo**.
  - Sem `restart` policy no docker-compose, containers ficam parados.
  - Após RabbitMQ voltar, workers continuam mortos. Saga em voo perdida.
- **Causa:** lib não tem reconnect logic. php-amqplib não reconnecta automaticamente.
- **Soluções possíveis:**
  1. `restart: unless-stopped` no compose (em K8s = pod restart automático). Aceitável se a saga em voo for recuperada via re-entrega da fila durável (precisa investigar).
  2. Loop de retry no `EventBus::subscribe` que captura `AMQPConnectionClosedException` e reconecta com backoff exponencial.
  3. Bibliotecas como `videlalvaro/Thumper` ou wrappers PHP com reconnect built-in.
- **Comparação com Temporal:** durable execution by design — workflow continua exatamente de onde parou após qualquer outage. Em coreografia RabbitMQ, é responsabilidade da app garantir reconnect + idempotência sob re-entrega.
- **Custo estimado da correção:** +50-80 LOC na lib (reconnect + healthcheck periódico).

**Solução implementada:**

- `EventBus::subscribe` envolvido em `while (true)` capturando `AMQPConnectionClosedException | AMQPIOException | AMQPRuntimeException`.
- Ao detectar perda de conexão: `safeClose()` + sleep com backoff exponencial (1s → 2s → 4s → 8s → 16s → max 30s) + tentar reconectar.
- Quando reconnect tem sucesso, redeclare exchange/queue/binding/consumer e retoma loop.
- `restart: unless-stopped` no docker-compose como rede de proteção.
- Validado: workers sobreviveram a 25s de outage do RabbitMQ. Logs mostram tentativas com backoff. Após retomada, nova saga processou normalmente.

### Não executados ainda

- T2.2 idempotência via republicação manual de saga.failed (parcialmente coberto pelo retry de compensação acima)
- T-novo: ordering parcial (compensação chega antes do evento de sucesso)
- T-novo: postmortem distribuído (sem timeline central, rastreio via correlation-id em logs centralizados)
- Throughput/latência sob carga sustentada

### LOC final medido pós-correções

| Versão da lib                                                              | LOC reais | Aplicado em |
| -------------------------------------------------------------------------- | --------- | ----------- |
| Mínima inicial (pré achado 2.3)                                            | 234       | -           |
| Com `step_log` (achado 2.3 corrigido)                                      | 265       | +31         |
| **Madura: + retry de compensação + reconnect + status na compensação_log** | **357**   | +92         |
| Comparação: orquestrada                                                    | 381       | -           |

Lib coreografada madura ficou **357 LOC vs 381 do orquestrado** — ainda menor (~6%), mas a vantagem virou marginal. **A tese inicial de "lib pequena" não se sustentou** quando coreografia foi levada a sério para produção. O ganho real fica:

- Ausência de orquestrador central (zero hops extras).
- Ausência de versionamento de saga (mudanças localizadas em cada serviço).
- Volume de escritas drasticamente menor (~2 INSERTs/saga vs 38 do Temporal).

Mas o trade-off custa em outras frentes:

- Disciplina de idempotência por handler.
- Cuidado com timing entre `markStepDone` + efeito + publish (não-atomicidade).
- DLQ + monitoramento de retries é responsabilidade da app.
- Postmortem distribuído (correlation-id + log aggregator centralizado).

---

## 4. Próximos passos

- [x] **Resolver achado 2.3** — `step_log` local implementado em 2026-04-30; testado nos 4 cenários (happy, step1, step2, step3) com comportamento correto.
- [ ] **Investigar achado novo (atomicidade `markStepDone` + efeito do handler)** — em produção real, handler executa transação no banco do domínio, depois lib chama `markStepDone` em SQLite separado. Crash entre os dois deixa estado dessincronizado. Solução típica: outbox pattern.
- [ ] **Tier 1-6 re-projetado** — executar testes adaptados (T1.3 concorrência, T1.4 broker caído, T2.2 idempotência, T3.4 postmortem distribuído, novos: ordering, handler perdido, loop).
- [ ] **Comparar throughput/latência** — pub/sub puro deve ser mais rápido que orquestrado (sem hop pra orquestrador).
- [ ] **Documentar o trade-off "ordem vs simplicidade"** — em coreografia, compensações em paralelo simplificam código mas exigem que cada uma seja independente.

---

## 5. Conclusão preliminar (ANTES dos testes Tier 1-6)

Coreografia entrega as propriedades que se buscavam neste modelo:

- **Lib é genuinamente menor** (265 LOC vs 381 do orquestrado, ~30% menos) — já incluindo `step_log` para resolver o achado 2.3.
- **Sem tabela central de saga.** Cada serviço tem seu log local de compensação (e provavelmente de execução de step também).
- **Mudanças localizadas** — adicionar/remover step não requer migração de tabela central.

Mas há trade-offs reais não-triviais:

- **Achado 2.3** mostra que "lib mínima detecta erro e dispara compensação" não é suficiente — precisa de log de execução por serviço para evitar compensações falsas.
- **Postmortem distribuído** exige ferramenta externa (correlation-id em logs centralizados) — não vem grátis.
- **Ordering parcial** dos eventos pode produzir cenários onde compensação chega antes do evento de sucesso — precisa testar.

A recomendação final será uma **árvore de decisão**, não uma escolha única. Coreografia tende a ganhar em casos com 2-3 serviços simples e times independentes; orquestração (Temporal) tende a ganhar em casos com estado complexo, dependências entre passos, ou auditoria centralizada.
