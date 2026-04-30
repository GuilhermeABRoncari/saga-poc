# Fechamento do PoC SAGA — síntese das 6 baterias de teste

> Documento de consolidação da decisão. Resume **20 testes Tier 1-6** executados contra três PoCs reais (`saga-rabbitmq/`, `saga-temporal/`, `saga-step-functions/`), implementando o mesmo workflow de referência (`ActivateStoreSaga` reduzido). Toda a evidência empírica + análise está em [`checklist-testes.md`](./checklist-testes.md), [`findings-rabbitmq.md`](./findings-rabbitmq.md), [`findings-temporal.md`](./findings-temporal.md), [`findings-step-functions.md`](./findings-step-functions.md) e [`consideracoes.md`](./consideracoes.md).
>
> ## ⚠️ Atualização 2026-04-30 — fechamento revisado para REABERTO
>
> Após pré-review com o tech lead, foi identificado que **as três PoCs implementaram saga orquestrada** (orquestrador central + state machine no banco), enquanto a proposta original do tech lead era **saga coreografada** (sem tabela central, lib mínima de compensação por evento, handlers idempotentes em cada serviço).
>
> Implicações sobre o conteúdo deste documento:
>
> - **T5.1 ("silent corruption sob reordenamento")**, citado várias vezes abaixo como achado mais grave, **não se aplica** ao modelo coreografado — não existe `saga_definition` central para reordenar.
> - O score consolidado (Temporal vence 14, RabbitMQ vence 13) está **enviesado**: comparou Temporal-orquestrador × RabbitMQ-orquestrador × Step Functions-orquestrador, todos no mesmo modelo. Falta o ramo coreografado.
> - A resposta do tech lead em §5 ("mínimo de responsabilidades ao dev pra manusear `saga_step` no banco") foi reinterpretada: ele estava sinalizando que **`saga_step` não devia existir**, ou seja, descartando orquestração com state machine — não descartando RabbitMQ como ferramenta. A leitura técnica em §5 que classificava isso como "reforço pra Temporal" estava **incorreta**.
>
> **A recomendação de adotar Temporal foi reaberta.** Próximos passos antes de re-fechar: 4ª PoC (`saga-rabbitmq-coreografado/`), re-projeção dos testes Tier 1-6 para o novo modelo, reformulação da recomendação como **árvore de decisão** orquestração ⇄ coreografia. Detalhes em [`recomendacao-saga.md`](./recomendacao-saga.md) §10.
>
> O conteúdo abaixo é o estado em 2026-04-29 — útil como referência para o ramo orquestrado, mas **não representa a decisão vigente**.

---

## 1. O que foi testado

| Tier                        | Testes                                                                                                                             | Status                               |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ |
| 1 — Alto valor, baixo custo | T1.1 versionamento; T1.2 at-least-once; T1.3 100 sagas concorrentes; T1.4 falha de persistência; T1.5 `Workflow::getVersion()`     | 5/5                                  |
| 2 — Lacunas grandes         | T2.1 dashboard Grafana (estimado); T2.2 alerta de falha (implementado); T2.3 compensação paralela; T2.4 blind dev (não-executável) | 3/4                                  |
| 3 — Operacional             | T3.1 setup novo dev (estimado); T3.2 footprint idle; T3.3 sustained load 5min; T3.4 postmortem                                     | 3/4                                  |
| 4 — Resiliência adicional   | T4.1 falha de rede; T4.2 falha de storage; T4.3 falha em step 1; T4.4 timeout vs error                                             | 4/4                                  |
| 5 — Versionamento ampliado  | T5.1 reordenar steps; T5.2 mudar shape de payload                                                                                  | 2/2                                  |
| 6 — Custo real              | T6.1 Cloud em escala (estimado); T6.2 p99 fim-a-fim                                                                                | 1/2                                  |
| **Total**                   | **20 testes**                                                                                                                      | **18 executados, 2 não-executáveis** |

---

## 2. Achados decisivos

