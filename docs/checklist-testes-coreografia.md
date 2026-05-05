# Checklist de testes — RabbitMQ coreografado

> Espelha [`checklist-testes.md`](./checklist-testes.md) (que cobre os modelos **orquestrados**) para o modelo **coreografado** da PoC `saga-rabbitmq-coreografado/`. Cada teste do orquestrado é re-classificado em três categorias: **mantido** (mesmo critério, possivelmente mesmo método), **adaptado** (critério faz sentido, mas método muda — geralmente porque não há tabela central), **não se aplica** (critério depende de algo que não existe em coreografia, ex.: `saga_definition`).
>
> Além disso, a coreografia introduz **classes de risco específicas** (ordering parcial, handler perdido, loops de evento) que merecem testes próprios — listados no Tier C abaixo.
>
> Convenção de status: `[x]` executado / `[!]` executado, identificou gap / `[ ]` não executado / `[skip]` não-executável neste contexto / `[n/a]` não se aplica ao modelo.

---

## Tier 1 — Alto valor, baixo custo

### T1.1 Versionamento de Workflow code (sagas em voo)

- **[n/a]** **Não se aplica em coreografia.** O critério T1.1 testa o que acontece quando a `saga_definition` central muda enquanto há sagas em voo. Em coreografia não há `saga_definition` — a "ordem dos steps" é emergente da topologia de eventos consumidos/publicados em cada serviço. Mudar essa topologia exige deploy coordenado (ou rolling com tolerância a duas versões), problema diferente.
- **Risco análogo no modelo coreografado:** durante deploy parcial (squad A já no novo, squad B ainda no antigo), eventos publicados podem não ter consumidor pronto na rota nova ainda. Mensagens ficam enfileiradas em queue durável até consumidor reabrir; sem perda de dados, mas com latência aumentada durante a janela de deploy.
- **Mitigação típica:** versionamento de evento via `eventType` (ex.: `stock.reserved.v2` em paralelo com `stock.reserved`) + período de transição em que ambas versões são consumidas.

### T1.2 At-least-once / execução dupla

- **[x] mantido (mesma natureza do orquestrado).** RabbitMQ continua entregando at-least-once; o handler precisa ser idempotente. Em coreografia, a lib **força idempotência via `step_log` local** (handler bem-sucedido grava row; reentregue da mesma mensagem encontra row e pula). Em testes na PoC, nenhuma duplicação observável foi reproduzida na janela testada — comportamento equivalente ao orquestrado em RabbitMQ 4.3.
- **Conclusão:** risco residual idêntico ao do RabbitMQ orquestrado (T1.2 do checklist principal). Diferença: coreografado já tem dedup automática via lib; orquestrado depende de o dev incluir.

### T1.3 100 sagas concorrentes — throughput e latência

- **[x] mantido.** PoC mediu:
  - **Publish 100 sagas (fire-only):** 0.01s (~14 000 msgs/s lado publish).
  - **Burst de 3000 sagas processadas end-to-end:** 0 falhas, broker estável em 102.5 MiB sob load (sem crescimento mensurável vs 110 MiB idle), serviços +1-1.4 MiB cada.
  - **Throughput end-to-end** dependente do consumer single-thread por serviço (~50 sagas/s nesta config; subindo `prefetch` e workers paralelos sobe linear).
- Comparação com orquestrado em 4.3: ~94 sagas/s sequencial em coreografado vs ~46/s em orquestrado (~2× mais rápido — sem hop ao orchestrator central).

### T1.4 Falha do broker mid-flight (e behavior de reconnect)

- **[x] mantido.** Saga lenta disparada (`SLOW_RESERVE_STOCK=15`), `docker stop` no broker aos 2s, esperou 25s, restart. Resultado:
  - Workers detectaram queda de conexão e iniciaram **backoff exponencial 1s→2s→4s→8s→16s**.
  - Após broker voltar, workers reconectaram e processaram a saga retomada normalmente — `step=reserve_stock ok → step=confirm_shipping ok`.
  - **Nenhum worker crashou.** Vs orquestrado (`saga-rabbitmq/`) onde workers `php-amqplib` morrem com `AMQPConnectionClosedException` e ficam em `Exited (255)`.
