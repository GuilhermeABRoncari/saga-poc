# Considerações: análise cross-tool de SAGA

> Documento de narrativa **cross-tool** — temas que cruzam as ferramentas avaliadas (RabbitMQ, Temporal, Step Functions) e merecem doc único. Complementa [`recomendacao-saga.md`](./recomendacao-saga.md), [`estudo.md`](./estudo.md) e os findings específicos de cada ferramenta.

## §0 Sumário — o que vive aqui

Este arquivo concentra análise transversal: comparação direta entre ferramentas, DX em code review, observabilidade, custos em escala, riscos de longo prazo. **Detalhes de prós/contras por ferramenta vivem nos findings:**

- [`findings-rabbitmq.md`](./findings-rabbitmq.md) — comportamento, gaps e medições do RabbitMQ + lib interna (orquestrado).
- [`findings-rabbitmq-coreografado.md`](./findings-rabbitmq-coreografado.md) — variante coreografada da PoC RabbitMQ.
- [`findings-temporal.md`](./findings-temporal.md) — comportamento, gaps e medições do Temporal.
- [`findings-step-functions.md`](./findings-step-functions.md) — comportamento, gaps e medições do AWS Step Functions.

Capítulos a seguir: §1 cruzamento ferramenta-a-ferramenta; §2 DX em code review; §3 alertas/observabilidade; §4 silent corruption sob mudança de código; §5 custo de "memória de longo prazo"; §6 custo financeiro em Cloud em escala; §7 plano técnico de Saga Aggregator; §8 TCO em 3 cenários; §9 ângulo que pode mudar tudo.

---

## §1 Cruzamento: o que cada ferramenta faz melhor

Tabela consolidada após baterias Tier 1 a Tier 6 (ver [`checklist-testes.md`](./checklist-testes.md)). Itens com referência `T*` têm medição empírica.

| Critério                                           | RabbitMQ + lib interna                                                          | Temporal                                                                             |
| -------------------------------------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| **Adoção rápida com time atual**                   | sim                                                                             | parcial (curva inicial real)                                                         |
| **Sem lock-in**                                    | sim                                                                             | parcial (lock-in moderado, OSS)                                                      |
| **Durable execution out-of-the-box** T1.4          | parcial (workers caem com broker e não reconectam)                              | sim (sobreviveu a 30s de Postgres caído)                                             |
| **Exactly-once de activity** T1.2                  | parcial (risco condicional, não certeza)                                        | sim (exactly-once estrutural)                                                        |
| **Observabilidade visual de saga**                 | não (precisa construir)                                                         | sim                                                                                  |
| **Compensação first-class**                        | parcial (constrói em ~30 LOC)                                                   | sim                                                                                  |
| **Compensação paralela** T2.3                      | parcial (paralelo natural por arquitetura, não controlável por LOC)             | sim (1 LOC switch)                                                                   |
| **Estado da compensação no DB confiável** T2.3     | não (lib atual mente: marca COMPENSATED em 103ms antes dos handlers terminarem) | sim (engine só marca completed após handlers terminarem)                             |
| **Replay/postmortem**                              | não (precisa construir)                                                         | sim                                                                                  |
| **Versionamento de saga** T1.1+T1.5                | parcial (implícito → silencioso; mitigação 25-30 LOC infra + 10 LOC/saga)       | sim (panic explícito; mitigação 4 LOC inline com `getVersion`)                       |
| **Throughput burst (100 sagas concorrentes)** T1.3 | sim ~142 sagas/s (4.3+Khepri); 187 MB RAM total                                 | parcial ~28 sagas/s, 629 MB RAM total                                                |
| **Throughput sustentado (5 min × 10/s)** T3.3      | sim 9.7/s, 0 falhas, RAM volta a baseline                                       | sim 9.5/s, 0 falhas, +311 MB de history (não é leak)                                 |
| **Footprint idle (RAM total)** T3.2                | sim ~137 MiB (4.3)                                                              | parcial ~439 MB (~3.2x mais)                                                         |
| **Tamanho de imagens Docker** T3.2                 | sim ~665 MB total                                                               | parcial ~3800 MB total (~6x mais)                                                    |
| **Cold start cacheado** T3.2                       | sim ~10s até saga rodar                                                         | parcial ~30s (afetado por race condition de inicialização)                           |
| **Setup novo dev (sem cache)** T3.1                | sim ~2-3 min                                                                    | não ~25 min (PECL grpc compile)                                                      |
| **Detecção de falha (alerta)** T2.2                | sim ~1s lag (40 LOC alerter)                                                    | sim ~7s lag (40 LOC alerter; lag dominado por retries)                               |
| **Cobertura automática de caminhos de falha** T2.2 | não (cada caminho exige código próprio)                                         | sim (Failed automático para qualquer falha terminal)                                 |
| **Postmortem / replay de saga antiga** T3.4        | parcial 2-15 min, sem payloads de entrada, sem replay                           | sim 30s-1min via UI/tctl, history completo, replay programático                      |
| **Resiliência a network outage (worker)** T4.1     | não workers caem com broker, não reconectam (T1.4)                              | sim worker buferou resultado em 10s outage, completou Attempt:1                      |
| **Robustez a falha de storage** T4.2               | não silent inconsistency, sem health-check                                      | sim workflows pausam até storage voltar, sem corrupção                               |
| **Conceito nativo de timeout** T4.4                | não inexistente; handler travado bloqueia consumer                              | sim 4 tipos de timeout distintos + classificação no history                          |
| **Reordenamento de steps durante deploy** T5.1     | não **silent corruption**: saga COMPLETED com state inconsistente               | sim panic LOUD com mensagem clara; workflow stuck até intervenção, estado preservado |
| **Mudança de shape de payload** T5.2               | sim compensa corretamente (1 attempt)                                           | sim compensa corretamente (3 retries default)                                        |
| **Latência fim-a-fim p99** T6.2                    | sim 22ms (max 25ms, distribuição apertada)                                      | parcial 351ms (~16x mais lento, distribuição bimodal)                                |
| **Throughput sequencial** T6.2                     | sim ~46 sagas/s                                                                 | parcial ~7.4 wfs/s (~6x menor)                                                       |
| **Custo Cloud em escala (estimado)** T6.1          | sim self-host viável                                                            | não ~$58k/ano em volume agregado; inviável — self-host é a opção sensata             |
| **DX em code review**                              | sim (PHP comum)                                                                 | parcial (saga centralizada em 1 arquivo, mas precisa entender determinismo)          |
| **Operação em produção**                           | parcial (clustering RabbitMQ + lib que precisa cobrir gaps bloqueantes)         | parcial (Temporal cluster ou Cloud)                                                  |
| **Bus factor**                                     | não (lib interna)                                                               | sim (SDK público com 2.4M installs)                                                  |
| **Maturidade da plataforma**                       | sim (RabbitMQ 18+ anos)                                                         | sim (Temporal 5+ anos, mas crescendo rápido)                                         |