### 2.1 Em favor do Temporal (críticos)

**T5.1 — Silent corruption sob reordenamento de steps (ACHADO MAIS GRAVE DO ESTUDO)**

Reordenar a ordem de `addCompensation` / steps em `definition()` enquanto sagas estão em voo:

- **Temporal:** panic explícito `[TMPRL1100] history event is ServiceA.reserveStock, replay command is ServiceB.chargeCredit`. Workflow stuck em retry até intervenção. Estado preservado.
- **RabbitMQ-PoC:** saga marcada `COMPLETED` com state corrompido — saga `9b1213c2`: `reserveStock` executou 2x, `chargeCredit` nunca rodou, pedido marcado completo. **Sem qualquer alerta, log de erro ou sinal externo.**

Em produção: estoque reservado em duplicidade, pagamento perdido, pedido marcado como sucesso. **Pior cenário possível.**

**T1.4 — Workers RabbitMQ não reconectam quando broker cai**

`docker stop saga-rabbitmq-rabbitmq-1` mid-saga → todos os 3 workers PHP morrem com `AMQPProtocolConnectionException` e ficam em `Exited (255)` indefinidamente. Sagas em voo ficam stuck até intervenção manual.

Em Temporal análogo: workers continuam rodando, retentam gRPC, retomam quando server volta.

**T2.3 — DB do RabbitMQ mente sobre estado da compensação**

Orchestrator marca saga `COMPENSATED` em ~103ms enquanto handlers de compensação ainda estão dormindo 3s. `SELECT * FROM sagas WHERE status='COMPENSATED'` não garante que a reversão real aconteceu.

**T3.4 — Postmortem rico vs arqueologia**

Reconstruir o passo-a-passo de uma saga arbitrária: Temporal 30s-1min via UI/tctl com payloads completos de cada step. RabbitMQ 2-15min com payloads de entrada **não persistidos** pela lib (só `result`).

**T4.4 — Conceito de timeout de handler**

Temporal classifica 4 tipos de timeout (`StartToClose`, `ScheduleToClose`, `ScheduleToStart`, `Heartbeat`) distintos de errors aplicacionais. RabbitMQ-PoC não tem conceito de timeout — handler travado bloqueia consumer indefinidamente.

### 2.2 Em favor do RabbitMQ (quantitativos)

**T6.2 — Latência fim-a-fim**

RabbitMQ p50=21ms / p99=22ms (max 25ms, distribuição apertada). Temporal p50=60ms / p99=351ms (~16x mais lento, distribuição bimodal).

**T1.3 + T3.2 + T3.3 — Footprint operacional**

|                            | RabbitMQ | Temporal                    |
| -------------------------- | -------- | --------------------------- |
| RAM idle (stack)           | 170 MB   | 439 MB (~2.6x)              |
| Imagens Docker             | 665 MB   | 3800 MB (~6x)               |
| Cold start cacheado        | ~10s     | ~30s                        |
| Setup novo dev (sem cache) | ~2-3 min | ~25 min (PECL grpc compile) |

**T6.1 — Custo Cloud em escala**

Temporal Cloud para volume agregado dos 4 sistemas (~17M sagas/mês × 7 actions): **~$58k/ano**. Inviável em escala — força self-host EKS, que adiciona ~15 dias eng inicial + 1-2 dias eng/mês.

### 2.3 Empates / inconclusivos

- **T1.2** (at-least-once duplicação): não reproduzido na janela testada; risco condicional, não certeza.
- **T5.2** (mudança de shape de payload): ambos compensam corretamente.
- **T4.3** (falha em step 1, compensação trivial): ambos OK.
- **T1.3** (throughput burst): RabbitMQ ~1.7x mais rápido, mas ambos cobrem volume esperado.

---

## 3. Custo total revisado

### 3.1 Caminho RabbitMQ + lib `mobilestock/saga`

Achados das 6 baterias adicionaram **5 itens bloqueantes ou semi-bloqueantes** que não estavam na estimativa original:

| Item                                                                                     | Origem            | Custo                       |
| ---------------------------------------------------------------------------------------- | ----------------- | --------------------------- |
| 9 itens originais (idempotência, observabilidade, resume, bus factor, lint, shape, etc.) | findings iniciais | ~10-15 dias                 |
| Reconexão automática de workers                                                          | T1.4 (BLOQUEANTE) | +0.5 dia                    |
| Wait-for-ack na compensação                                                              | T2.3 (BLOQUEANTE) | +1 dia                      |
| Cobertura de caminhos de falha → status=FAILED                                           | T2.2              | +3-5 dias                   |
| Health-check de storage                                                                  | T4.2 (BLOQUEANTE) | +1 dia                      |
| Conceito de timeout de handler                                                           | T4.4              | +1 dia                      |
| Deadlock de DB sob concorrência (WAL/busy_timeout)                                       | T6.2              | +0.5 dia                    |
| **Total revisado**                                                                       |                   | **~17-23 dias eng inicial** |

Mais manutenção recorrente, lint custom, code review centralizado, disciplina permanente em todos os 4 times.

### 3.2 Caminho Temporal

Achados das 6 baterias confirmaram capacidades nativas — nenhum item bloqueante adicional.

| Item                                                                                        | Custo                                                                    |
| ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| Adoção: pacote interno `mobilestock/laravel-temporal-saga` (esconde RoadRunner + dialética) | ~5-7 dias inicial                                                        |
| Lint PHPStan (proíbe `date()`, `rand()`, `PDO`, `Http::` em workflow code)                  | ~1-2 dias                                                                |
| Treinamento + exemplos canônicos                                                            | ~2-3 dias + 1 semestre de calibração                                     |
| Operação inicial (Cloud) ou self-host EKS                                                   | $100-200/mês Cloud OU 15 dias eng + $250-500/mês infra                   |
| **Total**                                                                                   | **~10 dias eng inicial + custo recorrente Cloud OU 25 dias eng + infra** |

---

## 4. Score consolidado pós Tier 1-6

- **Temporal vence em 14 critérios** (qualitativos: durable execution, exactly-once, observabilidade, replay, postmortem, conceito de timeout nativo, classificação de falhas, segurança contra silent corruption, etc.).
- **RabbitMQ vence em 13 critérios** (quantitativos: throughput, latência, RAM, disco, cold start, custo Cloud em escala, etc.).
- **4 empates** (operação em produção, throughput sustentado, compensação trivial, mudança de shape de payload).

A vitória **em quantidade de critérios** ficou quase empatada (14 vs 13). Mas a **assimetria de peso** continua sendo o ponto chave:

- Critérios em que **Temporal vence** = ligados a **confiança em produção** (correção sob mudança de código, durabilidade sob falhas de infra, observabilidade para postmortem, segurança contra silent corruption).
- Critérios em que **RabbitMQ vence** = ligados a **DX local + custo operacional** (latência menor, RAM menor, cold start mais rápido, sem custo Cloud).

---

## 4.5 3ª PoC — AWS Step Functions (executada via LocalStack)

PoC reaberta após decisão preliminar para validar definitivamente. Resultados em [`findings-step-functions.md`](./findings-step-functions.md).

**Resumo de Tier 1-6 contra Step Functions/LocalStack:**