- **Diferença vs orquestrado:** o reconnect é responsabilidade do `EventBus.php` da lib coreografada. A lib orquestrada (`AmqpTransport.php`) não tem reconnect — é gap arquitetural conhecido.

### T1.5 `getVersion` mitiga divergência de definição

- **[n/a]** **Não se aplica.** `getVersion` é primitiva do Temporal SDK para versionar workflow code. Em coreografia, não há código central que precise versionar.
- **Análogo coreografado:** versionamento de evento (ver T1.1 acima).

---

## Tier 2 — Lacunas grandes

### T2.1 Construir dashboard e timeline de saga

- **[ ] adaptado, não executado.** Em coreografia não há tabela central com timeline da saga. A solução madura é o **Saga Aggregator** (consumer que agrega eventos `saga.*` em uma tabela `saga_view` desnormalizada + UI Filament/Livewire). Plano técnico completo em [`consideracoes.md`](./consideracoes.md) §7. Não construído nesta PoC — registrado como "trabalho real de adoção" em vez de "refinamento do estudo".
- **Diferença vs orquestrado:** orquestrado tem `saga_states` central; basta CRUD admin (Filament). Coreografado exige **construir** o agregador (componente próprio a manter).

### T2.2 Alerta automático em saga FAILED

- **[x] adaptado.** Em coreografado, FAILED não fica em uma tabela central — é um evento `saga.failed` no broker. Alerta vira `subscribe('saga.failed', → notifyHumans)` em qualquer worker dedicado. ~10 LOC. Implementação trivial; não construída na PoC porque tema é cobertura de teste, não construção.
- **Lag esperado:** ~latência de fanout do broker, equivalente aos ~1s do orquestrado em RabbitMQ 4.3.

### T2.3 Estado da compensação no DB confiável

- **[x] adaptado.** Sem orquestrador central, "estado da compensação" é distribuído em `compensation_log` por serviço. Cada serviço sabe o que ele próprio compensou. Não há uma única consulta para "essa saga está totalmente compensada?" — exige agregar de N serviços (ou usar Saga Aggregator).
- **Diferença vs orquestrado:** orquestrado da PoC tem o gap do T2.3 do checklist principal — o orquestrador marca COMPENSATED em ~103ms antes dos handlers terminarem (DB mente). Em coreografado, **cada serviço só marca `compensation_log='done'` após handler terminar com sucesso** — não há "DB que mente"; há "DB distribuído que pode estar parcial".

### T2.4 Blind dev (dev sem contexto adiciona step)

- **[skip]** Requer dev externo. Não executável nesta PoC. Para pré-review, basta usar a documentação ("Adicionando uma saga nova" no README do `saga-rabbitmq-coreografado/`) como gabarito do que um dev novo precisa fazer.

---

## Tier 3 — Operacional

### T3.1 Setup novo dev (sem cache)

- **[x] mantido.** `docker compose up --build -d` completa em ~2 min (RabbitMQ pull + composer install). Igual ao orquestrado (`saga-rabbitmq/`); muito menor que Temporal (~25 min com PECL grpc compile).

### T3.2 Footprint idle por container

- **[x] mantido.** Medição em RabbitMQ 4.3 idle:
  - `rabbitmq: 109.8 MiB`
  - `service-a: 6.6 MiB`
  - `service-b: 6.6 MiB`
  - **Total stack idle: ~123 MiB.**
- Vs orquestrado: ~137 MiB (orq tem orchestrator + alerter como containers extra).
- Vs Temporal: ~439 MiB (~3.6× mais pesado).

### T3.3 Sustained load — memory leak detection

- **[x] mantido (parcial).** Burst de 3000 sagas processadas end-to-end:
  - Broker oscilou entre 110 MiB idle → 102 MiB sob load (Khepri compacta sob escrita).
  - Serviços cresceram +1-1.4 MiB cada.
  - 0 falhas em 3000 sagas.
  - **Sem leak detectado.**
- **Nota:** o teste original em orquestrado foi 5 min × 10 sagas/s sustentadas. O coreografado mediu burst (3000 de uma vez) — comportamento comparável, mas não é o mesmo padrão de carga. Para paridade absoluta, refazer com `bin/sustained-load.php` seria necessário (não criado para coreografado).

### T3.4 Postmortem de saga antiga

