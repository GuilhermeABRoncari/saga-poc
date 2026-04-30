# Considerações: pros e contras detalhados por abordagem

> Documento analítico para alimentar a decisão final em [`recomendacao-saga.md`](./recomendacao-saga.md). Complementa o comparativo de alto nível em [`estudo.md`](./estudo.md) e as medições concretas em [`findings-rabbitmq.md`](./findings-rabbitmq.md). É deliberadamente balanceado — cada ferramenta tem custo real e benefício real, e a recomendação só fechará após findings simétricos do PoC Temporal.
>
> **Atualizado em 2026-04-29** após baterias de testes Tier 1 a Tier 5 (ver [`checklist-testes.md`](./checklist-testes.md)). Mudanças relevantes:
>
> - **§1.1.7 NOVO** com medições de throughput, footprint e cold start (T1.3 + T3.2 + T3.3).
> - **§1.2.2 (at-least-once)** rebaixado de "certeza sem idempotência" para "risco condicional" — T1.2 não reproduziu duplicação na janela testada.
> - **§1.2.3 e §1.2.4 (sem timeline / sem replay)** reforçados com medição empírica em T3.4 — postmortem em RabbitMQ leva 2-15 min e não tem payloads de entrada; em Temporal leva 30s-1min com history completo.
> - **§1.2.10 NOVO** sobre reconexão de workers — T1.4 mostrou que PoC atual não reconecta workers quando broker cai; lacuna real da lib.
> - **§1.2.11 NOVO** sobre orchestrator marcando COMPENSATED antes da compensação completar — T2.3 mostrou que DB mente sobre estado real.
> - **§1.2.8 (versionamento implícito)** confirmado empiricamente em T1.1 — saga em voo completou silenciosamente com definição antiga.
> - **§2.1.4 (observabilidade)** reforçado com T3.4 — Temporal entrega payloads completos consultáveis sem o dev ter previsto consulta; RabbitMQ não.
> - **§2.1.5 (versionamento explícito)** confirmado em T1.5 — Temporal panic + getVersion como mitigação correta.
> - **§3 (cruzamento)** atualizado com medições de throughput, memória, tempo de detecção de falha, profundidade de postmortem e cold start.
> - **§4 NOVO** sobre alertas — implementação concreta do T2.2 enfraqueceu o argumento "Temporal entrega alertas grátis"; argumento que se mantém é "Temporal classifica Failed automaticamente para qualquer caminho de falha".
> - **§5 NOVO** sobre custo de "memória de longo prazo" — T3.3 mostrou que Temporal acumula state durável em Postgres (~+311 MB sob 5 min de load) enquanto RabbitMQ é "transport ephemeral" (volta ao baseline). Não é leak — é o preço da observabilidade.
> - **§1.2.13 NOVO** sobre falta de health-check de storage — T4.2 mostrou que lib RabbitMQ trata SQLite indisponível com falha silenciosa.
> - **§1.2.14 NOVO** sobre falta de conceito de timeout — T4.4 mostrou que handler travado em RabbitMQ bloqueia consumer indefinidamente; Temporal classifica 4 tipos de timeout distintos.
> - **§2.1.2 (durable execution)** reforçado com T4.1 — worker Temporal sobreviveu a 10s de network outage com 0 retries.
> - **§2.1.X NOVO** sobre classificação rica de falhas — T4.4 mostrou Temporal distinguindo `ActivityTaskTimedOut` (4 tipos) de `ActivityTaskFailed`.
> - **§1.2.8 (mudança de shape)** elevado a achado crítico: T5.1 reproduziu **silent corruption** real em RabbitMQ (saga `9b1213c2`: reserveStock executou 2x, chargeCredit nunca rodou, saga marcada COMPLETED). T5.1 é provavelmente o argumento mais forte do estudo a favor do Temporal no eixo "correção sob mudança de código".
> - **T5.2 (shape de payload)** mostrou empate prático — ambos compensam corretamente quando handler rejeita payload. Diferença marginal apenas em retry behavior.
> - **§1.1.7 (throughput) reforçado em T6.2** — RabbitMQ p99=22ms vs Temporal p99=351ms (~16x). Vantagem clara em latência e previsibilidade.
> - **§1.2.15 NOVO (deadlock SQLite sob concorrência)** — T6.2 revelou que lib RabbitMQ não tinha proteção contra "database is locked"; corrigido com 2 LOC (`PRAGMA busy_timeout` + `journal_mode=WAL`), mas é evidência de "classe de bug que ninguém testou".
> - **§6 NOVO (custo Temporal Cloud em escala)** — T6.1 estimou ~$4800/mês (~$58k/ano) para volume dos 4 sistemas; self-host EKS é a opção financeiramente sensata em escala >10M actions/mês.

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

#### 1.1.7 Throughput e footprint enxutos (NOVO — T1.3 + T3.2 + T3.3)

Validado empiricamente em testes T1.3 (burst), T3.2 (idle) e T3.3 (sustained):

- **Throughput burst (100 sagas concorrentes):** RabbitMQ **~48 sagas/s** vs Temporal ~28 sagas/s — **~1.7x mais rápido**.
- **Throughput sustentado (5 min × 10/s):** ambos ~9.5-9.7 sagas/s; 0 falhas em 2847-2909 sagas.
- **Latência fim-a-fim (T6.2 — 1000 sagas sequenciais):** RabbitMQ p50=21ms / p99=22ms (max 25ms, distribuição apertada) vs Temporal p50=60ms / p99=351ms (distribuição bimodal, ~16x mais lento em p99). RabbitMQ ~6.2x mais throughput sequencial.
- **RAM idle por stack:** RabbitMQ **~170 MB** vs Temporal **~439 MB** — **~2.6x mais leve**.
- **Tamanho das imagens Docker:** RabbitMQ **~665 MB** total vs Temporal **~3800 MB** total — **~6x menor** (PECL grpc + RoadRunner pesam).
- **Cold start cacheado:** RabbitMQ **~10s** até saga rodar vs Temporal **~30s** (afetado pela race condition documentada em [`findings-temporal.md`](./findings-temporal.md) §1 bug 3).
- **Cold start sem cache (estimado):** RabbitMQ **~2-3 min** vs Temporal **~25 min** (PECL grpc compile domina).
- **Latência de detecção de falha (alerter):** RabbitMQ **~1s** vs Temporal ~7s.
- **Memória sob load sustentado:** RabbitMQ volta ao baseline depois do load; Temporal acumula +300 MB no Postgres (history retention — não é leak, é storage de audit trail).

