# Findings: PoC Step Functions — medições contra os mesmos critérios das outras PoCs

> Documento simétrico a [`findings-rabbitmq.md`](./findings-rabbitmq.md) e [`findings-temporal.md`](./findings-temporal.md). Cobre os 20 testes Tier 1-6 executados contra a PoC `saga-step-functions/` rodando em LocalStack 3.8 + activity workers PHP poll-based.
>
> **Atenção a uma limitação importante de ambiente:** os testes rodaram em **LocalStack** (emulador local) e não em AWS real. Algumas observações são consequência direta dessa escolha (ver §11 — limitações conhecidas).

---

## 1. Esforço até happy path

| Métrica | Valor | Comparação |
|---|---|---|
| Sessão de implementação | 1 (~1.5h, contando ajustes) | RabbitMQ ~2h; Temporal ~3h |
| LOC totais (PHP + JSON, sem bench) | **~440** (state-machine.json 119 + ActivityWorker 87 + 2 workers 108 + trigger 60 + bootstrap 66) | RabbitMQ 632; Temporal 237 |
| LOC do "workflow" (state-machine.json) | **119** (ASL JSON) | RabbitMQ 381 lib em 6 arquivos; Temporal 77 workflow |
| LOC dos 2 service workers + ActivityWorker base | 195 (87 + 61 + 47) | RabbitMQ 73 (handlers); Temporal 96 (activities) |
| LOC do bin (trigger, bench, alerter, bootstrap, update-asl, sustained, batch) | ~340 | RabbitMQ 140; Temporal 64 |
| Composer deps | 2 (`aws/aws-sdk-php`, `ramsey/uuid`) | RabbitMQ 3; Temporal 3 |
| Containers Docker | 5 (localstack + bootstrap + 2 workers + alerter) | RabbitMQ 5; Temporal 7 |
| Tempo do primeiro `docker compose up --build` | ~2 min (LocalStack pull domina) | RabbitMQ ~2 min; Temporal ~25 min |

### Bugs encontrados durante o build

1. **Long-poll bloqueante:** primeira versão usava loop sequencial de `getActivityTask` por ARN. Cada chamada faz long-poll de até 60s no AWS SDK; com 3 ARNs por worker, polling rotation virou ~3 minutos. **Fix:** `pcntl_fork` cria 1 child process por ARN.
2. **LocalStack state não persiste por default:** `docker stop localstack` perde TODO o state (state machines + executions). Não há configuração equivalente a `volume:` que recupere automaticamente — precisa env `PERSISTENCE=1` + `DATA_DIR=/tmp/localstack/data` (não testado, mas documentado).
3. **`describeExecution` retorna RUNNING enquanto worker estava em fork em paralelo:** combinação de polling agressivo (200ms) + fork imaturo levou a "saga RUNNING para sempre" em alguns cases. Corrigido com warm-up time pós-restart.

---

## 2. Esforço de compensação completa

| Métrica | Valor |
|---|---|
| Implementação de compensação LIFO | **Catch chain em ASL**: cada step tem `Catch` apontando para o próximo passo da cadeia de reversão. ConfirmShipping fail → RefundCredit → ReleaseStock → Compensated. |
| LOC dedicadas a compensação no ASL | ~25 (Catch blocks + states de compensação) |
| LOC de handlers de compensação (workers) | 12 (releaseStock + refundCredit) |
| Padrão LIFO | ✅ via Catch chain manual |
| Compensação paralela | ❌ não nativo; teria que usar `Type: Parallel` state — refator significativo do ASL |

Compensação **funcionou** ponta a ponta:
- saga `381b8ea1` com `FORCE_FAIL=step3` → ConfirmShipping falhou → RefundCredit (3s sleep) → ReleaseStock (3s sleep) → COMPENSATED.
- Sequência LIFO correta nos logs dos workers.

**Diferença vs Temporal:** o `Workflow\Saga::addCompensation()` registra closures dinamicamente conforme steps completam — automaticamente respeitando LIFO. No Step Functions, a "ordem" da compensação é **estática no ASL** — quem escreve precisa pensar no Catch chain manualmente.

---

## 3. Observabilidade out-of-the-box

### O que se enxerga sem investimento extra