### Pontos chave do RabbitMQ + lib interna

Os 5 pontos que mais pesam, condensados (detalhe completo em [`findings-rabbitmq.md`](./findings-rabbitmq.md)):

1. **Continuidade com a stack existente** — sem runtime novo, sem `yield`, sem determinismo. Code review entra na rotina.
2. **Throughput e footprint enxutos** — 142 sagas/s burst (T1.3), p99 de 22ms sequencial (T6.2), ~137 MiB idle, ~10s cold start.
3. **Tudo o que não é transport, você constrói** — state machine, idempotência, outbox, DLX, resume on boot, observabilidade. Custo agregado pré-produção: ~17-23 dias de eng inicial + manutenção recorrente.
4. **Silent corruption sob reordenamento de step** (T5.1) — saga marcada COMPLETED com state inconsistente, sem alerta, sem panic. É o argumento mais cético do estudo (ver §4).
5. **Lib atual mente sobre estado de compensação** (T2.3) — orchestrator marca COMPENSATED em 103ms enquanto handlers ainda dormem 3s. Bloqueante para produção; mitigação ~25 LOC.

### Pontos chave do Temporal

Os 5 que mais pesam (detalhe em [`findings-temporal.md`](./findings-temporal.md)):

1. **Durable execution out of the box** — sobreviveu a 30s de Postgres caído (T1.4) e a 10s de network outage de worker sem retries (T4.1).
2. **Observabilidade rica e profunda** — timeline visual, payloads de entrada/saída de cada activity persistidos, replay programático. Postmortem em 30s-1min via Web UI vs 2-15 min em RabbitMQ (T3.4).
3. **Versionamento explícito honesto** — panic LOUD em mudança de shape (T5.1); mitigação on-demand de 4 LOC com `getVersion` (T1.5).
4. **Dialética diferente do PHP comum** — workflow code é subset rígido (sem `date()`, sem `sleep()`, sem `PDO`, sem `rand()`). Curva de adoção real de 1-2 meses para time PHP-first; este é o maior custo de adoção.
5. **Custo Cloud cresce com volume** — em escala agregada (~120M actions/mês) chega a ~$58k/ano (T6.1). Cloud só faz sentido em adoção; self-host vira inevitável em escala.

### Score qualitativo

- **Temporal vence:** durable execution, exactly-once, observabilidade visual, compensação first-class, estado da compensação confiável, replay, versionamento (com mitigação correta), cobertura automática de falhas, postmortem rico, resiliência a network outage, robustez a falha de storage, conceito de timeout nativo, bus factor, **reordenamento de steps (silent corruption no RabbitMQ)** = **14 critérios**.
- **RabbitMQ vence:** adoção rápida, sem lock-in, throughput burst, footprint idle, tamanho de imagens, cold start, setup novo dev, detecção de falha (lag), DX em code review, maturidade da plataforma, **latência p99**, **throughput sequencial**, **custo Cloud em escala** = **13 critérios**.
- **Empate:** operação em produção, throughput sustentado, compensação trivial, mudança de shape de payload.