- **[x] adaptado — gap conhecido.** Sem agregador, postmortem em coreografia é doloroso: precisa juntar logs distribuídos correlacionando por `saga_id`. Tempo estimado em incidente real: 2-15 min, igual ao orquestrado RabbitMQ. **Mitigação prevista:** Saga Aggregator (§7 de `consideracoes.md`).
- Vs Temporal: 30s-1min via UI/tctl com history completo + payloads de entrada/saída. Continua sendo o melhor cenário para postmortem distribuído.

---

## Tier 4 — Resiliência

### T4.1 Network outage durante step

- **[x] executado em 2026-05-04.** Saga `ecbe6a0a` disparada com `SLOW_RESERVE_STOCK=15`. `docker network disconnect` no service-a aos 3s, reconnect aos 15s (12s outage). **Resultado: saga COMPLETED.** service-a continuou Up; conexão TCP do `php-amqplib` foi mantida pelo kernel durante o disconnect (sleep do handler não dispara I/O, então heartbeat não detecta). Quando rede voltou, publish de `stock.reserved` sucedeu silenciosamente.
- **EventBus reconnect não foi disparado** — não houve detecção de connection drop. Mesmo veredito do T4.1 orquestrado: client robusto a outages curtos (até pelo menos 75s testados no orquestrado).
- **Cenário não testado:** outage longo (>5 min) que ultrapassaria timers de TCP keepalive do kernel. Em produção real com cluster RabbitMQ multi-node, o failover de líder Raft (quorum queues) seria o teste mais relevante — fora do escopo desta PoC single-node.

### T4.2 Storage indisponível (SQLite local)

- **[x] executado em 2026-05-04.** Movido `service-a.sqlite` mid-flight para forçar erro de write. Saga `8233399b` chegou. Handler ReserveStock executou, mas `markStepDone` falhou com `SQLSTATE[HY000]: General error: 8 attempt to write a readonly database`.
- **Comportamento da lib:** exception capturada pelo `SagaListener::react()`, republicada como `saga.failed` com `error="attempt to write a readonly database"`. service-b consumiu `saga.failed`, tentou compensar — mas `wasStepDone()` retornou false (markStepDone tinha falhado), então compensação foi pulada. **Estado consistente:** saga falhou, nada para reverter (porque o write do efeito real também não persistiu — embora isso dependa de quanto do handler executou antes do erro).
- **Após restaurar o arquivo:** queue voltou a 0 mensagens, service-a retomou processamento normal de novas sagas.
- **Diferença vs T4.2 orquestrado:** o orquestrado da PoC mostrou silent corruption (saga ficou stuck em estado inconsistente sem erro propagado). O coreografado **falha alta** via `saga.failed`. Comportamento estruturalmente melhor.
- **Risco residual conhecido:** se o efeito real do handler (ex.: write no banco do serviço) tiver persistido **antes** do `markStepDone` falhar, há inconsistência (efeito existe, log não, compensação pula). Solução clássica é outbox pattern; documentado em `findings-rabbitmq-coreografado.md` §2.3 como achado paralelo a investigar.

### T4.3 Falha em step 1 (compensação trivial)

- **[x] mantido.** `FORCE_FAIL=step1` testado: nenhum step prévio existia, então nenhuma compensação rodou. service-a publicou `saga.failed`; service-a e service-b assinam `saga.failed`, ambos pulam compensação via `wasStepDone()` retornando false. Resultado correto: nada para reverter, nada foi feito.

### T4.4 Conceito de timeout

- **[!] mantido — gap idêntico ao orquestrado.** A lib coreografada não tem conceito de timeout de handler. Handler travado bloqueia o consumer indefinidamente (single-thread por queue). RabbitMQ 4.3 classic queues não avaliam consumer timeout. **Mitigação clássica:** `pcntl_alarm()` no início do handler com timeout configurável + handler de SIGALRM lançando exception. ~10 LOC adicionais por handler. Não implementado na PoC.
- **Diferença vs Temporal:** Temporal classifica 4 tipos de timeout (`StartToClose`, `ScheduleToClose`, `ScheduleToStart`, `Heartbeat`) e propaga no history. Coreografado teria que construir.

### T4.5 Compensação que falha sempre