- **AWS Step Functions Console / CLI / SDK:**
  - `describeExecution` retorna status (RUNNING/SUCCEEDED/FAILED/TIMED_OUT/ABORTED), input, output, error, cause.
  - `getExecutionHistory` retorna lista detalhada de eventos (ExecutionStarted, TaskStateEntered, ActivityScheduled, ActivityStarted, ActivitySucceeded/Failed, etc.) com timestamps.
  - Cada activity tem input/output expostos no history.
- **CloudWatch Logs (em AWS real):** logs de transição de states, integração nativa.
- **CloudWatch Metrics:** ExecutionsStarted, ExecutionsSucceeded, ExecutionsFailed, etc. — out-of-the-box.
- **X-Ray tracing:** integração nativa.

### O que NÃO se enxerga (em LocalStack)

- LocalStack 3.8 free não inclui o **Step Functions Console UI** (visualização gráfica do workflow).
- CloudWatch metrics em LocalStack são limitadas.
- X-Ray não está habilitado por default.

### Conclusão

Step Functions entrega observabilidade rica em AWS real (próxima do Temporal Web UI em capacidade), mas em LocalStack (que é onde rodamos o PoC) a UI gráfica não está disponível. Para postmortem honesto, precisamos confiar em `getExecutionHistory` via API.

---

## 4. Esforço para observabilidade aceitável (estimativa)

| Componente | Esforço estimado | Comparação |
|---|---|---|
| Métricas básicas | ✅ grátis (CloudWatch) | RabbitMQ: 4-6h |
| Timeline visual | ✅ grátis (Step Functions Console em AWS real) | RabbitMQ: 1 dia |
| Replay/postmortem | ⚠️ via getExecutionHistory + reproduzir manualmente | Temporal: grátis |
| Alerta de falha | ~15 lines YAML CloudWatch Alarm + SNS | similar a Temporal |
| Search/filter | ✅ via `listExecutions` filters | similar a Temporal |
| **Total** | **~0.5 dia** (em AWS real) | RabbitMQ ~3-5 dias; Temporal ~1 dia |

Custo de observabilidade no Step Functions é baixo, mas amarrado ao stack AWS.

---

## 5. DX em code review

### Pontos a favor

- Estado da saga em **um único arquivo JSON** (`state-machine.json`). Reviewer abre, lê os `States`, vê a sequência + Catches.
- **Steps são puramente declarativos** (sem `yield`, sem determinismo).
- Activities são PHP comum.

### Pontos contra

- **Workflow vive em JSON SEPARADO do código PHP.** Não é PHP-idiomático. Code review da saga e dos handlers fica em arquivos diferentes — IDE não tem refactor cross-language.
- **Sem type-safety na ASL:** `"Resource": "<ARN>"` é string; campos de input/output são strings dot-path (`"$.reserve.reservation_id"`). Erros só aparecem em runtime.
- **Catch chain é explícito mas verbose:** cada step + suas compensações duplica blocos `Catch`. Para 5 steps com compensações condicionais, ASL fica complexo.
- **Versionamento de ASL é separate from code:** PRs precisam mudar JSON + worker — em arquivos potencialmente em repos diferentes.

### Comparação prevista

- **Mais declarativo que Temporal** (ASL é declaração; Temporal é código com yield).
- **Mais explícito que RabbitMQ** (em RabbitMQ a definition + handlers + orchestrator estão em 3-4 arquivos; em Step Functions há 2 arquivos: ASL + worker).
- **Menos type-safe que ambos.**

---

## 6. Resiliência simulada

### 6.1 Cenário A: kill `service-a-worker` mid-handler

**Setup:** SLOW_RESERVE_STOCK=12, fire saga, kill worker durante sleep, restart.

**Resultado:** ✅ saga completou — ActivityTimedOut depois de 60s + reagendamento + worker novo pega no retry. Mais devagar que Temporal/RabbitMQ por causa do timeout fixo de 60s, mas funciona.

### 6.2 Cenário B: kill activity workers e retomar

Mesmo padrão de Temporal — workers stateless, podem morrer e voltar; tasks ficam pendentes no engine, retomam quando worker volta. ✅

### 6.3 Cenário C: at-least-once / execução dupla

