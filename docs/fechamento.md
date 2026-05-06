# Fechamento do estudo SAGA — narrativa de processo

Este documento registra **como o estudo evoluiu**: o que foi feito em cada iteração, onde mudamos de ideia, quais caminhos foram considerados e descartados. É a trilha de raciocínio do estudo, não a recomendação final (que vive em [`recomendacao-saga.md`](./recomendacao-saga.md)) nem a evidência empírica (que vive nos `findings-*.md` e em [`checklist-testes.md`](./checklist-testes.md)).

A separação importa: alguém que precisa **decidir** vai direto à recomendação; alguém que precisa **entender por que decidimos assim** lê este documento; alguém que precisa **conferir os números** lê os findings.

---

## 1. Estrutura do estudo

Quatro PoCs independentes, cada uma implementando o mesmo workflow de referência (3 passos: `ReserveStock` → `ChargeCredit` → `ConfirmShipping`, com `FORCE_FAIL=step3` exercitando reversão LIFO):

1. `saga-rabbitmq/` — RabbitMQ + lib interna **orquestrada**.
2. `saga-temporal/` — Temporal + RoadRunner workers PHP.
3. `saga-step-functions/` — AWS Step Functions em LocalStack.
4. `saga-rabbitmq-coreografado/` — RabbitMQ no estilo **coreografia**, sem orquestrador central.

Cada PoC foi submetida aos mesmos 20 testes Tier 1-6, congelados antes da implementação para evitar viés ex-post.

---

## 2. Iteração 1 — três PoCs orquestradas

A primeira iteração comparou três modelos **orquestrados**: RabbitMQ + lib interna, Temporal e Step Functions. O critério de inclusão foi "ferramenta candidata a coordenar SAGA com state machine central".

### 2.1 O que foi medido

20 testes Tier 1-6 cobrindo:

| Tier                        | Foco                                                                   |
| --------------------------- | ---------------------------------------------------------------------- |
| 1 — Alto valor, baixo custo | Versionamento, at-least-once, burst, falha de persistência, getVersion |
| 2 — Lacunas grandes         | Dashboard, alerta, compensação paralela, blind dev                     |
| 3 — Operacional             | Setup novo dev, footprint idle, sustained load, postmortem             |
| 4 — Resiliência             | Falha de rede, de storage, em step 1, timeout vs error                 |
| 5 — Versionamento ampliado  | Reordenar steps, mudar shape de payload                                |
| 6 — Custo real              | Cloud em escala, p99 fim-a-fim                                         |

Resultado: 18/20 testes executados, 2 não-executáveis (T2.4 blind dev exige humano externo; T6.1 Cloud em escala é estimativa).

### 2.2 Achados que pesaram

- **T5.1 — silent corruption sob reorder:** RabbitMQ-PoC marca saga `COMPLETED` com state corrompido (estoque 2x, pagamento perdido). Temporal panic LOUD. Achado mais grave da iteração.
- **T1.4 — broker outage:** workers `php-amqplib` morrem em `Exited (255)` sem auto-reconnect. Em Temporal, gRPC retry retoma quando server volta.
- **T4.4 — timeout:** Temporal classifica 4 tipos (`StartToClose`, `ScheduleToClose`, `ScheduleToStart`, `Heartbeat`); RabbitMQ-PoC não tem conceito de timeout — handler travado bloqueia consumer.
- **T6.2 — latência:** RabbitMQ p50=21.8/p99=23.8ms; Temporal p50=60/p99=351ms (~16× mais lento, distribuição bimodal).
- **T6.1 — custo Cloud:** Temporal Cloud para volume agregado (~17M sagas/mês × 7 actions) ~$58k/ano. Inviável em escala — força self-host.

### 2.3 Conclusão da iteração 1

Recomendação fechada: **adotar Temporal como padrão para SAGA orquestrada**, com Cloud nos primeiros 6-12 meses migrando para self-host depois. A justificativa primária era prevenção estrutural de silent corruption — a natureza qualitativa dos critérios em que Temporal vence supera os quantitativos em que RabbitMQ vence.

Score consolidado: Temporal 14, RabbitMQ 13, empates 4. Ranking quase empatado, mas com **assimetria de peso** — Temporal vencia em correção/durabilidade/observabilidade, RabbitMQ vencia em DX local + custo operacional.

---

## 3. Pré-review e reabertura