A **assimetria de peso** é o ponto chave:

- Critérios em que Temporal vence são **qualitativos** (correção, observabilidade, durabilidade, segurança contra silent corruption) e ligados a confiança em produção.
- Critérios em que RabbitMQ vence são **quantitativos** (throughput, RAM, tamanho, lag) e ligados a DX local.

Para padrão usado por múltiplos serviços durante anos, os critérios qualitativos pesam mais — especialmente após T5.1 sobre silent corruption real (ver §4). Para PoC isolada ou caso pontual, os critérios quantitativos pesam mais.

---

## §2 DX em code review

Comparação concreta com **dois cenários comuns de mudança em saga** contra as 4 PoCs existentes. Métricas: arquivos tocados, LOC tocadas (excluindo whitespace), legibilidade do diff sem rodar.

### Cenário A — Adicionar 1 step novo entre dois existentes (com sua compensação)

Tarefa: inserir um step `audit_log` (e sua compensação `unaudit_log`) entre `charge_credit` e `confirm_shipping`, ambos em `service-a`.

| Modelo                    | Arquivos tocados | LOC tocadas (aprox.) | Local da mudança                                                                        | Reviewer entende sem rodar?                                                                                                                                       |
| ------------------------- | ---------------- | -------------------- | --------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **RabbitMQ orquestrado**  | 3                | ~37                  | Centralizado em `definition()` + 2 arquivos de handler                                  | Sim — basta ler o array de `Step` na ordem.                                                                                                                       |
| **RabbitMQ coreografado** | 3                | ~33                  | Distribuído: 2 handlers novos + 2 chamadas `react()` em `bin/service-a.php` reordenadas | Parcial — reviewer precisa montar mentalmente a cadeia de eventos (`saga.started → … → audit.logged → saga.completed`); não há "definição central" para conferir. |
| **Temporal**              | 3                | ~30                  | Centralizado em `execute()` + 2 métodos novos na interface/implementação                | Sim — `execute()` é leitura linear; `addCompensation` deixa LIFO óbvio.                                                                                           |
| **Step Functions**        | 3-4              | ~50+                 | Centralizado em `state-machine.json` + 1 handler PHP + atualizar bootstrap/ARN          | Verboso — ASL exige Catch chain manual; reviewer precisa rastrear todos os `Catch.Next` para garantir LIFO.                                                       |

**Observações:**

- **Coreografado** "vence em LOC" mas "perde em legibilidade". O reviewer não consegue afirmar correção lendo só o diff — precisa mapear o grafo de eventos contra os outros serviços para confirmar que `audit.logged` será consumido por quem deveria.
- **Step Functions** é o mais verboso: cada `Catch` precisa repetir a estrutura `ErrorEquals/ResultPath/Next`, e adicionar um state intermediário muda múltiplos `Next` na cadeia de compensação.
- **Orquestrado e Temporal** têm DX equivalente para esse cenário — ambos centralizam a definição num arquivo legível.

### Cenário B — Reordenar 2 steps adjacentes

Tarefa: trocar a ordem de `reserve_stock` (step 0) e `charge_credit` (step 1), de modo que cobrança aconteça **antes** da reserva. Caminho de compensação muda em sequência.

| Modelo                    | Arquivos tocados | LOC tocadas (aprox.)                            | Risco em sagas em voo durante deploy                                                                                                                                                                                                                                                                                          |
| ------------------------- | ---------------- | ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **RabbitMQ orquestrado**  | 1                | ~6 (mover bloco no array)                       | **Silent corruption (T5.1)** — sagas em voo executam definição nova sobre estado salvo na ordem antiga, sem aviso.                                                                                                                                                                                                            |
| **RabbitMQ coreografado** | 2                | ~6 distribuídos                                 | Bagunça transitória — durante deploy, alguns serviços têm config nova e outros antiga; eventos podem ser publicados/consumidos numa cadeia inconsistente, mas **não há saga.definition central que entre em silent corruption** — cada serviço só processa o que entende. Risco: deadlock de cadeia (eventos não-consumidos). |
| **Temporal**              | 1                | ~4 (trocar 2 yields)                            | Replay panic (`TMPRL1100`) — workflow para de avançar e exige `Workflow::getVersion()` ou intervenção manual. **Honesto e seguro**.                                                                                                                                                                                           |
| **Step Functions**        | 1                | ~10+ (trocar `StartAt` + ajustar vários `Next`) | Imutabilidade — executions em voo continuam na versão antiga (Step Functions versiona implicitamente); só novas executions usam a definição nova.                                                                                                                                                                             |