- **[x] mantido.** `FAIL_COMPENSATION=refund` força service-b a falhar `RefundCredit`. Resultado:
  - `compensation_log` mostra row com `status='in_progress'` e `attempts` incrementando.
  - Lib relança exception → `EventBus` faz `nack` + republish → mensagem volta pra queue.
  - Retry exponencial automático.
  - **Comportamento sustentado:** ao remover `FAIL_COMPENSATION`, próxima tentativa sucede e marca `done`.
- Documentado em detalhe em `findings-rabbitmq-coreografado.md` §2 (achado original do "saga.failed era ackado mesmo com falha" — corrigido em 2026-04-30).

---

## Tier 5 — Versionamento ampliado

### T5.1 Reordenar steps durante deploy (silent corruption)

- **[n/a]** **Não se aplica em coreografia. Esse é o achado mais importante a favor do modelo.** T5.1 mostra que orquestrado RabbitMQ pode marcar saga COMPLETED com state corrompido após reorder da `saga_definition`. Em coreografia, **não há definição central para reordenar** — cada serviço define localmente o evento que consome e o que publica. Reordenar steps significa modificar a topologia de eventos (mudar quem consome o quê), o que é mudança coordenada explícita, não modificação silenciosa de array.
- **Risco análogo:** durante deploy parcial, eventos podem ficar órfãos (publicados mas sem consumer ainda atualizado). Solução: versionamento de evento (T1.1 acima).

### T5.2 Mudar shape de payload

- **[n/a]** **Não se aplica do mesmo jeito.** Em orquestrado, `saga_definition` carrega o shape esperado e a versão pode passar batido. Em coreografia, cada handler valida o shape do evento que recebe — se publisher emite shape novo e consumer está antigo (ou vice-versa), exception é capturada e vira `saga.failed`. Resultado: falha **alta** durante deploy parcial, em vez de silent corruption.
- **Conclusão:** modelo coreografado é estruturalmente mais seguro contra esse vetor de bug.

---

## Tier 6 — Custo real

### T6.1 Cold start

- **[x] mantido.** ~10s do `docker compose up` ao primeiro publish ser aceito. Igual ao orquestrado em RabbitMQ 4.3.

### T6.2 Latência fim-a-fim (1000 sagas sequenciais)

- **[x] mantido.** Bench `bin/p99-bench.php` com 1000 sagas:
  - **n=1000, p50=10.2ms p95=13.2ms p99=20.4ms max=40.5ms avg=10.6ms**
  - **Throughput sequencial: ~94 sagas/s** (~2× mais rápido que orquestrado RabbitMQ ~46/s, ~13× mais rápido que Temporal ~7.4/s).
- **Por que é mais rápido:** menos hops (3 vs 5 do orquestrado, sem coordenador central).

---

## Tier C — Testes específicos do modelo coreografado

Estes testes não existem no `checklist-testes.md` original porque só fazem sentido em coreografia.

### C1 Handler offline mid-flight (consumer perdido durante saga)

- **[x] executado.** Saga `a15a0e21` disparada. service-b parado aos 1s (queue `service-b.saga` ficou com 0 consumers). Após 5s, broker mostrava queue durável com mensagem persistida aguardando consumer. service-b reiniciado → consumiu `stock.reserved` da queue, processou `charge_credit`, publicou `credit.charged`, service-a retomou e completou a saga.
- **Veredito:** RabbitMQ persistência durável + queue durável + consumer reentrante cobrem o gap. **Saga completou** mesmo com handler offline durante o fluxo.
- **Cenário não coberto neste teste:** handler crashar mid-execution **depois** de aplicar efeito mas antes de publicar evento de sucesso. Esse é o gap não-atomicidade documentado em §2.3 do `findings-rabbitmq-coreografado.md` — solução clássica é outbox pattern.

### C2 Ordering parcial (compensação chega antes do sucesso do step)