| Critério                     | Resultado                                                                                                                                                             |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| LOC totais (sem bench)       | ~440 (state-machine.json 119 + ActivityWorker + 2 workers + trigger + bootstrap) — entre RabbitMQ (632) e Temporal (237)                                              |
| Throughput burst (T1.3)      | **10.9/s** — pior dos 3 (RabbitMQ 48/s, Temporal 28/s)                                                                                                                |
| Throughput sustentado (T3.3) | 7.5/s — pior dos 3                                                                                                                                                    |
| Latência p99 (T6.2)          | **2092ms** — ~95x pior que RabbitMQ, ~6x pior que Temporal                                                                                                            |
| Versionamento (T1.1)         | **Silent migration em LocalStack** — in-flight saga adotou nova ASL mid-execution. AWS real seria pinning (que tem outro problema: sagas em voo nunca recebem fixes). |
| Falha de infra (T1.4)        | LocalStack perdeu TODO o state. Em AWS real seria multi-AZ durável, mas no nosso teste não validamos.                                                                 |
| Compensação paralela (T2.3)  | ❌ Catch chain inerentemente sequencial; paralelo exige `Type:Parallel` (rewrite ASL).                                                                                |
| Operação                     | ✅✅ **zero-ops em AWS real** — único critério onde Step Functions vence Temporal.                                                                                    |
| Custo financeiro 12 meses    | ~$51k/ano (volume agregado dos 4 sistemas × $0.025/1000 transições) — próximo do Temporal Cloud.                                                                      |
| Lock-in                      | ❌ profundo (AWS-only)                                                                                                                                                |

**Conclusão da 3ª PoC:** Step Functions é viável tecnicamente, atrativo operacionalmente, mas **não vence Temporal em nenhum dos critérios qualitativos críticos** (correção, latência, lock-in). O argumento "zero-ops" é forte, mas não compensa o lock-in profundo + custo de transição (cada step = transição cobrada) + latência maior.

**Limitação importante a registrar:** os testes rodaram em LocalStack (free), não AWS real. Quatro tests (T1.1 versionamento, T1.4 infra failure, T1.5 getVersion equivalent, T6.1 custo) precisam de re-validação em AWS real para fechar a comparação 100%. Mas mesmo assumindo o melhor cenário do AWS real, os critérios em que Step Functions perde (latência, lock-in, custo) são estruturais e não mudariam.

---

## 5. Resposta à pergunta-chave do tech lead — leitura revisada em 2026-04-30

A pergunta de [`consideracoes.md`](./consideracoes.md) §9 era: _"Com que frequência você espera mudar a forma de uma saga vs mudar regras de negócio dentro dos passos?"_

**Resposta do tech lead (2026-04-30, registrada literalmente):**

> "A regra de negócio muda com muita frequência, e devemos deixar o mínimo de responsabilidades ao dev pra manusear um `saga_step` no banco."

### 5.1 Leitura inicial (subsequentemente corrigida)

A leitura inicial classificou a resposta como "reforço para Temporal" e "alternativa RabbitMQ descartada", baseada em duas inferências:

1. "Regra muda muita" → cenário ótimo para Temporal (Activities = PHP comum, sem `getVersion()`).
2. "Mínimo de `saga_step` no banco" → incompatível com a alternativa RabbitMQ + lib que exigia `saga_version` + bump manual.

### 5.2 Leitura revisada após pré-review (2026-04-30 — final do dia)

No pré-review, ficou claro que **a leitura inicial confundiu rejeição de orquestração com rejeição de RabbitMQ**. A posição real do tech lead:

- Ele nunca pediu `saga_step` no banco — a PoC RabbitMQ é que introduziu isso (modelo orquestrado).
- A proposta original dele é **saga coreografada**: lib mínima detecta erro num step, publica evento `saga.failed` em tópico, cada serviço consome esse evento e roda compensação idempotente local. Sem state machine, sem `saga_step`, sem `saga_version`.
- "Mínimo de responsabilidades ao dev" não significa "vamos pra Temporal". Significa **"vamos pra um modelo que não exige tabela central de saga"** — coreografia atende isso com lib pequena (<100 LOC estimado).

**Implicação correta:**

- Temporal continua sendo um candidato viável para casos de orquestração com estado complexo.
- **RabbitMQ-coreografado não foi avaliado** — falta a 4ª PoC.
- A recomendação não pode fechar até que ambos os modelos (orquestrado e coreografado) tenham sido medidos com critérios comparáveis.