**Observações:**

- **RabbitMQ orquestrado** é onde o cenário B mais machuca — diff trivial (~6 LOC), mas o achado T5.1 mostra que essa "trivialidade" esconde silent corruption. **Custo aparente baixo + risco real alto** = pior combinação para code review.
- **RabbitMQ coreografado** tem custo real ligeiramente maior (2 arquivos), mas o reviewer **não precisa pensar em "saga.definition central"** porque ela não existe. Risco de deploy é "cadeia de eventos quebra durante rolling update", não "saga COMPLETED com state corrompido".
- **Temporal** força o dev a tratar versionamento (custoso na primeira vez, salvador depois). Diff é minúsculo; risco é explícito.
- **Step Functions** versiona implicitamente — executions em voo nunca veem a definição nova. Operacionalmente robusto, mas o trade-off é que cada deploy gera nova `state-machine ARN`, e ARNs antigos só são limpos manualmente.

### Síntese

| Critério                                 | Vencedor                                                 |
| ---------------------------------------- | -------------------------------------------------------- |
| Cenário A — adicionar step               | Empate **Orquestrado / Temporal**                        |
| Cenário B — reordenar steps (diff size)  | **Temporal** (4 LOC)                                     |
| Cenário B — segurança em deploy          | **Temporal / Step Functions** (ambos seguros)            |
| Cenário B — silent corruption            | **Pior: RabbitMQ orquestrado** (T5.1)                    |
| Reviewer afirma correção sem rodar       | **Orquestrado / Temporal** (centralizados)               |
| Reviewer precisa montar grafo de eventos | **Coreografado** (mais difícil em fluxos médios/grandes) |

**Conclusão:** Temporal e RabbitMQ orquestrado têm **DX equivalente em mudanças aditivas**, mas Temporal vence claramente em **mudanças que reordenam ou removem steps** porque o engine força tratamento de versionamento. RabbitMQ coreografado paga um custo de DX que não aparece nos micro-cenários (LOC) mas aparece quando o fluxo cresce (5+ steps, múltiplos serviços) — o reviewer precisa de ferramentas externas (diagramas, traces) para validar coerência da cadeia. Step Functions tem segurança de deploy excelente mas verbosidade de ASL pesa em fluxos médios.

---

## §3 Alertas e observabilidade

Versões anteriores deste documento sugeriam que **Temporal entrega alertas grátis** enquanto **RabbitMQ exige construção significativa**. A implementação concreta no T2.2 mostrou que a diferença é mais matizada:

**O que ficou parecido:**

- Alerter standalone para "saga falhou" deu ~40 LOC nos dois lados (RabbitMQ poll de SQLite, Temporal poll via SDK).
- Tempo de implementação ponta a ponta foi similar (~10-20 minutos cada um).
- Memória idle: RabbitMQ 10 MB, Temporal 100 MB (vantagem RabbitMQ, mas não decisiva).
- Latência de detecção: RabbitMQ ~1s, Temporal ~7s (Temporal mais lento por design — espera retries esgotarem antes de marcar Failed).

**O que continua sendo vantagem real do Temporal:**

A vantagem **não é** "tempo de escrever o alerter" (similar). É "abrangência da detecção":

- **Temporal classifica `ExecutionStatus='Failed'` automaticamente para QUALQUER caminho de falha terminal**: timeout, panic, exception não tratada, retry esgotado, terminação manual, perda de heartbeat, etc. Um único alerter em `ExecutionStatus='Failed'` cobre 100% dos casos.

- **RabbitMQ exige código próprio para converter cada caminho de falha em `status='FAILED'`**:
  - `step.failed` → `compensate()` → `status=COMPENSATED` (lib atual)
  - `compensation.failed` → `status=FAILED` (lib atual após T1.2)
  - Orchestrator crash mid-compensação → saga órfã `RUNNING/COMPENSATING`
  - Mensagem para DLX por timeout consumer → não notifica orchestrator
  - Saga timeout absoluto → conceito não existe
  - Esquecimento de cobrir um novo caminho em mudança futura → silenciosamente quebra

Cada caminho é mais código + mais teste + mais chance de erro humano. Um alerter "completo" em RabbitMQ depende de a lib ter convertido todos os caminhos — o que é disciplina permanente, não one-shot.

**Ângulo concreto para a decisão:** assumir que o time vai cobrir todos os caminhos de falha sem regredir é a aposta cara. Se houver confiança nessa disciplina, RabbitMQ é viável. Se não, Temporal compra essa garantia automaticamente.

---

## §4 Silent corruption sob mudança de código — o argumento mais forte

T5.1 reproduziu o cenário comum de produção: **reordenar steps de uma saga durante deploy, enquanto sagas estão em voo.** Aplicações reais fazem isso ao otimizar fluxos, mudar regras de negócio ou refatorar pedidos.

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