Antes do fechamento definitivo, pré-review identificou que a iteração 1 cobria apenas o **ramo orquestrado**. As três PoCs eram todas variações do mesmo modelo (orchestrator central + state machine no banco) — a comparação Tier 1-6, lida como "avaliação de todo o espaço de SAGA", ficava enviesada.

Três conclusões da iteração 1 precisavam ser relidas:

- **T5.1** não se aplica a coreografia — não há `saga_definition` central para reordenar.
- A discussão sobre tabela `saga_step` no banco assume orquestração centralizada.
- A leitura "minimizar responsabilidades sobre tabela central" também é compatível com **um modelo sem tabela central** — não obriga a escolher Temporal.

A decisão foi **reabrir o estudo** e construir uma 4ª PoC: `saga-rabbitmq-coreografado/`.

### 3.1 Mudança de enquadramento

Antes da iteração 2, o estudo era "Temporal × RabbitMQ × Step Functions". Depois, virou **"orquestração ⇄ coreografia × ferramenta"** — uma matriz 2×N. Isso muda como a recomendação é apresentada: não mais como escolha única, mas como **árvore de decisão por cenário**.

---

## 4. Iteração 2 — 4ª PoC coreografada

### 4.1 Modelo implementado

- Mesmos 3 passos do workflow de referência.
- Cada serviço publica eventos de domínio em tópico RabbitMQ (`stock.reserved`, `credit.charged`, `shipping.failed`).
- Step seguinte é disparado por subscription no evento anterior — sem orquestrador.
- Quando qualquer step publica `<step>.failed`, a lib publica `saga.<id>.failed` em fanout.
- Cada serviço consome `saga.<id>.failed` e roda compensação **se aplicável** (idempotente via dedup-key + `step_log`).
- Sem tabela `saga_states`, sem `saga_definition`, sem `saga_version`.

### 4.2 Mudança de hipótese durante o build

A tese inicial era "lib mínima <100 LOC". A lib amadureceu para **357 LOC** (3 arquivos: `EventBus`, `SagaLog`, `SagaListener`) — apenas ~6% menor que a orquestrada (381 LOC).

Por quê: para chegar a comportamento correto sob ordering parcial, retry de compensação e reconnect, foi necessário adicionar `step_log` (rastreio do que cada serviço executou de fato), retry exponencial na compensação, e detecção de `saga.failed` antes que o step propriamente dito tenha rodado. A tese "lib pequena" se sustenta como ordem de grandeza, mas não como diferenciador.

### 4.3 Onde coreografia ganhou

- **Latência sequencial:** p50=10.2ms, p99=20.4ms. ~2× mais rápido que orquestrado, ~17× mais rápido que Temporal. Razão: 3 hops fim-a-fim vs 5 do orquestrado (sem coordenador central).
- **Throughput:** ~94 sagas/s sequencial (orquestrado ~46/s, Temporal ~7.4/s).
- **Escritas no banco por saga:** **0 happy / 2 com compensação**. Orquestrado: 1. Temporal: 38 happy / 53 com compensação.
- **Acoplamento:** cada squad opera seu serviço; sem orquestrador como gargalo de governance.

### 4.4 Onde coreografia perdeu

- **DX em code review:** ler "o fluxo da saga X" exige juntar handlers em N serviços. Sem timeline central.
- **Postmortem:** sem audit trail unificado. Mitigação plausível é construir um **Saga Aggregator** (consumer dedicado + `saga_view` desnormalizada + UI tipo Temporal Web) — viável mas é trabalho real, documentado em [`consideracoes.md`](./consideracoes.md) §8.0.
- **Disciplina exigida:** idempotência por handler é obrigatória; um handler não-idempotente quebra silenciosamente sob retry.

### 4.5 Atualizações de versão durante a iteração

Durante a iteração 2, RabbitMQ subiu para **4.3** (Khepri/Raft, Mnesia removido, mirrored queues removidas). Todas as medições afetadas foram refeitas:

- Memória idle do broker caiu de 141 MB → 108 MiB (−23%).
- Throughput burst subiu de 48/s → 142/s (~3×, com classic durable).
- Quorum queues (única opção HA suportada em 4.x) custam **−25% de throughput** em single-node por causa do consenso Raft em cada `basic_publish`. T1.4 (workers não auto-reconectam) **não é mitigado** por quorum — é gap do lado client (`php-amqplib`), não do server.

