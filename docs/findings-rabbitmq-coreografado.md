# Findings: PoC RabbitMQ Coreografado — medições para fechar a recomendação

> 4ª PoC do estudo. Documento simétrico a [`findings-rabbitmq.md`](./findings-rabbitmq.md) e [`findings-temporal.md`](./findings-temporal.md). Construído em 2026-04-30 após pré-review do tech lead identificar que as PoCs anteriores cobriram apenas saga orquestrada.

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

| Métrica                                                                | Valor                                              | Comparação RabbitMQ-orquestrado |
| ---------------------------------------------------------------------- | -------------------------------------------------- | ------------------------------- |
| Sessão de implementação                                                | 1 (∼1h)                                            | 1 (∼2h)                         |
| LOC totais                                                             | **428** (PHP em `src/` + `bin/`)                   | 632                             |
| LOC da lib `Mobilestock\SagaCoreografada`                              | **234** (3 arquivos: EventBus 81, CompensationLog 55, SagaListener 98) | 381 (6 arquivos) |
| LOC dos handlers (5 arquivos)                                          | 116                                                | ~73                             |
| LOC dos scripts em `bin/`                                              | 78                                                 | 140                             |
| Composer deps                                                          | 2 (`php-amqplib`, `ramsey/uuid`)                   | 3                               |
| Containers Docker                                                      | 3 (rabbitmq + 2 serviços)                          | 4                               |
| Tempo do primeiro `docker compose up --build`                          | ~2 min                                             | ~2 min                          |

**Observação:** lib é ~39% menor que a versão orquestrada (234 vs 381 LOC). Sem `SagaOrchestrator`, sem `SagaStateRepository`, sem `Saga`, sem `Step`. As três classes restantes (`EventBus`, `CompensationLog`, `SagaListener`) cobrem todo o ciclo. **Importante:** o claim do tech lead de "<100 LOC" não bateu — a lib mínima viável ficou em 234 LOC, e provavelmente vai crescer pra ~280-300 quando o achado 2.3 for resolvido (step_log local).

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

### 2.3 Compensação por falha em step2 (ChargeCredit) — ⚠️ ACHADO IMPORTANTE

Saga `12eb779c`:
1. ReserveStock completou.
2. ChargeCredit falhou (FORCE_FAIL=step2) — saga.failed publicado.
3. service-a executou ReleaseStock (correto — havia stock reservado).
4. **service-b executou RefundCredit — mesmo sem nunca ter cobrado nada.**

**Diagnóstico:** em coreografia pura, `saga.failed` é fanout; cada serviço executa sua compensação localmente. A lib mínima atual **não distingue** entre "este serviço completou seu step e precisa reverter" vs "este serviço nem chegou a executar". A lib só garante exactly-once via dedup-key (idempotência), não condicional-on-execution.

**Em produção isso seria bug:** "reembolsar" um pagamento que nunca foi cobrado pode estourar contas, criar inconsistência financeira, ou no melhor caso virar no-op silencioso.

**Soluções possíveis (a investigar):**
1. Cada serviço mantém log local de "executei step X com sucesso pra saga Y" (additional table `step_log`); compensação consulta esse log antes de executar.
2. Compensações inspecionam `failed_step` no payload de `saga.failed` e decidem heuristicamente se devem rodar (ex.: RefundCredit só roda se `failed_step != charge_credit`).
3. Compensação **é** idempotente E **detecta no efeito** se há algo a fazer (ex.: tenta deletar reservation_id; se não existe, vira no-op).

**Implicação na lib:** os 234 LOC atuais não são suficientes — vão crescer para incluir o "step log local". Estimativa pós-refactor: ~280-300 LOC. Ainda menor que orquestrado (381), mas o claim de "lib pequena, dev tem mínima responsabilidade" precisa ser qualificado: cada compensação ainda exige cuidado de design (idempotência + condicional-on-execution).

---

## 3. Critérios re-avaliados (vs orquestrado)