Para o volume esperado pelos 4 sistemas (<100 sagas/min agregadas), **ambos são adequados** em throughput. A folga operacional do RabbitMQ é maior em RAM, disco e cold start. Em cenários de pico não-previstos, RabbitMQ tem mais headroom; em cenários de devs com 4GB de RAM (CI runners, máquinas modestas), Temporal pode forçar tuning.

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

#### 1.2.2 At-least-once obriga idempotência por construção (revisado em T1.2)

Identificado teoricamente no [Cenário C](./findings-rabbitmq.md#63-cenário-c-at-least-once--execução-dupla-gap-identificado-não-testado) do PoC: se o orchestrator morre **entre** atualizar SQLite e publicar o próximo comando (ou entre publicar e ackar a msg que está sendo consumida), no restart a msg é redelivered e o comando republicado → handler executa duas vezes.

**T1.2 (2026-04-29) tentou reproduzir empiricamente e NÃO conseguiu na janela testada.** Hipótese: heartbeat AMQP padrão (60s) + restart rápido fazem com que RabbitMQ não tenha tempo de detectar conexão morta e reentregar a mensagem antes do novo orchestrator subir. A mensagem fica em "delivered to dead consumer" até o broker timeout.

Status atualizado: **risco condicional** dependendo do timing AMQP, não certeza. Em produção pode acontecer com `consumer_timeout`/`heartbeat` configurados de forma diferente, e a janela exata depende de:

- Configuração de heartbeat do broker.
- Velocidade de detecção de conexão morta.
- Velocidade de restart do orchestrator (se < heartbeat: provável evitar; se > heartbeat: provável reproduzir).

Implicações reais em produção, **caso aconteça**:

- `chargeCredit` cobrado duas vezes do cliente.
- `reserveStock` reservando estoque em duplicidade.
- `OAuthClient` criado em duplicidade no `users-api`.

A mitigação **continua sendo disciplina**: cada handler precisa checar antes de agir (idempotency_key, dedup table, unique constraints). Nunca é default da plataforma — é responsabilidade permanente do dev. **Em Temporal essa classe de bug é estruturalmente reduzida** (não eliminada) porque o engine garante exactly-once de activity execution na vasta maioria dos cenários via event sourcing — janela de risco fica em milissegundos entre completar activity e enviar resultado ao server, vs janela de segundos no RabbitMQ.

#### 1.2.3 Sem timeline visual nativa (reforçado em T3.4)

- RabbitMQ Management UI mostra filas, mensagens, throughput — mas **não** mostra "saga X passou pelos steps Y, Z, W".
- Para saber o que aconteceu numa saga específica, precisamos correlacionar logs de N containers pelo `saga_id` manualmente, ou construir UI custom.
- Postmortem vira arqueologia em log + query SQL na `saga_state`.

**Confirmação T3.4 (2026-04-29):** reconstruir o passo-a-passo de uma saga COMPLETED levou 2-5 minutos no melhor caso (saga simples). O Temporal Web UI faz o mesmo em 30s-1min com timeline visual e payloads expandidos.

#### 1.2.4 Sem replay de execução passada (reforçado em T3.4)

- Logs efêmeros (ou em ELK, depende do investimento).
- Estado SQLite só guarda o **snapshot atual**, não o histórico completo.
- Para "ver exatamente o que aconteceu na saga 1234 ontem às 14h", precisamos juntar logs de N serviços.
- Precisaria adicionar tabela `saga_events` append-only para ter algo equivalente — **1-2 dias de engenharia** ([`findings-rabbitmq.md`](./findings-rabbitmq.md) §4).

**Confirmação T3.4 (2026-04-29):** o gap real medido vai além de "sem timeline visual" — é que **payloads de entrada de cada step não são persistidos**. A lib guarda só o `result` retornado, não o payload com que o handler foi chamado. Para um postmortem do tipo "por que `chargeCredit` recebeu valor errado?" o dev precisa correlacionar logs de N services. Em Temporal, cada `ActivityTaskScheduled` tem `Input:[...]` no history.

Para chegar à paridade, RabbitMQ precisaria:

- Tabela `saga_events` append-only com input + output de cada step (~1-2 dias eng).
- Integração com ELK/Loki para correlacionar logs cross-service (~2-3 dias).
- UI custom para navegar o history (ou views Grafana — mais 1-2 dias).
- Lifecycle policy para purgar dados antigos (~0.5 dia).
- **Custo cumulativo: ~5-7 dias de eng** + manutenção recorrente.

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

#### 1.2.8 Mudança de shape de saga é problema implícito (CONFIRMADO CRÍTICO em T1.1 + T5.1)

- Se hoje `completed_steps[].step_index` é um inteiro `[0,1,2]`, e amanhã inserimos um step novo na posição 1, sagas em voo apontam para step errado.
- A solução existe, mas vira responsabilidade da lib OU do dev: migração de dados, status enums versionados, lógica condicional no orchestrator ("se saga começou antes da migração X, siga caminho velho").
- O problema **não desaparece em RabbitMQ** — é apenas implícito (e por isso mais perigoso, porque é fácil esquecer).

**Confirmação empírica T5.1 (2026-04-29) — provavelmente o achado mais sério do estudo:**

Cenário: saga em voo (reserveStock dormindo 15s); orchestrator restartado mid-flight com `definition()` reordenada (chargeCredit antes de reserveStock).

Resultado real medido na saga `9b1213c2`:

```
status: COMPLETED  ← (mentira: saga marcada como sucesso)
completed_steps:
  [{"index":0, "name":"charge_credit", "result":{"reservation_id":"res_73461e96"}},  ← name e result não batem
   {"index":1, "name":"reserve_stock", "result":{"reservation_id":"res_fa3b08dd"}}, ← reserveStock executou DE NOVO
   {"index":2, "name":"confirm_shipping", "result":{"tracking_code":"BR387995"}}]
```

Em produção, isso significaria:

- Estoque reservado **2 vezes** (`res_73461e96` + `res_fa3b08dd`).
- Pagamento (`chargeCredit`) **nunca executado** — o slot foi "consumido" pelo result residual de reserveStock.
- Pedido marcado como `COMPLETED` no DB.
- Cliente vê pedido confirmado, recebe item duas vezes do estoque, **não pagou nada**.

**Sem qualquer alerta, log de erro, exception ou sinal externo.** A lib não tem como detectar essa inconsistência — `status='COMPLETED'` é o que aparece no dashboard.

Em Temporal o cenário equivalente (T5.1) gerou panic explícito com mensagem detalhada (`history event is ServiceA.reserveStock, replay command is ServiceB.chargeCredit`), workflow stuck em retry até intervenção humana. Estado preservado, postmortem trivial.

Mitigação no RabbitMQ exige `saga_version` + lógica condicional + lint customizado, mas **mesmo assim depende do dev lembrar de bumpar a versão a cada mudança**. Esquecimento humano = corrupção em produção. Para 4 sistemas e 4 times durante anos, esse risco é cumulativo.

Este é o achado mais cético sobre RabbitMQ-PoC.

#### 1.2.9 Operação em produção

- Clustering em Swarm é doloroso (hostname pinning, volume constraints, peer discovery via classic config).
- RabbitMQ 4.x exige Quorum Queues para HA — Classic Mirrored foram removidas.
- Mínimo 3 nodes para tolerância a falhas reais.
- Recursos: 4GB RAM + 4 cores por node em produção (estimativa).
- Monitoring: Prometheus + Grafana + alertas custom.

#### 1.2.10 Workers da PoC não reconectam quando broker cai (NOVO — T1.4)

Validado empiricamente em **T1.4 (2026-04-29)**: ao matar o broker RabbitMQ enquanto sagas estão em voo, **todos os workers (`orchestrator`, `service-a`, `service-b`) caem com `AMQPProtocolConnectionException`** e ficam em status `Exited (255)` indefinidamente. Quando o broker volta, **não há reconexão automática** — a stack inteira fica down até intervenção manual (`docker compose up -d`).

Implicações:

- Em ambiente de produção com cluster RabbitMQ + quorum queues, derrubadas planejadas (rolling restart, upgrade) ou não-planejadas (split brain, deploy quebrado) param TODA a coordenação de sagas até alguém subir os workers manualmente.
- Sagas em voo ficam stuck — mensagens permanecem em queue durable, mas sem consumer não há progresso.
- Em Temporal o equivalente foi testado (T1.4 análogo): workers sobreviveram a 30s de Postgres caído e retomaram automaticamente quando voltou.

**Mitigação:** envolver `consume()` em try/catch + loop de reconexão com backoff exponencial. Custo: ~0.5 dia de eng + testes. Não é trabalho gigante, mas é mais um item da lista "tudo que você precisa construir" da lib interna, e é **bloqueante para produção** — sem isso a lib não é viável.

#### 1.2.11 Orchestrator marca COMPENSATED antes da compensação completar (NOVO — T2.3)

Validado empiricamente em **T2.3 (2026-04-29)**: o orchestrator atual publica TODAS as mensagens de compensação numa fila e **imediatamente seta `status='COMPENSATED'` no DB** — sem esperar ack dos handlers. Tempo medido: **103ms** do trigger até DB marcado COMPENSATED, enquanto handlers de compensação ainda estavam dormindo 3s.

Implicações:

- `SELECT * FROM sagas WHERE status='COMPENSATED'` **não garante** que estoque foi liberado e crédito reembolsado de fato.
- Em postmortem, o estado no DB pode dizer "compensada com sucesso" enquanto handler de `refundCredit` ainda nem foi executado (ou falhou silenciosamente).
- Combinado com §1.2.10: se broker cai durante a janela entre publish da compensação e execução do handler, a saga fica eternamente "COMPENSATED no DB / não compensada na realidade".

**Mitigação:** orchestrator precisa consumir eventos `compensation.completed` (não emitidos atualmente) e só marcar COMPENSATED quando todas chegarem. Custo: ~25 LOC + testes. **Bloqueante para produção** — sem isso, observabilidade é fundamentalmente quebrada.

#### 1.2.12 "Caminhos de falha" exigem código explícito para virar `status=FAILED` (NOVO — T2.2)

Validado em **T2.2 (2026-04-29)**: para que o alerter consiga detectar uma saga falhada, alguém precisa ter convertido a falha em `status='FAILED'` no DB. No PoC fizemos isso explicitamente para o caso "compensation.failed" (≈12 LOC em T1.2). Mas há outros caminhos de falha:

- Handler de step lança exception não tratada → emite `step.failed` → orchestrator compensa OK.
- Compensação lança exception → emite `compensation.failed` → orchestrator marca FAILED. ✅ (feito)
- Orchestrator crasha mid-compensação → saga fica RUNNING/COMPENSATING órfã indefinidamente. ❌ (não tratado)
- Mensagem em DLX por timeout consumer → não emite nada para o orchestrator. ❌
- Saga timeout absoluto → não há conceito de timeout na lib. ❌

Cada novo caminho de falha exige nova lógica de conversão na lib. Em Temporal, **qualquer falha terminal** vira `ExecutionStatus='Failed'` automaticamente (timeout, panic, retry esgotado, terminação manual) sem código nosso.

**Implicação prática:** alertas e dashboards no RabbitMQ são tão bons quanto a cobertura dos caminhos de falha pela lib. Esquecer um = saga silenciosamente quebra sem alerta. Disciplina permanente.

#### 1.2.13 Sem health-check de storage (NOVO — T4.2)

Validado parcialmente em **T4.2 (2026-04-29)**: ao mover o arquivo SQLite mid-flight, o orchestrator daemon ficou consultando o file descriptor antigo (arquivo já desvinculado do path), enquanto o trigger CLI criou novo arquivo vazio. Resultado: saga registrada num arquivo, daemon procurando em outro, **sem erro propagado para o usuário** — trigger retornou exit 0, daemon emitiu apenas `saga not found` em stderr.

Em produção (Postgres ou MySQL ao invés de SQLite local), o cenário equivalente é "DB unreachable" → PDOException. Lib atual não trata exceções de DB no orchestrator daemon → provável crash silencioso ou loop infinito.

**Mitigação:**

1. Try/catch em todas as queries com retry exponencial. (~10 LOC)
2. Health-check periódico do DB com circuit breaker. (~30 LOC)
3. Modo "degradado" que para de aceitar novas sagas quando storage indisponível, em vez de aceitar e perder. (~20 LOC)

**Custo: ~1 dia de eng**, mais um item para a lista de débitos pré-produção. Em Temporal, a equivalente "Postgres do server caído" foi testada em T1.4 — workers continuam, eventualmente erram, e RECUPERAM quando Postgres volta sem corrupção.

#### 1.2.14 Sem conceito de timeout de handler (NOVO — T4.4)

Validado em **T4.4 (2026-04-29)**: lib não tem mecanismo nativo para detectar handler travado. Se um handler chama HTTP sem timeout configurado, ou bloqueia em deadlock, **o consumer fica bloqueado indefinidamente** (qos=1 ⇒ uma mensagem por vez). Outras mensagens na fila ficam aguardando.

Implicações:

- Handler buggy = saga inteira para. Outras sagas no mesmo service também param.
- Sem timeout, postmortem é difícil: "saga X parou no step 2" sem saber se está travada ou apenas demorada.
- Não há classificação "timeout" vs "exception" — ambos viram `step.failed` igualmente.

**Mitigação para atingir paridade com Temporal:**

1. Timeout via `pcntl_alarm` ou exec em subprocess. (~30-50 LOC + edge cases)
2. Distinção entre "timeout" e "exception" no event emitido. (~5 LOC)
3. Documentação para devs definirem timeouts apropriados por handler.

**Custo estimado: ~1 dia de eng** + disciplina permanente para configurar timeout em cada novo handler.

#### 1.2.15 Deadlock SQLite sob concorrência (NOVO — T6.2)

Validado em **T6.2 (2026-04-29)**: rodar 1000 sagas sequenciais com bench script + orchestrator daemon ambos escrevendo no mesmo SQLite causou exception "SQLSTATE[HY000]: General error: 5 database is locked" após poucas iterações. **Lib não estava configurada com `PRAGMA busy_timeout` nem `journal_mode = WAL`** — defaults do SQLite serializam writers e falham fast em contention.

Fix aplicado em ~2 LOC:

```php
$this->pdo->exec('PRAGMA busy_timeout = 5000');
$this->pdo->exec('PRAGMA journal_mode = WAL');
```

Com isso, o bench passou de exception após 100 iterations para 1000 sagas completas em 21.5s sem nenhum lock issue.

**Achado paralelo importante:** este é o tipo de bug que aparece **só em testes de carga**. A lib provavelmente passaria em todos os testes de unidade e mesmo testes de integração com 1 saga por vez. Um time adotando a lib sem testar concorrência iria descobrir esse bug em produção. **Item para a lista de débitos pré-produção** — se for usar SQLite ou Postgres, configurar isolation/timeout corretos desde o dia 1.

Em produção com Postgres/MySQL, deadlocks ainda existem mas são gerenciados pelo DB engine. Lib precisa setar `PDO::ATTR_TIMEOUT` apropriado e tratar `PDOException` com retry exponencial. Mais código a manter.

---

### 1.3 Como mitigar os contras

| Contra                                                   | Mitigação                                                                                                                                                                                                                                                                                                                                                                                           | Custo                                |
| -------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ |
| Idempotência manual em tudo                              | Helper na lib que oferece `IdempotencyKey` derivado de `saga_id + step_name`. Tabela `processed_keys` consultada no início de cada handler.                                                                                                                                                                                                                                                         | ~1 dia + disciplina                  |
| Sem timeline visual                                      | Tabela `saga_events` append-only + script de export para Grafana ou UI minimalista.                                                                                                                                                                                                                                                                                                                 | 1-2 dias                             |
| Sagas órfãs                                              | Método `resumeStuckSagas()` rodando no boot do orchestrator.                                                                                                                                                                                                                                                                                                                                        | 1-2 dias                             |
| Bus factor                                               | Documentação obrigatória + dois mantenedores formalmente designados + ADR + code review centralizado de mudanças na lib.                                                                                                                                                                                                                                                                            | Recorrente                           |
| Disciplina erodindo o padrão                             | Lint customizado (PHPStan rule) que detecta uso não-padrão da lib.                                                                                                                                                                                                                                                                                                                                  | 1-2 dias                             |
| Mudança de shape (§1.2.8) **REFORÇADO/CRÍTICO**          | Versionamento explícito de saga_definition (ex.: coluna `saga_version` + `definition(int $version): array`). Confirmado em T1.5: 25-30 LOC infra + 10 LOC por saga. **T5.1 mostrou que sem isso, reordenação de step durante deploy gera silent corruption** (estoque duplicado, pagamento perdido, saga marcada COMPLETED). Mitigação técnica + lint, mas depende de disciplina humana permanente. | 1-2 dias + risco residual permanente |
| Workers não reconectam (§1.2.10) **NOVO/BLOQUEANTE**     | Try/catch + loop de reconexão com backoff exponencial em `AmqpTransport::consume()`. Confirmado em T1.4 como gap real.                                                                                                                                                                                                                                                                              | ~0.5 dia                             |
| DB mente sobre compensação (§1.2.11) **NOVO/BLOQUEANTE** | Orchestrator consumir `compensation.completed` events e só marcar COMPENSATED após todas chegarem. Confirmado em T2.3.                                                                                                                                                                                                                                                                              | ~25 LOC + testes                     |
| Caminhos de falha exigem conversão explícita (§1.2.12)   | Cobertura sistemática de cada caminho: step.failed, compensation.failed, orchestrator crash, DLX, timeout. Cada um vira código.                                                                                                                                                                                                                                                                     | ~3-5 dias para cobrir todos          |
| Health-check de storage (§1.2.13) **NOVO**               | Try/catch em queries + circuit breaker + modo degradado quando DB indisponível. Confirmado em T4.2 como gap real.                                                                                                                                                                                                                                                                                   | ~1 dia                               |
| Conceito de timeout de handler (§1.2.14) **NOVO**        | Timeout via `pcntl_alarm` + distinção timeout/exception nos events + documentação por handler. Confirmado em T4.4 como gap real.                                                                                                                                                                                                                                                                    | ~1 dia + disciplina permanente       |
| Alertas externos                                         | Alerter standalone (script PHP de ~40 LOC consultando `status='FAILED'` em SQLite) + integração webhook real (Slack/PagerDuty). Confirmado em T2.2.                                                                                                                                                                                                                                                 | ~0.5 dia                             |
| Deadlock de DB sob concorrência (§1.2.15) **NOVO**       | `PRAGMA busy_timeout` + `journal_mode=WAL` (SQLite) ou `PDO::ATTR_TIMEOUT` + retry com backoff (Postgres/MySQL). Confirmado em T6.2 — fix de 2 LOC bastou para a PoC, mas em produção precisa cobertura sistemática + testes de carga.                                                                                                                                                              | ~0.5 dia + testes de carga           |

**Custo total agregado para chegar a "produção responsável" (revisado pós-Tier 1+2+3+4+5+6):** **~17-23 dias de engenharia inicial** + manutenção recorrente. Tier 5 não adicionou novos custos (apenas reforçou T1.1). Tier 6 adicionou ~0.5 dia (§1.2.15). Continua viável, mas a margem encolheu mais.

---

## 2. Temporal

### 2.1 Pros

#### 2.1.1 SAGA first-class

- API `Workflow\Saga` declarativa: cada `addCompensation()` registra reversão para o step que acabou de rodar.
- LIFO automático na hora da reversão. Sem código nosso.
- Compensação em paralelo opcional (`setParallelCompensation(true)`).

#### 2.1.2 Durable execution out of the box (confirmado em T1.4 + T4.1)

- Estado da saga vive no engine (event-sourced em Postgres/MySQL/Cassandra), não no nosso banco.
- Sobrevive a crash de worker, restart de cluster, deploy do worker, deploy do server.
- "Mecanismo de resume on boot" é grátis — nem existe esse conceito explícito porque o engine sempre sabe onde retomar.

**Confirmações empíricas:**

- **T1.4 (2026-04-29):** Postgres do Temporal foi parado por 30s mid-flight. Workflow ficou pausado. Quando Postgres voltou, workflow retomou e completou normalmente. Sem corrupção.
- **T4.1 (2026-04-29):** `service-a-worker` foi desconectado da rede por 10s durante uma activity. Activity completou no `Attempt:1` (sem retry), buffer interno do worker preservou resultado, enviado ao reconectar. Worker resilient a network blips.
- O análogo no RabbitMQ-PoC (T1.4) mostrou comportamento dramaticamente diferente: workers caem com `AMQPProtocolConnectionException` e ficam Exited, exigindo intervenção manual. Diferença qualitativa: Temporal **sobrevive a falhas de infra sem trabalho do dev**; RabbitMQ exige código de reconexão na lib.

#### 2.1.3 Exactly-once de activity execution

- Engine garante (via event sourcing + workflow determinístico) que cada activity executa **exatamente uma vez** com sucesso.
- A classe de bug do Cenário C (RabbitMQ+lib) **não existe** aqui. Idempotência ainda é boa prática (em raros casos de timeout + recovery), mas não é responsabilidade central do dev.

#### 2.1.4 Observabilidade entrega timeline rica (reforçado em T3.4)

- **Temporal Web UI**: cada workflow tem timeline completa, com payload de entrada/saída de cada activity, retries, compensações, decisões. **Postmortem é navegação visual, não SQL.**
- Search por workflow ID, por type, por tag.
- Replay de execução passada literalmente possível (re-rodar workflow do início com histórico antigo).
- Sem custo de engenharia para chegar nesse nível.

**Confirmação empírica T3.4 (2026-04-29):** medimos o tempo prático para reconstruir o passo-a-passo de uma saga arbitrária:

- **Temporal:** 30s-1min via UI ou `tctl workflow show` (97 linhas de history com payloads completos, retry attempts, timing).
- **RabbitMQ:** 2-15min, com limitações severas — **payloads de entrada não são persistidos** (lib guarda só `result`); precisa correlacionar logs de N containers; sem replay programático.

**Insight chave:** o gap real não é "Temporal tem UI bonita". É **profundidade de informação consultável sem ter previsto a consulta**. Em Temporal, qualquer field que passou por uma activity está no history para sempre. Em RabbitMQ, se você não persistiu naquele momento, não dá para recuperar.

#### 2.1.5 Versionamento explícito (custo, mas honesto) — confirmado em T1.1+T1.5

- `Workflow::getVersion()` força o dev a tratar mudanças de shape conscientemente.
- Em RabbitMQ+lib o mesmo problema existe **implícito** (e por isso pior — é fácil esquecer).
- Para sagas curtas (segundos-minutos como nosso `ActivateStoreSaga`), o uso de getVersion é raríssimo: deploy → fila drena → todas as novas sagas usam código novo.

**Confirmação empírica:**

- **T1.1** mostrou Temporal lançando `[TMPRL1100]` panic explícito quando código de Workflow mudou enquanto saga estava em voo SEM `getVersion` — workflow ficou stuck até intervenção. RabbitMQ no mesmo cenário completou silenciosamente com a definição antiga (perigoso).
- **T1.5** mostrou Temporal lidando corretamente quando `getVersion` está aplicado: saga em voo (sem o novo step) e saga nova (com novo step) coexistiram no mesmo deploy. Custo: **4 LOC inline** no workflow code.
- O análogo manual em RabbitMQ exigiria coluna `saga_version` + `definition(int $version): array` + lógica de seleção: ~25-30 LOC infra na lib + ~10 LOC por saga concreta + lint customizado (~1-2 dias de eng).
- **Diferença real:** Temporal cobra o custo de versionamento **on-demand** (paga 4 LOC quando precisa); RabbitMQ cobra **upfront** (boilerplate em todas as sagas mesmo se nunca for usar).

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

#### 2.1.9 Classificação rica de falhas (NOVO — T4.4)

Validado em **T4.4 (2026-04-29)**: Temporal distingue na plataforma 4 tipos de timeout (`StartToClose`, `ScheduleToClose`, `ScheduleToStart`, `Heartbeat`) de falhas de aplicação (`ApplicationFailureInfo`). Eventos no history são distintos:

- `ActivityTaskTimedOut` + `TimeoutFailureInfo:{TimeoutType:...}` para timeouts.
- `ActivityTaskFailed` + `ApplicationFailureInfo:{Message:...}` para erros lançados pelo handler.

Implicações:

- RetryPolicy pode tratar tipos diferente via `NonRetryableErrorTypes`.
- Postmortem distingue "activity demorou demais" de "activity bugou" sem precisar de código nosso.
- Web UI mostra ícones distintos.

No RabbitMQ-PoC, **handler travado bloqueia consumer indefinidamente** (qos=1) e não há classificação — handler que dorme 5 minutos em deadlock parece igual a handler que retorna em 50ms. Para 4 sistemas com SLOs por step, essa distinção é importante na operação. Custo para implementar em RabbitMQ: ~1 dia de eng + disciplina (§1.2.14).

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

Tabela atualizada após bateria Tier 1 + Tier 2. Itens com asterisco têm medição empírica (não mais especulação).

| Critério                                              | RabbitMQ + lib interna                                                                            | Temporal                                                                                                              |
| ----------------------------------------------------- | ------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| **Adoção rápida com time atual**                      | ✅✅                                                                                              | ⚠️ (curva inicial real)                                                                                               |
| **Sem lock-in**                                       | ✅✅                                                                                              | ⚠️ (lock-in moderado, OSS)                                                                                            |
| **Durable execution out-of-the-box** ⭐ T1.4          | ⚠️ (parcial; workers caem com broker e não reconectam — §1.2.10)                                  | ✅✅ (sobreviveu a 30s de Postgres caído)                                                                             |
| **Exactly-once de activity** ⭐ T1.2                  | ⚠️ (risco condicional, não certeza — §1.2.2 revisado)                                             | ✅ (exactly-once estrutural)                                                                                          |
| **Observabilidade visual de saga**                    | ❌ (precisa construir)                                                                            | ✅✅                                                                                                                  |
| **Compensação first-class**                           | ⚠️ (constrói em ~30 LOC)                                                                          | ✅✅                                                                                                                  |
| **Compensação paralela** ⭐ T2.3                      | ⚠️ (paralelo natural por arquitetura, não controlável por LOC)                                    | ✅ (1 LOC switch)                                                                                                     |
| **Estado da compensação no DB confiável** ⭐ T2.3     | ❌ (lib atual mente — §1.2.11)                                                                    | ✅✅ (engine só marca completed após handlers terminarem)                                                             |
| **Replay/postmortem**                                 | ❌ (precisa construir)                                                                            | ✅✅                                                                                                                  |
| **Versionamento de saga** ⭐ T1.1+T1.5                | ⚠️ (implícito → silencioso; mitigação 25-30 LOC infra + 10 LOC/saga)                              | ✅ (panic explícito; mitigação 4 LOC inline com `getVersion`)                                                         |
| **Throughput (100 sagas concorrentes)** ⭐ T1.3       | ✅ ~48 sagas/s, 187 MB RAM total                                                                  | ⚠️ ~28 sagas/s, 629 MB RAM total                                                                                      |
| **Throughput sustentado (5 min × 10/s)** ⭐ T3.3      | ✅ 9.7/s, 0 falhas, RAM volta a baseline                                                          | ✅ 9.5/s, 0 falhas, +311 MB de history (não é leak)                                                                   |
| **Footprint idle (RAM total)** ⭐ T3.2                | ✅ ~170 MB                                                                                        | ⚠️ ~439 MB (~2.6x mais)                                                                                               |
| **Tamanho de imagens Docker** ⭐ T3.2                 | ✅ ~665 MB total                                                                                  | ⚠️ ~3800 MB total (~6x mais)                                                                                          |
| **Cold start cacheado** ⭐ T3.2                       | ✅ ~10s até saga rodar                                                                            | ⚠️ ~30s (afetado por race condition de inicialização)                                                                 |
| **Setup novo dev (sem cache)** ⭐ T3.1                | ✅ ~2-3 min                                                                                       | ❌ ~25 min (PECL grpc compile)                                                                                        |
| **Detecção de falha (alerta)** ⭐ T2.2                | ✅ ~1s lag (40 LOC alerter)                                                                       | ✅ ~7s lag (40 LOC alerter; lag dominado por retries)                                                                 |
| **Cobertura automática de caminhos de falha** ⭐ T2.2 | ❌ (cada caminho exige código — §1.2.12)                                                          | ✅✅ (Failed automático para qualquer falha terminal)                                                                 |
| **Postmortem / replay de saga antiga** ⭐ T3.4        | ⚠️ 2-15 min, sem payloads de entrada, sem replay                                                  | ✅✅ 30s-1min via UI/tctl, history completo, replay programático                                                      |
| **Resiliência a network outage (worker)** ⭐ T4.1     | ❌ workers caem com broker, não reconectam (T1.4)                                                 | ✅ worker buferou resultado em 10s outage, completou Attempt:1                                                        |
| **Robustez a falha de storage** ⭐ T4.2               | ❌ silent inconsistency, sem health-check (§1.2.13)                                               | ✅ workflows pausam até storage voltar, sem corrupção (T1.4)                                                          |
| **Conceito nativo de timeout** ⭐ T4.4                | ❌ inexistente; handler travado bloqueia consumer (§1.2.14)                                       | ✅ 4 tipos de timeout distintos + classificação no history                                                            |
| **Compensação trivial (falha em step 1)** ⭐ T4.3     | ✅ compensa em ms (sem retry)                                                                     | ✅ compensa em ~3s (3 retries default)                                                                                |
| **Reordenamento de steps durante deploy** ⭐ T5.1     | ❌❌ **silent corruption**: saga COMPLETED com state inconsistente, estoque 2x, pagamento perdido | ✅✅ panic LOUD com mensagem clara (`history event X vs replay Y`); workflow stuck até intervenção, estado preservado |
| **Mudança de shape de payload** ⭐ T5.2               | ✅ compensa corretamente, mensagem de erro nos logs (1 attempt)                                   | ✅ compensa corretamente, mensagem de erro no history (3 retries)                                                     |
| **Latência fim-a-fim p99** ⭐ T6.2                    | ✅✅ 22ms (max 25ms, distribuição apertada)                                                       | ⚠️ 351ms (~16x mais lento, distribuição bimodal)                                                                      |
| **Throughput sequencial** ⭐ T6.2                     | ✅ ~46 sagas/s                                                                                    | ⚠️ ~7.4 wfs/s (~6x menor)                                                                                             |
| **Custo Cloud em escala (estimado)** ⭐ T6.1          | ✅ self-host viável                                                                               | ❌ ~$58k/ano em volume agregado; inviável em escala — self-host EKS é a opção sensata                                 |
| **DX em code review**                                 | ✅ (PHP comum, espalhado em 6 arquivos)                                                           | ⚠️ (saga em 1 arquivo, mas precisa entender determinismo)                                                             |
| **Operação em produção**                              | ⚠️ (clustering RabbitMQ + lib que precisa cobrir 3 gaps bloqueantes)                              | ⚠️ (Temporal cluster ou Cloud)                                                                                        |
| **Custo financeiro 12 meses**                         | ✅ ($2400-4800 + ~15-20 dias eng)                                                                 | ⚠️ ($1200-2400 Cloud / $3000-6000 self-host)                                                                          |
| **Bus factor**                                        | ❌ (lib interna)                                                                                  | ✅ (SDK público com 2.4M installs)                                                                                    |
| **Maturidade da plataforma**                          | ✅✅ (RabbitMQ 18+ anos)                                                                          | ✅ (Temporal 5+ anos, mas crescendo rápido)                                                                           |

**Score qualitativo revisado pós Tier 1+2+3+4+5+6:**

- **Temporal vence:** durable execution, exactly-once, observabilidade visual, compensação first-class, estado da compensação confiável, replay, versionamento (com mitigação correta), cobertura automática de falhas, postmortem rico, resiliência a network outage, robustez a falha de storage, conceito de timeout nativo, bus factor, **reordenamento de steps (silent corruption no RabbitMQ)** = **14 critérios**.
- **RabbitMQ vence:** adoção rápida, sem lock-in, throughput burst, footprint idle, tamanho de imagens, cold start, setup novo dev, detecção de falha (lag), DX em code review, maturidade da plataforma, **latência p99**, **throughput sequencial**, **custo Cloud em escala** = **13 critérios**.
- **Empate/ambos com ressalvas:** operação em produção, throughput sustentado, compensação trivial, mudança de shape de payload (T5.2).

Tier 5 adicionou **reordenamento de steps** (silent corruption no RabbitMQ vs panic explícito no Temporal — assimétrico). Tier 6 adicionou 3 critérios onde RabbitMQ vence (latência, throughput sequencial, custo Cloud em escala). Score ficou quase empatado em quantidade (14 vs 13), mas a **natureza dos critérios continua assimétrica**.

A **assimetria de peso** continua sendo o ponto chave:

- Os critérios em que Temporal vence são **qualitativos** (correção, observabilidade, durabilidade, resiliência, segurança contra silent corruption) e ligados a confiança em produção.
- Os em que RabbitMQ vence são **quantitativos** (throughput, RAM, tamanho, lag) e ligados a DX local.

Na prática:

- **Para padrão organizacional usado por 4 sistemas durante anos:** os critérios qualitativos do Temporal pesam mais — especialmente após o achado T5.1 sobre silent corruption real (ver §5).
- **Para PoC isolada ou caso pontual:** os critérios quantitativos do RabbitMQ pesam mais.

O custo total da abordagem RabbitMQ subiu de ~10-15 dias (estimativa anterior) → ~15-20 dias após Tier 1+2 → ~17-22 dias após Tier 4 → **~17-22 dias após Tier 5** (sem novos custos, mas com **risco residual permanente** confirmado em T5.1). O "custo" de Temporal em RAM/disco/cold start é um trade-off, não débito.

---

## 4. Sobre alertas e observabilidade (NOVO — pós-T2.2)

A versão anterior deste documento sugeria que **Temporal entrega alertas grátis** enquanto **RabbitMQ exige construção significativa**. A implementação concreta no T2.2 mostrou que a diferença é mais matizada:

**O que ficou parecido:**

- Alerter standalone para "saga falhou" deu ~40 LOC nos dois lados (RabbitMQ poll de SQLite, Temporal poll via SDK).
- Tempo de implementação ponta a ponta foi similar (~10-20 minutos cada um).
- Memória idle: RabbitMQ 10 MB, Temporal 100 MB (vantagem RabbitMQ, mas não decisiva).
- Latência de detecção: RabbitMQ ~1s, Temporal ~7s (Temporal mais lento por design — espera retries esgotarem antes de marcar Failed).

**O que continua sendo vantagem real do Temporal:**

A vantagem **não é** "tempo de escrever o alerter" (similar). É "abrangência da detecção":

- **Temporal classifica `ExecutionStatus='Failed'` automaticamente para QUALQUER caminho de falha terminal**: timeout, panic, exception não tratada, retry esgotado, terminação manual, perda de heartbeat, etc. Um único alerter em `ExecutionStatus='Failed'` cobre 100% dos casos.

- **RabbitMQ exige código nosso para converter cada caminho de falha em `status='FAILED'`**:
  - `step.failed` → `compensate()` → `status=COMPENSATED` ✅ (lib atual)
  - `compensation.failed` → `status=FAILED` ✅ (lib atual após T1.2)
  - Orchestrator crash mid-compensação → saga órfã `RUNNING/COMPENSATING` ❌
  - Mensagem para DLX por timeout consumer → não notifica orchestrator ❌
  - Saga timeout absoluto → conceito não existe ❌
  - Esquecimento de cobrir um novo caminho em mudança futura → silenciosamente quebra ❌

Cada caminho é mais código + mais teste + mais chance de erro humano. Um alerter "completo" em RabbitMQ depende de a lib ter convertido todos os caminhos — o que é disciplina permanente, não one-shot.

**Ângulo concreto para a decisão:** assumir que o time vai cobrir todos os caminhos de falha sem regredir é a aposta cara. Se você acredita nessa disciplina, RabbitMQ é viável. Se não, Temporal compra essa garantia automaticamente.

---

## 5. Silent corruption sob mudança de código — o argumento mais forte (NOVO — pós-T5.1)

T5.1 (2026-04-29) reproduziu o cenário comum de produção: **reordenar steps de uma saga durante deploy, enquanto sagas estão em voo.** Aplicações reais fazem isso ao otimizar fluxos, mudar regras de negócio ou refatorar pedidos.

**Cenário de teste:** saga em voo (reserveStock dormindo 15s). Mid-flight, swap da ordem em código: chargeCredit antes de reserveStock. Restart do orchestrator (RabbitMQ) / workflow-worker (Temporal).

**Resultado RabbitMQ-PoC (saga real `9b1213c2`):**

- Status final: **`COMPLETED`** ← (mentira; saga marcada como sucesso)
- `completed_steps[0]` tem `name='charge_credit'` mas `result={reservation_id: res_73461e96}` — name e result não batem.
- `completed_steps[1]` tem reserveStock executando NOVAMENTE com `reservation_id: res_fa3b08dd` (diferente do anterior).
- chargeCredit nunca executou.
- Em produção: **estoque reservado em duplicidade, pagamento perdido, pedido marcado como sucesso, sem qualquer alerta**.

**Resultado Temporal:**

- Workflow panic com mensagem detalhada:
  ```
  [TMPRL1100] nondeterministic workflow:
  history event is ActivityTaskScheduled: ServiceA.reserveStock
  replay command is ScheduleActivityTask: ServiceB.chargeCredit
  ```
- Workflow stuck em retry (Attempt 1, 2, 3...) — não avança até intervenção humana.
- Estado preservado, postmortem trivial.
- **Sem corrupção, sem perda de dado, sem falsa promessa de sucesso.**

### Por que isso importa para a decisão organizacional

O ponto-chave não é "Temporal é melhor que RabbitMQ" abstrato. É que o **modo de falha é estruturalmente diferente**:

- **Temporal erra LOUD.** Workflow trava, mas o time descobre. Loss máximo: tempo de eng para resolver + sagas atrasadas até intervenção.
- **RabbitMQ-PoC erra SILENT.** Saga marcada como sucesso, dado corrompido, ninguém é notificado. Loss máximo: pagamentos perdidos, estoque duplicado, pedidos errados — descobertos só quando alguém faz auditoria contábil ou cliente reclama.

Para 4 sistemas (e-commerce, logística, financeiro, estoque) durante anos, com 4 times diferentes deploying independentemente, a probabilidade de algum dev esquecer de bumpar `saga_version` em algum deploy é, na prática, **certeza ao longo do tempo**.

### Mitigação no RabbitMQ exige disciplina permanente

A mitigação técnica é conhecida (`saga_version` + `definition(int $version)` + lint) — confirmada em T1.5 com custo de 25-30 LOC infra + 10 LOC por saga. Mas:

- **Lint estático não detecta todos os casos.** Mudança de ordem em array literal pode não disparar regra.
- **Code review centralizado também falha** sob pressão de prazo.
- **Em Temporal, a engine detecta automaticamente** sem depender de revisão humana.

### Quando este achado pode ser ignorado

Se o time se compromete a **NUNCA mudar a forma de uma saga depois que ela está em produção** (só mudar regras de negócio dentro dos passos, nunca adicionar/remover/reordenar passos), o risco do T5.1 desaparece. Mas isso é uma promessa improvável de cumprir por 4 times durante anos. Vide a pergunta-chave do tech lead em §7.

---

## 6. Custo de "memória de longo prazo" (NOVO — pós-T3.3)

**T3.3 (2026-04-29)** rodou 5 minutos × 10 sagas/s em ambos PoCs e revelou uma assimetria estrutural:

- **RabbitMQ stack:** memória cresce ~20-30 MB durante load, **volta ao baseline** quando load termina. Mensagens são ackadas e removidas; SQLite tem rows leves; processos liberam RAM.
- **Temporal stack:** memória cresce **+311 MB** durante load — Postgres acumula history events (cada workflow gera ~10 eventos), temporal server cacheia state, workers crescem ~50 MB cada. **Não volta ao baseline** ao fim do load.

**Esse não é um leak — é storage de audit trail durável.** Postgres do Temporal só limpa após o retention period (default: 7 dias para Completed). É exatamente o que torna `tctl workflow show` instantâneo dias depois (ver T3.4).

**Implicações para a decisão:**

- Em **RabbitMQ**, "lembrar" custa zero em memória, mas custa caro em postmortem futuro (porque não lembrou do que importava).
- Em **Temporal**, "lembrar" custa RAM e disco previsivelmente lineares com volume, mas paga em postmortem grátis depois.
- Se o time não vai investigar incidentes além de "deu erro? rerun", RabbitMQ é mais barato. Se o time vai querer entender por que `chargeCredit` recebeu valor X em saga `1234` há 3 dias, Temporal já tem a resposta. RabbitMQ exigiria ELK + dashboards + persistência de payloads — custo cumulativo.

**Cálculo grosseiro de retenção (pessoa-mês para 4 sistemas):**

- Volume estimado: 100 sagas/min × 60 × 24 × 30 = ~4.3M sagas/mês.
- Cada saga gera ~10 events × ~500 bytes = ~5 KB de history.
- 4.3M × 5 KB = **~21 GB/mês de history** em Postgres do Temporal.
- Retention de 7 dias: ~5 GB ativos a qualquer momento. Aurora Postgres lida sem problema.
- Em RabbitMQ, o equivalente para chegar à paridade de informação seria ~21 GB/mês em ELK ou Loki — ônus do time.

---

## 7. Custo financeiro de Temporal Cloud em escala (NOVO — pós-T6.1)

T6.1 (estimativa, não executado por falta de credenciais Cloud) projetou o custo de Temporal Cloud para o volume agregado dos 4 sistemas:

- Volume estimado: 100 sagas/min × 60 × 24 × 30 = ~4.3M sagas/mês por sistema; **~17M sagas/mês agregadas**.
- Cada workflow consome ~5-10 "actions" Temporal (start, decision tasks, activity scheduling, completion). Chute conservador: 7 actions.
- Total mensal: ~120M actions.
- Tier "Essentials" (~$100/mês) cobre 10M actions; acima disso é **~$0.04 por 1000 actions**.
- Cálculo grosseiro: 120M × $0.04/1000 = **~$4800/mês ≈ $58k/ano**.

Em comparação:

- **RabbitMQ self-hosted:** $200-400/mês (3 nodes) + ~17-23 dias eng inicial + manutenção recorrente.
- **Temporal Cloud Essentials/Growth:** $58k/ano. Inviável em escala.
- **Temporal self-host EKS:** $250-500/mês (Aurora + nodes EKS) + ~15 dias eng inicial + ~1-2 dias eng/mês de operação. Aproximadamente $3-6k/ano + tempo de operação.

**Conclusão prática:**

- Cloud só faz sentido **durante adoção** (primeiros 6-12 meses), antes de o time ter expertise para self-host.
- Para escala >10M actions/mês (qualquer um dos 4 sistemas, depois de adotado), **self-host EKS é a opção financeiramente sensata**.
- O custo de "operar Temporal self-host" não é trivial — Helm chart oficial existe, mas operar Postgres + indexação ES + 4 serviços é trabalho de SRE. Vide §2.2.5 ("Self-hosting é não-trivial").
- O argumento "Cloud reduz overhead inicial" do §2.1.7 continua válido — mas a saída Cloud → self-host depois é re-aponte de namespace + reconstrução de runbooks. Trabalho real, mas concentrado.

Esse cálculo deve entrar na decisão final como **TCO de 12-24 meses**, não como "Cloud é caro abstratamente".

---

## 8. O que ainda só vai ficar claro depois do PoC Temporal completo

- LOC reais de saga + activities + workers (vs 632 do RabbitMQ).
- Tempo de onboarding de um dev Laravel para escrever primeiro workflow (em dias).
- Frequência de erros de determinismo durante desenvolvimento.
- Comportamento real durante deploy mid-flight (rolling restart de workers).
- Cenários de resiliência simétricos aos rodados em RabbitMQ.
- Custo Cloud projetado no volume real esperado.
- DX de code review na prática (uma coisa é a teoria, outra é dois reviewers olhando código real).

Esses números fecham a tabela §3.2 da [`recomendacao-saga.md`](./recomendacao-saga.md) para o lado Temporal. Aí a recomendação pode sair do "em aberto" para "fechada com evidência".

---

## 9. Ângulo que pode mudar tudo

**Pergunta concreta que vale fazer ao tech lead antes de fechar:**

> Com que frequência você espera mudar a **forma** de uma saga (adicionar step no meio, reordenar, mudar compensação) vs mudar **regras de negócio dentro dos passos**?

- Se **a forma muda raramente** (típico): os 6 contras acima do versionamento Temporal somem na prática. Mudanças de regra de negócio vivem em Activities, que são PHP comum, e podem ser deployadas sem `getVersion()`.
- Se **a forma muda toda semana** (atípico, mas possível em ambiente experimental): o custo de versionamento Temporal vira fricção real. Mas isso sinaliza que a saga não é uma abstração estável — e o problema vai existir em qualquer orquestrador (RabbitMQ+lib idem, só que escondido).

A resposta calibra o peso desse critério no comparativo final.