E achado adicional do Temporal: **MariaDB não suportado** (Multi-Valued Indexes do MySQL 8 que MariaDB 11.4 não implementa). **MySQL 8 confirmado funcional** empiricamente (schema migration completa até 1.14, sem adaptação na PoC). Isso transforma "incompatibilidade Temporal × MariaDB" de deal-breaker em **item quantificável de TCO**: provisão de um SGBD adicional (~$30-150/mês de RDS/Aurora MySQL 8) mais uma rodada one-time de migração de schema/conexões. Detalhes em [`findings-temporal.md`](./findings-temporal.md) §2.2.6.

---

## 5. Decisão registrada — 5ª PoC descartada

Foi cogitado construir uma 5ª PoC simulando coreografia em Temporal — workers reagindo a `signals` em vez de um workflow orquestrador — para fechar a matriz ortogonal (orquestrado/coreografado × ferramenta).

**Decisão: não construir.** Motivos:

1. **Não é caso de uso recomendado pelo próprio Temporal.** Signals existem para input externo num workflow em curso, não para implementar coreografia distribuída. Construir a PoC seria forçar a ferramenta para fora do design intent.
2. **Não responde nenhuma pergunta nova.** Já há orquestração em Temporal e coreografia em RabbitMQ. Coreografia em Temporal não acrescenta evidência relevante porque o ganho da coreografia (ausência de orquestrador central, acoplamento mínimo, baixo footprint) **se opõe diretamente** ao ganho de Temporal (engine central com durable execution, audit trail, replay).
3. **Custo de oportunidade.** Esforço gasto numa 5ª PoC competiria com o que já temos — completar Tier 1-6 do coreografado, validar Saga Aggregator real, medir quorum em produção.

A combinação faltante (coreografado em Step Functions) também é descartada pelo mesmo argumento — Step Functions é orquestrador puro, sem semântica natural de coreografia.

**Quando reabrir essa decisão:** se Temporal lançar primitiva oficial para coreografia (improvável); se aparecer interesse externo em comparação ortogonal completa.

A matriz fica registrada como **3 ferramentas × 2 modelos = 4 combinações testadas** (orquestrado em RabbitMQ/Temporal/Step Functions + coreografado em RabbitMQ).

---

## 6. Como a recomendação evoluiu

| Momento                 | Forma da recomendação                                                                                                                                                                                                                                                                           |
| ----------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pré-PoC (narrativa)     | "Temporal por durable execution + audit trail." Baseado em marketing, sem evidência.                                                                                                                                                                                                            |
| Fim da iteração 1       | "Temporal para SAGA orquestrada." Baseado em 20 testes; viés do ramo único reconhecido como ressalva.                                                                                                                                                                                           |
| Pré-review              | **Reaberta.** "Falta a 4ª PoC; comparar como árvore de decisão, não escolha única."                                                                                                                                                                                                             |
| Fim da iteração 2       | Árvore de decisão por cenário: fluxo curto + multi-squad → coreografia; fluxo longo + audit → Temporal; AWS-native + free tier → Step Functions; orquestração média → RabbitMQ orquestrado com mitigações de T5.1.                                                                              |
| Fechamento (2026-05-06) | **Padrão organizacional adotado: RabbitMQ coreografado.** Árvore técnica preservada; filtro de custo aplicado por cima — RabbitMQ já na stack + lib <400 LOC + sem tabela central + sem infra nova é o piso de custo. Demais critérios qualitativos descartados como decisores conscientemente. |

A trajetória é o que esperávamos de um estudo honesto: começou em narrativa, virou evidência, foi corrigida ao perceber viés, e fechou em recomendação contextual.

---

## 7. Lições do processo

- **Congelar critérios antes de medir.** Os critérios da §3.2 da recomendação foram congelados pré-PoC e seguidos rigorosamente. Sem isso, qualquer resultado vira justificativa para a preferência inicial.
- **PoC = evidência, não exibição.** A PoC RabbitMQ orquestrada parecia "boa o suficiente" no happy path. T5.1 só apareceu sob mudança de código realista; T1.4 só apareceu sob falha de infra. Sem testes adversariais, esses gaps ficariam invisíveis.
- **Reabrir a decisão é mais barato que rever depois.** Reconhecer "faltou medir o ramo coreografado" antes de fechar exigiu uma iteração extra; descobrir isso após adoção em produção exigiria muito mais — refator distribuído num sistema já em uso.
- **Ferramenta certa depende do cenário.** Tentar fechar em "ferramenta vencedora" universal foi o viés da iteração 1. A árvore de decisão final reflete que **não existe vencedor único** — existem combinações apropriadas para cada cenário.