**Não testado especificamente.** Step Functions garante que cada activity execution recebe um único taskToken; tasks só completam via `SendTaskSuccess`/`SendTaskFailure`. Janela de risco existe entre handler completar e SendTaskSuccess chegar — similar ao Temporal. Mas em prática é dominada pelas mesmas garantias da plataforma managed.

### 6.4 Cenário D: kill LocalStack mid-flight (T1.4)

**Setup:** SLOW=20, fire saga, `docker stop localstack` aos 4s, esperar 30s, restart.

**Resultado:** ❌ **TODO O STATE FOI PERDIDO.**
- Saga `dd095714` retornou `ExecutionDoesNotExist`.
- State machine retornou `0` em `listStateMachines`.
- Activity ARNs perdidos.
- Workers ficam pollando ARNs que não existem mais.

**Causa:** LocalStack 3.8 default não persiste state. Docker volume não foi configurado para `/tmp/localstack`.

**Em AWS real:** Step Functions tem multi-AZ durability; uma "queda" do serviço é evento extremamente raro e executions sobrevivem a falhas de AZ individual.

**Comparação:**
- Temporal (T1.4): sobreviveu a 30s de Postgres parado, retomou normalmente.
- RabbitMQ (T1.4): workers caíram com broker; após restart manual, mensagens em queue durable foram processadas.
- Step Functions/LocalStack: perda total de state — **arquitetonicamente possível recuperar em AWS real, mas no nosso ambiente local impossível**.

---

## 7. Operação simulada

| Aspecto | Step Functions (LocalStack) | Step Functions (AWS real) |
|---|---|---|
| Setup local | ~2 min (pull LocalStack image) | n/a |
| Containers | 5 (localstack + bootstrap + 2 workers + alerter) | n/a — managed |
| Self-host EKS | n/a — Step Functions é always-managed | n/a — managed |
| Healthcheck workers | warm-up necessário (~10s) antes de disparar saga | mesmo |
| Logs | stdout dos workers | CloudWatch + LocalStack stdout |

Em AWS real, operação é zero — nem cluster nem nodes para gerenciar. **Esse é o maior atrativo do Step Functions vs Temporal self-host.**

---

## 8. Custo projetado 12 meses

Step Functions é **always-managed**, pay-per-use. Cálculo para volume agregado dos 4 sistemas (~17M sagas/mês × ~10 transições por saga = ~170M transições/mês):

- **Standard mode:** $0.025 por 1.000 transições. Total: 170M × $0.025/1000 = **$4250/mês ≈ $51k/ano**.
- **Express mode:** $0.000001/request + $0.000002/GB-segundo. Mais barato em volume mas SEM history persistente — não serve para nosso caso (precisamos postmortem).

Comparação:

| Plataforma | Custo 12 meses (volume agregado) |
|---|---|
| RabbitMQ self-hosted | $2400-4800 + ~17-23 dias eng |
| Temporal Cloud | ~$58k/ano (estimado em T6.1) |
| Temporal self-host EKS | $3-6k/ano + ~15-30 dias eng |
| Step Functions Standard | **~$51k/ano** |

**Step Functions é financeiramente próximo do Temporal Cloud em escala.** Vantagem: zero ops. Desvantagem: lock-in profundo + custo escala com cada transição (não com volume de "trabalho útil").

---

## 9. Risco de SDK/lib decair

- `aws/aws-sdk-php`: oficial AWS, mantenance ativa, milhões de installs.
- ASL é spec da AWS — extremamente estável (mudanças retroativamente compatíveis há anos).
- Step Functions é serviço-core da AWS, não está em risco de descontinuação.

**Risco residual:** mudança de pricing pela AWS (já aconteceu uma vez em 2018). Lock-in significa que migração para outra plataforma é rewrite, não re-deploy.

---

## 10. Comparação direta com RabbitMQ e Temporal