A leitura técnica em §5.1 fica registrada como histórico do erro de interpretação. O caminho atual é executar a 4ª PoC e então reformular a recomendação como árvore de decisão (orquestração ⇄ coreografia, não Temporal × RabbitMQ).

---

## 6. Recomendação consolidada

### 6.1 Recomendação principal

**Adotar Temporal como padrão organizacional para SAGA.**

Justificativa primária: **prevenção estrutural de silent corruption** sob mudanças de código (T5.1). A natureza qualitativa dos critérios em que Temporal vence (correção, durabilidade, observabilidade) supera os critérios quantitativos em que RabbitMQ vence (latência, RAM, custo Cloud).

### 6.2 Ressalvas técnicas

- **Custo de adoção real existe:** ~1 semestre de calibração para o time interiorizar a dialética determinística (proibido `date()`, `rand()`, `PDO`, `Http::` em workflow code). Mitigar com pacote interno `mobilestock/laravel-temporal-saga` + lint PHPStan + treinamento + apps/_template_.
- **Cloud só nos primeiros 6-12 meses.** A partir de ~10M actions/mês (qualquer dos 4 sistemas em produção), self-host EKS vira financeiramente obrigatório (T6.1: $58k/ano vs $3-6k/ano + ~15 dias eng).
- **PECL grpc + RoadRunner pesam no setup local.** Aceitar como custo one-time per-dev (~25 min na primeira vez).
- **Race condition na inicialização (workers tentam conectar antes do server pronto):** documentada em [`findings-temporal.md`](./findings-temporal.md) §1 bug 3. Adicionar healthcheck gRPC + `depends_on` no compose oficial.

### 6.3 Alternativa minoritária

Se o tech lead responder à pergunta-chave com "**a forma da saga muda raramente E o time se compromete a manter `saga_version` + lint custom + code review centralizado SEM falhar**", então RabbitMQ + lib `mobilestock/saga` continua viável. Custo: ~17-23 dias eng inicial + manutenção recorrente + risco residual permanente.

Não é recomendação errada per se; é recomendação **mais arriscada** dado o histórico humano de esquecimentos em deploys.

### 6.4 Casos pontuais que não se beneficiam

Se aparecer caso pontual de SAGA que **não justifica adotar plataforma nova** (ex.: 1-2 fluxos isolados em sistema legado sem prazo de migração), permitir SQS + lógica simples + idempotência + alerta manual. **Não tornar isso padrão.**

### 6.5 Próximos passos

1. **Validar a recomendação com tech lead** apresentando este documento + T5.1 reproduzido em vídeo de 2 minutos.
2. **Decidir Cloud vs self-host EKS** para os primeiros 6 meses de adoção (recomendação: começar Cloud para reduzir overhead inicial).
3. **Construir `mobilestock/laravel-temporal-saga`** como pacote interno encapsulando RoadRunner + retry policies padrão + helpers de Saga.
4. **Treinar primeiros devs** com workshop de 1-2 dias + apps/_template_ canônico.
5. **Migrar primeiro caso real** — `ActivateStoreSaga` no `marketplace-api` é candidato natural (já mapeado).
6. **Estabelecer governance:** ADR + lint PHPStan + code review centralizado nas primeiras 4-6 semanas.

---

## 7. Arquivos relacionados

- [`glossario.md`](./glossario.md) — sumário de siglas e termos do estudo.
- [`checklist-testes.md`](./checklist-testes.md) — 20 testes detalhados com cada resultado.
- [`findings-rabbitmq.md`](./findings-rabbitmq.md) — medições da PoC RabbitMQ.
- [`findings-temporal.md`](./findings-temporal.md) — medições simétricas da PoC Temporal.
- [`findings-step-functions.md`](./findings-step-functions.md) — medições simétricas da PoC Step Functions / LocalStack.
- [`consideracoes.md`](./consideracoes.md) — pros e contras detalhados (atualizado pós Tier 1-6).
- [`recomendacao-saga.md`](./recomendacao-saga.md) — documento oficial de decisão.