### Por que isso importa para um padrão sustentável

O ponto-chave não é "Temporal é melhor que RabbitMQ" abstrato. É que o **modo de falha é estruturalmente diferente**:

- **Temporal erra LOUD.** Workflow trava, mas o time descobre. Loss máximo: tempo de eng para resolver + sagas atrasadas até intervenção.
- **RabbitMQ-PoC erra SILENT.** Saga marcada como sucesso, dado corrompido, ninguém é notificado. Loss máximo: pagamentos perdidos, estoque duplicado, pedidos errados — descobertos só quando alguém faz auditoria contábil ou cliente reclama.

Para múltiplos serviços (e-commerce, logística, financeiro, estoque) durante anos, com vários times deploying independentemente, a probabilidade de algum dev esquecer de bumpar `saga_version` em algum deploy é, na prática, **certeza ao longo do tempo**.

### Mitigação no RabbitMQ exige disciplina permanente

A mitigação técnica é conhecida (`saga_version` + `definition(int $version)` + lint) — confirmada em T1.5 com custo de 25-30 LOC infra + 10 LOC por saga. Mas:

- **Lint estático não detecta todos os casos.** Mudança de ordem em array literal pode não disparar regra.
- **Code review centralizado também falha** sob pressão de prazo.
- **Em Temporal, a engine detecta automaticamente** sem depender de revisão humana.

### Quando este achado pode ser ignorado

Se um time se compromete a **NUNCA mudar a forma de uma saga depois que ela está em produção** (só mudar regras de negócio dentro dos passos, nunca adicionar/remover/reordenar passos), o risco do T5.1 desaparece. Mas isso é uma promessa improvável de cumprir por vários times durante anos. Vide a pergunta-chave em §9.

---

## §5 Custo de "memória de longo prazo"

**T3.3** rodou 5 minutos × 10 sagas/s em ambos PoCs e revelou uma assimetria estrutural:

- **RabbitMQ stack:** memória cresce ~20-30 MB durante load, **volta ao baseline** quando load termina. Mensagens são ackadas e removidas; SQLite tem rows leves; processos liberam RAM.
- **Temporal stack:** memória cresce **+311 MB** durante load — Postgres acumula history events (cada workflow gera ~10 eventos), temporal server cacheia state, workers crescem ~50 MB cada. **Não volta ao baseline** ao fim do load.

**Esse não é um leak — é storage de audit trail durável.** O Postgres do Temporal só limpa após o retention period configurado. **O default do `temporalio/auto-setup` é 24h** (verificado em 2026-05-04). Para postmortem além de 24h, é necessário aumentar retention explicitamente ou exportar history events para storage frio.

**Implicações para a decisão:**

- Em **RabbitMQ**, "lembrar" custa zero em memória, mas custa caro em postmortem futuro (porque não lembrou do que importava).
- Em **Temporal**, "lembrar" custa RAM e disco previsivelmente lineares com volume, mas paga em postmortem grátis depois.
- Se o time não vai investigar incidentes além de "deu erro? rerun", RabbitMQ é mais barato. Se o time vai querer entender por que `chargeCredit` recebeu valor X em saga `1234` há 3 dias, Temporal já tem a resposta. RabbitMQ exigiria ELK + dashboards + persistência de payloads — custo cumulativo.

**Cálculo grosseiro de retenção (volume agregado dos serviços avaliados):**

- Volume estimado: 100 sagas/min × 60 × 24 × 30 = ~4.3M sagas/mês.
- Cada saga gera ~10 events × ~500 bytes = ~5 KB de history.
- Com **retention default de 24h**: ~700 MB ativos a qualquer momento (4.3M sagas/mês ÷ 30 = 143k sagas/dia × 5 KB). Aurora Postgres lida sem esforço; **storage não é decisor** com retention curta.
- Para postmortem além de 24h, aumentar retention para 7d sobe storage ativo para ~5 GB; para 30d, ~21 GB. Trade-off explícito entre profundidade de postmortem e custo de storage.
- Em RabbitMQ, o equivalente para chegar à paridade de informação seria ~21 GB/mês em ELK ou Loki — ônus do time.

---

## §6 Custo financeiro de Temporal Cloud em escala

T6.1 (estimativa, não executado por falta de credenciais Cloud) projetou o custo de Temporal Cloud para o volume agregado dos serviços avaliados:

- Volume estimado: 100 sagas/min × 60 × 24 × 30 = ~4.3M sagas/mês por sistema; **~17M sagas/mês agregadas**.
- Cada workflow consome ~5-10 "actions" Temporal (start, decision tasks, activity scheduling, completion). Chute conservador: 7 actions.
- Total mensal: ~120M actions.
- Tier "Essentials" (~$100/mês) cobre 10M actions; acima disso é **~$0.04 por 1000 actions**.
- Cálculo grosseiro: 120M × $0.04/1000 = **~$4800/mês ≈ $58k/ano**.