---

## 9. Decisão final — 2026-05-06

Após a iteração 2 e a maturação da 4ª PoC, a decisão organizacional foi fechada: **adotar SAGA coreografada com RabbitMQ** como padrão para os 4 sistemas (e-commerce, logística, financeiro, estoque).

### 9.1 Critério decisivo

**Custo.** O eixo financeiro de §4.5 passo 6 da recomendação foi promovido a decisor primário. RabbitMQ coreografado é o piso de custo possível para SAGA via filas porque acumula três zeros simultâneos:

1. **Zero infra nova:** RabbitMQ já está na stack em uso. Nenhum novo serviço a provisionar/operar.
2. **Zero SGBD dedicado:** o modelo coreografado dispensa tabela central de saga. Cada serviço usa o banco que já tem; quando o handler é stateless, dispensa banco também.
3. **Zero licenciamento/cluster próprio:** nem Temporal Cloud (~$58k/ano em escala) nem Temporal self-hosted (cluster + 2º SGBD MySQL 8 + Elasticsearch opcional) nem Step Functions (lock-in profundo + free tier insuficiente em escala).

Comparado a Temporal, a economia recorrente é da ordem de milhares de USD/ano + a ausência de uma nova superfície operacional para o time SRE manter.

### 9.2 O que foi conscientemente descartado como decisor

Os critérios em que Temporal vencia — observabilidade nativa, audit trail unificado, replay determinístico, conceito nativo de timeout, prevenção estrutural de silent corruption (T5.1) — são reconhecidos como reais e relevantes, mas **não foram suficientes** para justificar o custo adicional dada a previsão de fluxos curtos (≤ 3 steps) e múltiplos squads independentes. Documentar esse descarte explicitamente importa: se algum desses critérios virar requisito duro no futuro (compliance, postmortems frequentes, fluxos crescendo para 8+ steps), a decisão precisa ser revisitada — não esquecida.

### 9.3 Implicações imediatas

- A v1 da lib interna segue a especificação de §5.1 da recomendação — coreografada, sem porta para orquestrado, YAGNI estrito.
- A árvore de decisão por cenário (§4 da recomendação) **não é descartada** — vira referência para casos futuros que ofendam a premissa de custo.
- As três PoCs orquestradas (`saga-rabbitmq/`, `saga-temporal/`, `saga-step-functions/`) ficam preservadas no repositório como evidência do processo, não como caminho a seguir.

### 9.4 Quando reabrir a decisão organizacional

- Volume agregado crescer além de 10M actions/mês a ponto de o Saga Aggregator + disciplina de idempotência custar mais que um engine dedicado.
- Requisito de compliance/audit estrito surgir e exigir retention configurável + replay nativo.
- Fluxos reais começarem a passar de 7-8 steps com aninhamento, conforme §4.1 da recomendação.
- Custo operacional real do RabbitMQ + Saga Aggregator superar a estimativa que ancorou esta decisão.

---

## 10. Arquivos relacionados

- [`recomendacao-saga.md`](./recomendacao-saga.md) — recomendação consolidada como árvore de decisão.
- [`consideracoes.md`](./consideracoes.md) — prós/contras detalhados, §8.0 Saga Aggregator, §8.1 TCO em 3 cenários.
- [`checklist-testes.md`](./checklist-testes.md) — 20 testes Tier 1-6 com resultados detalhados.
- [`findings-rabbitmq.md`](./findings-rabbitmq.md) — medições da PoC RabbitMQ orquestrado (revalidado em 4.3 + análise quorum).
- [`findings-rabbitmq-coreografado.md`](./findings-rabbitmq-coreografado.md) — medições da PoC coreografada.
- [`findings-temporal.md`](./findings-temporal.md) — medições da PoC Temporal + achado MariaDB × MySQL 8.
- [`findings-step-functions.md`](./findings-step-functions.md) — medições da PoC Step Functions/LocalStack.
- [`glossario.md`](./glossario.md) — siglas e termos.