- **[ ] não executado.** Cenário: saga A executa step 1 lentamente (10s sleep). Outra ação publica `saga.failed` para A antes do step 1 terminar. Comportamento esperado: a lib pula compensação via `wasStepDone()` retornando false (o step ainda não marcou `done`). Quando o step 1 enfim termina, `markStepDone` grava row — mas a saga já está marcada como falha em outro lugar.
- **Por que importa:** em coreografia distribuída, eventos `saga.failed` podem chegar antes de `*.ok` em consumer com lag. A lib precisa lidar com ordering parcial.
- **Por que não foi executado:** exige injeção controlada de `saga.failed` paralela; possível mas não trivial sem modificar a PoC.
- **Mitigação prevista:** `wasStepDone()` já cobre o caso conservadoramente (pula compensação se step não confirmou). Validar empiricamente é trabalho de adoção, não de PoC.

### C3 Loop de eventos (handler que publica evento que ele próprio consome)

- **[ ] não executado.** Cenário: erro de configuração — service-a tem `react('foo', ..., emit: 'foo', ...)`. Lib pública `foo`, próprio service-a consome, executa de novo, publica `foo` de novo, **infinite loop**.
- **Veredito sem rodar:** lib **não tem proteção** contra esse erro. RabbitMQ vai entregar mensagens at-least-once em loop até alguém intervir. Mensagens vão acumular memória do broker e travar.
- **Mitigação clássica:** detecção de TTL / max-redelivery via header `x-death`, ou regra de validação na lib que recusa `react()` onde `event == emit`. ~5 LOC. Não implementado na PoC.
- **Em produção:** vale validar via lint/teste estático antes de deploy, não em runtime.

### C4 Postmortem distribuído sem agregador

- **[skip]** Avaliação qualitativa só. Para uma saga que falhou há 3 horas em service-b: precisa correlacionar logs por `saga_id` em N serviços. Tempo estimado em produção: 2-15 min. **Mitigação obrigatória para adoção:** Saga Aggregator (§7 de `consideracoes.md`). Sem ele, postmortem é o ponto fraco do modelo.

---

## Sumário comparativo: Tier 1-6 cruzando os modelos

| Tier | Orquestrado RabbitMQ                 | Coreografado RabbitMQ                | Temporal             |
| ---- | ------------------------------------ | ------------------------------------ | -------------------- |
| T1.1 | Silent corruption                    | n/a (sem definição central)          | Panic LOUD           |
| T1.2 | Risco condicional                    | Risco condicional (lib mitiga)       | Estrutural ok        |
| T1.3 | 142 sagas/s burst                    | ~50 sagas/s end-to-end (single-thr.) | 28 sagas/s           |
| T1.4 | **Workers crashm**                   | Reconnect ok                         | Reconnect ok         |
| T4.1 | Saga ok (até 75s outage)             | Saga ok (até 12s outage)             | Saga ok (10s outage) |
| T4.2 | Silent corruption                    | Falha alta (saga.failed publicado)   | Workflow pausa       |
| T2.1 | Construir Filament sobre saga_states | Construir Saga Aggregator (~6 dias)  | Temporal Web grátis  |
| T2.3 | DB mente (gap T2.3)                  | DB distribuído consistente           | Engine consistente   |
| T3.2 | 137 MiB idle                         | 123 MiB idle                         | 439 MiB idle         |
| T3.4 | 2-15 min postmortem                  | 2-15 min postmortem                  | 30s-1min             |
| T4.4 | **Sem timeout**                      | **Sem timeout**                      | 4 tipos              |
| T5.1 | **Silent corruption**                | n/a (estruturalmente seguro)         | Panic LOUD           |
| T5.2 | Silent risk                          | Falha alta                           | Falha alta           |
| T6.2 | p99 23.8ms                           | **p99 20.4ms (mais rápido)**         | p99 351ms            |
| C1   | n/a                                  | ✅ ok (queue durável)                | (Activity retry)     |
| C2   | n/a                                  | ⚠ não validado                       | (engine resolve)     |
| C3   | n/a                                  | ⚠ sem proteção na lib                | (workflow protege)   |

**Pontos onde coreografia ganha sobre orquestrado:** T1.4, T2.3, T3.2, T4.2, T5.1, T5.2, T6.2.
**Pontos onde coreografia perde para orquestrado:** T2.1 (precisa construir agregador).
**Pontos onde ambos os modelos RabbitMQ perdem para Temporal:** T2.1 (precisa construir vs Temporal Web grátis), T3.4 (postmortem 2-15 min vs 30s-1min), T4.4 (sem timeout vs 4 tipos).