Em comparação:

- **RabbitMQ self-hosted:** $200-400/mês (3 nodes) + ~17-23 dias eng inicial + manutenção recorrente.
- **Temporal Cloud Essentials/Growth:** $58k/ano. Inviável em escala.
- **Temporal self-host Kubernetes:** $250-500/mês (Aurora + nodes) + ~15 dias eng inicial + ~1-2 dias eng/mês de operação. Aproximadamente $3-6k/ano + tempo de operação.

**Conclusão prática:**

- Cloud só faz sentido **durante adoção** (primeiros 6-12 meses), antes de o time ter expertise para self-host.
- Para escala >10M actions/mês (qualquer um dos serviços, depois de adotado), **self-host é a opção financeiramente sensata**.
- O custo de "operar Temporal self-host" não é trivial — Helm chart oficial existe, mas operar Postgres + indexação ES + 4 serviços é trabalho de SRE. Vide [`findings-temporal.md`](./findings-temporal.md) sobre incompatibilidade MariaDB e o segundo SGBD necessário.
- O argumento "Cloud reduz overhead inicial" continua válido — mas a saída Cloud → self-host depois é re-aponte de namespace + reconstrução de runbooks. Trabalho real, mas concentrado.

Esse cálculo deve entrar na decisão final como **TCO de 12-24 meses**, não como "Cloud é caro abstratamente".

---

## §7 Saga Aggregator — plano técnico para coreografia operacional

A maior fraqueza do modelo coreografado documentada neste estudo é **observabilidade**: cada serviço tem seu `step_log` local (SQLite/MariaDB), e não há "lista de sagas" centralizada como o Temporal Web entrega gratuitamente. Em produção real, o postmortem distribuído é arrasador (já documentado em T3.4).

A solução madura é construir um **Saga Aggregator** — um microsserviço dedicado que consome todos os eventos de saga publicados pelos serviços e popula uma tabela central `saga_view` desnormalizada, sobre a qual roda uma UI tipo Temporal Web.

Este plano técnico descreve o que precisa ser construído. **Não foi implementado nesta iteração** porque é trabalho de ~5-7 dias eng — registrá-lo aqui é parte da honestidade do estudo: defender coreografia exige assumir o custo de operacionalizá-la com observabilidade aceitável.

### §7.1 Arquitetura proposta

```
serviços (service-a, service-b, service-c, …)
        │
        │ publica saga.*, *.ok, *.failed, *.compensated em topic exchange
        ▼
   topic exchange `saga.events`
        │
        ▼
┌────────────────────────────┐
│  Saga Aggregator service   │
│  - subscribe `saga.#`       │
│  - upsert em saga_view     │
│  - expõe HTTP API          │
└────────────────────────────┘
        │
        ▼
   tabela `saga_view` (MariaDB) — desnormalizada
        │
        ▼
   UI Filament/Livewire (read-only) para postmortem
```

### §7.2 Schema da `saga_view`

Tabela única, otimizada pra leitura. Cada row é uma saga; payload de eventos vai num campo JSON.

```sql
CREATE TABLE saga_view (
  saga_id          CHAR(36) PRIMARY KEY,
  saga_type        VARCHAR(64)  NOT NULL,                   -- ex: 'order_creation'
  status           VARCHAR(16)  NOT NULL,                   -- RUNNING / COMPLETED / COMPENSATED / FAILED
  current_step     VARCHAR(64)  NULL,                       -- último step com evento publicado
  started_at       DATETIME(6)  NOT NULL,
  finished_at      DATETIME(6)  NULL,
  duration_ms      INT GENERATED ALWAYS AS (TIMESTAMPDIFF(MICROSECOND, started_at, finished_at)/1000) STORED,
  events_json      JSON         NOT NULL,                   -- [{step, ts, payload, status}]
  failure_reason   TEXT         NULL,
  trace_id         VARCHAR(32)  NULL,                       -- correlation com observabilidade existente
  INDEX idx_status_started (status, started_at DESC),
  INDEX idx_saga_type      (saga_type, started_at DESC),
  INDEX idx_trace          (trace_id)
);
```

### §7.3 Lógica do consumer

Um único consumer Laravel (ou worker simples PHP) ouvindo `saga.#` (topic wildcard) faz:

1. Recebe evento → extrai `saga_id`, `event_name`, `payload`, `timestamp`.
2. `INSERT … ON DUPLICATE KEY UPDATE` na `saga_view`:
   - Primeira vez: cria row com `status=RUNNING`, `started_at=now()`.
   - Eventos subsequentes: `JSON_ARRAY_APPEND(events_json, '$', evento)`, atualiza `current_step`.
   - Evento terminal (`saga.completed`/`saga.compensated`/`saga.failed`): atualiza `status`, `finished_at`, `failure_reason` se aplicável.