| Critério                            | RabbitMQ-orquestrado                            | RabbitMQ-coreografado                                                          | Vencedor                  |
| ----------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------------ | ------------------------- |
| LOC da lib                          | 381                                             | ~150-200                                                                       | **Coreografado**          |
| Versionamento de saga               | Implícito (silent corruption sem saga_version)  | Não se aplica — sem definição central                                          | **Coreografado**          |
| Reordenar steps em deploy           | T5.1: silent corruption                         | Cada serviço é dono da sua subscription — mudanças localizadas, sem corrupção  | **Coreografado**          |
| Acoplamento entre serviços          | Médio (orquestrador conhece todos)              | Mínimo (cada serviço conhece só eventos)                                       | **Coreografado**          |
| Compensação ordenada (LIFO)         | Garantida pelo orquestrador                     | Não garantida — fanout paralelo                                                | **Orquestrado**           |
| Compensação condicional (só se executou) | Garantida (orquestrador sabe quem executou) | ⚠️ requer step_log local em cada serviço (achado 2.3)                          | **Orquestrado**           |
| Postmortem visual                   | Tabela `saga_steps` central                     | Correlation-id + logs distribuídos por serviço                                 | **Orquestrado** (marginal)|
| Disciplina exigida do dev           | `saga_version` + lint + code review centralizado| Idempotência + step_log + tolerância a ordering parcial — distribuído por time | depende — não pior        |

---

## 3.A Volume de escritas no banco (medido 2026-04-30)

Critério levantado pelo tech lead após observar `laravel-workflow` fazendo 31+ inserções por workflow. Medição comparativa:

| Modelo                  | INSERTs happy-path     | INSERTs com compensação |
| ----------------------- | ---------------------- | ----------------------- |
| Temporal nativo         | **38**                 | **53**                  |
| RabbitMQ-orquestrado    | **1 INSERT + 4 UPDATEs** | **1 INSERT + ~6 UPDATEs** |
| RabbitMQ-coreografado   | **0**                  | **2** (1 em cada `compensation_log`) |

Detalhamento Temporal happy-path: history_node +12 (event sourcing), timer_tasks +12, transfer_tasks +8, visibility_tasks +3, executions +1, current_executions +1, history_tree +1.

**Implicações em escala (4 sistemas × ~1k-10k sagas/dia):**
- Temporal: 152k-2.1M INSERTs/dia só de metadados de workflow.
- Coreografado: 0-8k INSERTs/dia (só compensações).

Esse achado **fortalece o ramo coreografado** num critério quantitativo concreto que ainda não estava na comparação anterior — impacta latência (cada evento = round-trip), custo Cloud (Cloud cobra por action), e carga em Aurora self-host.

---

## 4. Próximos passos

- [ ] **Resolver achado 2.3** — implementar step_log local e re-testar step2/step1 falhando.
- [ ] **Tier 1-6 re-projetado** — executar testes adaptados (T1.3 concorrência, T1.4 broker caído, T2.2 idempotência, T3.4 postmortem distribuído, novos: ordering, handler perdido, loop).
- [ ] **Comparar throughput/latência** — pub/sub puro deve ser mais rápido que orquestrado (sem hop pra orquestrador).
- [ ] **Documentar o trade-off "ordem vs simplicidade"** — em coreografia, compensações em paralelo simplificam código mas exigem que cada uma seja independente.

---

## 5. Conclusão preliminar (ANTES dos testes Tier 1-6)

Coreografia entrega o que o tech lead pediu:
- **Lib é genuinamente menor** (234 LOC vs 381) — mesmo após adicionar step_log estimo ~280-300 LOC.
- **Sem tabela central de saga.** Cada serviço tem seu log local de compensação (e provavelmente de execução de step também).
- **Mudanças localizadas** — adicionar/remover step não requer migração de tabela central.

Mas há trade-offs reais não-triviais:
- **Achado 2.3** mostra que "lib mínima detecta erro e dispara compensação" não é suficiente — precisa de log de execução por serviço para evitar compensações falsas.
- **Postmortem distribuído** exige ferramenta externa (correlation-id em logs centralizados) — não vem grátis.
- **Ordering parcial** dos eventos pode produzir cenários onde compensação chega antes do evento de sucesso — precisa testar.

A recomendação final será uma **árvore de decisão**, não uma escolha única. Coreografia ganha em casos com 2-3 serviços simples e times independentes; orquestração (Temporal) ganha em casos com estado complexo, dependências entre passos, ou auditoria centralizada.