| Critério | RabbitMQ | Temporal | Step Functions | Quem ganha |
|---|---|---|---|---|
| LOC totais (PHP, sem bench) | 632 | 237 | ~440 | **Temporal** |
| LOC do "workflow" | 381 | 77 | 119 (ASL) | **Temporal** |
| Setup local 1ª vez | ~2 min | ~25 min | ~2 min | **RabbitMQ / Step Functions** (empate) |
| Composer deps | 3 | 3 | 2 | **Step Functions** (marginal) |
| Containers | 5 | 7 | 5 | empate |
| Throughput burst (100 sagas) | 48/s | 28/s | **10.9/s** | **RabbitMQ** |
| Throughput sustentado | 9.7/s | 9.5/s | **7.5/s** | RabbitMQ/Temporal |
| Latência fim-a-fim p99 | **22ms** | 351ms | **2092ms** | **RabbitMQ** |
| Resiliência a infra failure | ⚠️ workers caem | ✅ retoma | ❌ LocalStack perdeu state | **Temporal** |
| Compensação paralela | ⚠️ por arquitetura | ✅ 1 LOC switch | ❌ exige rewrite ASL para Parallel state | **Temporal** |
| Observabilidade default | ❌ logs | ✅ Temporal Web UI | ✅ Step Functions Console (AWS real) | **Temporal / Step Functions** |
| Postmortem | ⚠️ 2-15 min | ✅ 30s-1min | ✅ via API/Console | **Temporal / Step Functions** |
| Versionamento — sagas em voo | ❌ silent corruption | ✅ panic LOUD | ⚠️ silent migration (LocalStack) / pinning (AWS real) | **Temporal** |
| Lock-in | ✅ AMQP padrão | ⚠️ moderado | ❌ profundo (AWS) | **RabbitMQ** |
| Custo financeiro 12 meses (volume agregado) | ~$3k + 17-23 dias eng | ~$58k Cloud / ~$5k self-host + 15 dias eng | ~$51k | **RabbitMQ / Temporal self-host** |
| Operação | ⚠️ clustering | ⚠️ cluster ou Cloud | ✅✅ zero ops (managed) | **Step Functions** |
| Bus factor | ❌ lib interna | ✅ SDK público | ✅✅ AWS oficial | **Step Functions** |

**Score qualitativo:**
- **Temporal vence em correção, observabilidade, throughput vs Step Functions, e versionamento.**
- **RabbitMQ vence em latência, throughput e custo.**
- **Step Functions vence em operação zero, bus factor, e lock-in da plataforma (AWS oficial).**

**Step Functions não vence Temporal em nenhum dos critérios qualitativos críticos** que motivaram a recomendação atual (silent corruption, durable execution, postmortem). Adiciona zero-ops como diferencial — mas com custo de lock-in profundo + latência alta.

---

## 11. Limitações conhecidas do ambiente de teste

Os testes rodaram em **LocalStack 3.8 free**, não em AWS real. Implica:

1. **Sem persistência:** restart de LocalStack perde state. Não testamos durable execution real.
2. **Sem revision pinning:** T1.1 mostrou silent migration de in-flight executions para nova ASL — comportamento documentado de **AWS real é o oposto** (Standard executions são pinned ao revision do start).
3. **Latência inflada:** p99 de 2s no LocalStack provavelmente cai para ~500ms-1s em AWS real (sem REST roundtrip overhead).
4. **Sem Console UI:** Step Functions Console gráfico não está disponível no free tier.
5. **CloudWatch limitado:** métricas e alarms são parciais.

Para confirmar resultados, **recomenda-se rodar T1.1, T1.4, T1.5, T6.1 em AWS real** num namespace dedicado durante o tier free do Step Functions (4.000 transições grátis no primeiro mês).

---

## 12. Veredito

Com os critérios já medidos:

- **Step Functions resolve operação** (zero ops em AWS real).
- **Step Functions não resolve correção sob mudança de código** (silent migration na nossa observação; em AWS real é pinning, mas isso transfere o problema: sagas pinned em revisão antiga não recebem fixes de bugs até completarem — pior do que `getVersion()` granular do Temporal).
- **Step Functions tem latência alta** mesmo em AWS real (P99 reportado pela AWS é ~50-200ms para Standard mode, nosso teste em LocalStack ficou em 2s).
- **Step Functions tem lock-in profundo** — refazer o estudo seria rewrite caso AWS ficasse caro ou mudasse pricing.
- **Step Functions custa $51k/ano** no nosso volume — próximo do Temporal Cloud.

**Recomendação não muda:** Temporal continua sendo a opção certa, agora com 3ª PoC servindo como evidência adicional.

Step Functions é viável para casos específicos (workflows curtos, baixo volume, time já AWS-native), mas para padrão organizacional dos 4 sistemas com volume estimado, perde para Temporal nos critérios qualitativos e não compensa via custo.