3. Idempotência: chave composta `(saga_id, event_name, timestamp)` no array — duplicatas detectáveis.

### §7.4 UI de postmortem

Filament admin panel sobre `saga_view`:

- **Lista paginada** com filtros: status, saga_type, range de datas, busca por `saga_id`.
- **Drill-down** por saga: mostra timeline visual dos events no `events_json` com payloads expandíveis.
- **Ações:** retry manual (republica `saga.started` com mesmo `saga_id` + payload original), abort (publica `saga.aborted`).
- **Métricas agregadas:** % completas/compensadas/falhas última hora, p50/p95/p99 de duração por `saga_type`.

### §7.5 Custos estimados

| Componente                                | Custo eng    | Custo operacional              |
| ----------------------------------------- | ------------ | ------------------------------ |
| Schema + migrations                       | ~0.5 dia     | -                              |
| Worker consumer + idempotência            | ~1.5 dia     | 1 container leve (~30 MiB RAM) |
| Filament admin (lista + drill-down)       | ~2 dias      | Roteia para banco existente    |
| Métricas agregadas (Grafana ou dashboard) | ~1 dia       | Reusa stack de observabilidade |
| Testes (unit + integração)                | ~1 dia       | -                              |
| **Total inicial**                         | **~6 dias**  | -                              |
| Manutenção recorrente                     | ~0.5 dia/mês | $30-50/mês de banco/container  |

### §7.6 Quando construir o Saga Aggregator

- **Antes** de adotar coreografia em produção. Sem ele, postmortem é doloroso e custoso (T3.4 mostrou 2-15 min por incidente real).
- **Depois** se a expectativa é volume baixo (≤ 100 sagas/dia) e o time é pequeno — nesse caso, postmortem manual via grep nos logs é suficiente.

### §7.7 Trade-off explícito vs Temporal

Construir o Saga Aggregator é **recriar parte do que o Temporal entrega de graça** (lista de workflows, drill-down, retry). A diferença é:

| Aspecto                            | Saga Aggregator (caseiro)     | Temporal Web              |
| ---------------------------------- | ----------------------------- | ------------------------- |
| Custo inicial                      | ~6 dias eng                   | $0 (vem com Temporal)     |
| Replay determinístico              | não há                        | nativo                    |
| Auditoria de payload entrada/saída | sim, se publicado nos eventos | nativo                    |
| Visualização gráfica do fluxo      | tabela + JSON                 | timeline gráfica nativa   |
| Custo de manter                    | ~0.5 dia/mês                  | $0 (Temporal mantém)      |
| Lock-in                            | nenhum                        | médio (Temporal-specific) |

**Resumindo:** o Saga Aggregator é viável mas é **trabalho real**. Quando o estudo defende coreografia em RabbitMQ, defende **com este custo agregado** — não como "coreografia é grátis".

---

## §8 TCO em 3 cenários

A discussão de custo até aqui ficou em prosa ("~3 dias eng", "~$30-150/mês", "$58k/ano em escala"). Esta seção modela 3 cenários de volume e calcula TCO 12 meses para cada combinação relevante de modelo+ferramenta. **Premissas comuns:** ambiente AWS, equipe com expertise prévia em PHP/MariaDB/Laravel, custo de eng ~$80/h ou ~$640/dia, 1 ano de horizonte.

### Cenário A — Volume baixo (100 sagas/dia ≈ 3k/mês)

Caso típico de operação interna ou produto early-stage.

| Combinação                          | Custo eng adoção | Custo recorrente 12m              | Total 12m  |
| ----------------------------------- | ---------------- | --------------------------------- | ---------- |
| RabbitMQ orquestrado + lib interna  | ~17 dias = $11k  | $50/mês broker self-hosted        | ~$11.6k    |
| RabbitMQ coreografado               | ~12 dias = $7.7k | $50/mês broker self-hosted + agg. | ~$8.3k     |
| **Temporal self-hosted (Postgres)** | ~25 dias = $16k  | ~$200/mês infra + $30 RDS         | ~$18.8k    |
| **Temporal Cloud**                  | ~15 dias = $9.6k | $25/mês free tier (3k abaixo)     | ~$9.9k     |
| **Step Functions**                  | ~10 dias = $6.4k | $0 free tier (4k transições/mês)  | **~$6.4k** |

**Vencedor:** Step Functions. Free tier cobre o volume; custo de adoção é o menor (~10 dias, ASL é simples para 3 steps).

### Cenário B — Volume médio (10k sagas/dia ≈ 300k/mês)

Caso típico de SaaS B2B em produção estabelecida.

| Combinação                             | Custo eng adoção | Custo recorrente 12m               | Total 12m   |
| -------------------------------------- | ---------------- | ---------------------------------- | ----------- |
| **RabbitMQ orquestrado + lib interna** | ~22 dias = $14k  | $1.2k/mês broker + storage         | **~$28.4k** |
| RabbitMQ coreografado                  | ~17 dias = $11k  | $1.2k/mês broker + saga aggregator | ~$25.4k     |
| Temporal self-hosted (Postgres)        | ~25 dias = $16k  | $4k/mês infra + DBA part-time      | ~$64k       |
| **Temporal Cloud**                     | ~15 dias = $9.6k | ~$1.6k/mês (Essentials + actions)  | **~$28.8k** |
| Step Functions                         | ~10 dias = $6.4k | ~$3k/mês ($0.025/transição × 1.2M) | ~$42.4k     |

**Empate técnico:** RabbitMQ orquestrado vs Temporal Cloud, ambos ~$28k em 12m. Decisão fica em DX, lock-in e capacidade SRE.

### Cenário C — Volume alto (100k sagas/dia ≈ 3M/mês)

Caso de marketplaces grandes ou backbones críticos.

| Combinação                             | Custo eng adoção | Custo recorrente 12m              | Total 12m |
| -------------------------------------- | ---------------- | --------------------------------- | --------- |
| RabbitMQ orquestrado (cluster HA)      | ~30 dias = $19k  | $5k/mês cluster quorum + obs      | ~$79k     |
| **RabbitMQ coreografado (cluster HA)** | ~25 dias = $16k  | $5k/mês cluster + saga aggregator | **~$76k** |
| Temporal self-hosted (Aurora HA)       | ~30 dias = $19k  | $8k/mês infra + DBA dedicado      | ~$115k    |
| Temporal Cloud                         | ~15 dias = $9.6k | ~$5k/mês ($58k/ano em escala)     | ~$67.6k   |
| Step Functions                         | ~10 dias = $6.4k | ~$25k/mês (12M transições)        | ~$306k    |

**Vencedor financeiro:** Temporal Cloud em pricing puro. Mas RabbitMQ coreografado é competitivo se o time tem capacidade SRE.

### Análise consolidada

| Volume | Vencedor financeiro                  | Vencedor por DX                              | Vencedor por risco operacional     |
| ------ | ------------------------------------ | -------------------------------------------- | ---------------------------------- |
| Baixo  | Step Functions                       | Empate Step Functions / RabbitMQ orquestrado | Step Functions (managed)           |
| Médio  | Empate RabbitMQ-orq / Temporal Cloud | Temporal Cloud (DX rica)                     | Temporal Cloud (managed)           |
| Alto   | Temporal Cloud                       | Temporal Cloud                               | RabbitMQ self-hosted (sem lock-in) |

**Limitações destes números:**

- Estimativas de adoção em dias eng são **otimistas** — assumem que o time já tem expertise nas ferramentas adjacentes (PHP, Laravel, AWS). Se não tiver, somar ~1 mês onboarding.
- Custos de cloud são para us-east-1 + uma instância pequena/média. Multi-AZ ou multi-região dobra ou triplica.
- Não inclui **custo de incidentes** (downtime, débitos pré-produção que vazam, etc.). Se o estudo for mais conservador, somar ~10-20% como provisão de risco.
- Não inclui **custo de migração** se já existe sistema legado (estimar entre 1.5× e 3× do custo de adoção greenfield).

### Como usar esta tabela

1. Estime seu volume mensal real de sagas (não chute alto — meça o piloto).
2. Ache o cenário mais próximo (A, B ou C).
3. Olhe os 3 vencedores (financeiro, DX, risco).
4. Se os 3 apontam pra mesma ferramenta, é decisão fácil. Se não, escolha por qual eixo é mais crítico para o produto.
5. **Caveat de longo prazo:** Cloud cresce com volume; self-hosted cresce com headcount/SRE. Se o produto vai 10× em 12 meses, o cenário pode mudar de A para B ou de B para C — escolher uma ferramenta que **acompanha** essa transição.

---

## §9 Ângulo que pode mudar tudo

**Pergunta concreta que vale registrar antes de fechar:**

> Com que frequência se espera mudar a **forma** de uma saga (adicionar step no meio, reordenar, mudar compensação) vs mudar **regras de negócio dentro dos passos**?

- Se **a forma muda raramente** (típico): os contras de versionamento Temporal somem na prática. Mudanças de regra de negócio vivem em Activities, que são PHP comum, e podem ser deployadas sem `getVersion()`.
- Se **a forma muda toda semana** (atípico, mas possível em ambiente experimental): o custo de versionamento Temporal vira fricção real. Mas isso sinaliza que a saga não é uma abstração estável — e o problema vai existir em qualquer orquestrador (RabbitMQ+lib idem, só que escondido).

A resposta calibra o peso desse critério no comparativo final — e é o ângulo que mais pode mudar a recomendação de ferramenta para um cenário concreto.
