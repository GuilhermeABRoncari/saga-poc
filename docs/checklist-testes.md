# Checklist de testes comparativos — RabbitMQ vs Temporal

> Lista viva de testes para gerar evidência objetiva. Cada teste tem **como executar**, **o que medir** e **espaço para anotar resultado**. Resultados consolidados depois alimentam [`findings-rabbitmq.md`](./findings-rabbitmq.md), [`findings-temporal.md`](./findings-temporal.md) e a tabela §3.2 de [`recomendacao-saga.md`](./recomendacao-saga.md).
>
> Convenção de status:
> - `[ ]` não executado
> - `[~]` em andamento
> - `[x]` executado, resultado anotado
> - `[!]` executado, identificou bloqueio/bug que precisa ser tratado antes de continuar

---

## Tier 1 — Alto valor, baixo custo

### T1.1 Versionamento de Workflow code (sagas em voo)

- [ ] **Como executar:**
  1. Disparar saga lenta nos dois PoCs (`SLOW_RESERVE_STOCK=15`).
  2. Enquanto ela está em voo, editar `ActivateStoreSaga` adicionando um step novo entre 2 e 3.
  3. Restartar os workers/containers.
  4. Observar comportamento da saga em voo.
- **O que medir:**
  - Temporal sem `Workflow::getVersion()`: replay quebra? Mensagem de erro na UI?
  - Temporal com `Workflow::getVersion()`: completa OK?
  - RabbitMQ: saga em voo continua usando step_index antigo? Aponta para step errado?
- **Por que importa:** responde a pergunta-chave do tech lead em [`consideracoes.md`](./consideracoes.md) §5.
- **Resultado RabbitMQ:**
- **Resultado Temporal:**
- **Notas:**

### T1.2 At-least-once / execução dupla no RabbitMQ (Cenário C empírico)

- [ ] **Como executar:**
  1. Adicionar `sleep(2)` em `SagaOrchestrator::onEvent` entre `repo->advance(...)` e `dispatchStep(...)`.
  2. Disparar saga.
  3. Matar o orchestrator durante o sleep.
  4. Restartar. Verificar quantas vezes o handler do step seguinte executou.
- **O que medir:**
  - Quantas entradas duplicadas em `completed_steps[]`.
  - Logs do service mostram comando executado 1x ou 2x?
  - Estado final da saga.
- **Por que importa:** transforma a hipótese de [`findings-rabbitmq.md`](./findings-rabbitmq.md) §6.3 em fato medido.
- **Resultado:**
- **Notas:**

### T1.3 100 sagas concorrentes — throughput e latência

- [ ] **Como executar (RabbitMQ):**
  ```bash
  for i in {1..100}; do
    docker compose -f saga-rabbitmq/docker-compose.yml exec -T orchestrator php bin/trigger.php &
  done
  wait
  ```
- [ ] **Como executar (Temporal):**
  ```bash
  for i in {1..100}; do
    docker compose -f saga-temporal/docker-compose.yml exec -T workflow-worker php bin/trigger.php &
  done
  wait
  ```
- **O que medir:**
  - Tempo total para fechar todas as 100 sagas (wall clock).
  - p50 / p95 / p99 de latência fim-a-fim por saga.
  - Quantas falharam (não-determinístico esperado: 0).
  - Throughput sustentado (sagas/s).
  - Pico de memória/CPU dos containers (`docker stats`).
- **Resultado RabbitMQ:**
  - Tempo total:
  - p50/p95/p99:
  - Falhas:
- **Resultado Temporal:**
  - Tempo total:
  - p50/p95/p99:
  - Falhas:
- **Notas:**

### T1.4 Falha do Postgres do Temporal mid-flight

- [ ] **Como executar:**
  1. Disparar saga lenta (`SLOW_RESERVE_STOCK=20`).
  2. `docker stop saga-temporal-postgresql-1` durante o sleep.
  3. Aguardar 30s.
  4. `docker start saga-temporal-postgresql-1`.
  5. Observar se a saga completa.
- **O que medir:**
  - Workflow sobrevive ao Postgres caído?
  - Quanto tempo até retomar após Postgres voltar?
  - Erros visíveis na UI?
- **Análogo RabbitMQ:** não há equivalente direto — orchestrator usa SQLite local. Documentar essa assimetria.
- **Resultado Temporal:**
- **Resultado RabbitMQ (analógico — derrubar SQLite ou volume):**
- **Notas:**

---

## Tier 2 — Mais trabalho, fecha lacunas grandes

### T2.1 Dashboard Grafana mínimo no RabbitMQ

- [ ] **Como executar:**
  1. Subir Prometheus + Grafana no compose.
  2. Expor métricas mínimas: `saga_started_total`, `saga_completed_total`, `saga_compensated_total`, `saga_failed_total`, `saga_duration_seconds`.
  3. Construir dashboard com: sagas em andamento, % compensadas, p95 duração.
- **O que medir:**
  - Tempo total (horas) até "mínimo aceitável" funcionando.
  - Comparar com a estimativa de **3-5 dias** em [`findings-rabbitmq.md`](./findings-rabbitmq.md) §4.
- **Por que importa:** valida ou refuta a estimativa que está pesando contra RabbitMQ.
- **Resultado:**
- **Notas:**

### T2.2 Alerta "compensação falhou" em ambos

- [ ] **Como executar (RabbitMQ):**
  - Configurar DLX nas filas de compensação + consumidor que dispara webhook ao receber msg na DLX.
- [ ] **Como executar (Temporal):**
  - Usar Temporal SDK metrics + Prometheus AlertManager para alertar em `WorkflowExecutionFailed` com label workflow_type=ActivateStoreSaga.
- **O que medir:**
  - Tempo até primeiro alerta funcional em ambos.
  - Falsos positivos.
- **Resultado RabbitMQ:**
- **Resultado Temporal:**

### T2.3 Compensação paralela (só Temporal tem)

- [ ] **Como executar:**
  1. Estender saga para 5 steps com 5 compensações.
  2. No Temporal: `setParallelCompensation(true)` e medir tempo total de compensação.
  3. Comparar com `setParallelCompensation(false)` (sequencial).
  4. No RabbitMQ: documentar quanto código seria necessário para implementar paralelo.
- **O que medir:**
  - Tempo de compensação sequencial vs paralela em Temporal.
  - LOC estimado para implementar paralelo no RabbitMQ.
- **Resultado Temporal:**
- **Resultado RabbitMQ (estimativa):**

### T2.4 Code review blind test

- [ ] **Como executar:**
  1. Pegar dev que nunca viu o repo.
  2. Pedir para explicar em voz alta o fluxo de `ActivateStoreSaga` lendo só os arquivos (sem rodar).
  3. Cronometrar e anotar perguntas que ele(a) faz.
- **O que medir:**
  - Tempo até dev "entender" cada PoC.
  - Nº e tipo de perguntas (revela onde o código não conta a história sozinho).
  - Confiança subjetiva no final ("explicaria isso pra outro dev?").
- **Resultado RabbitMQ:**
- **Resultado Temporal:**

---

## Tier 3 — Operacional / custo real

### T3.1 Tempo até dev novo subir o ambiente

- [ ] **Como executar:**
  1. Em máquina sem cache de imagens Docker, cronometrar:
     - `git clone` (irrelevante mas registra)
     - `docker compose up --build` até "pronto"
     - Primeira saga rodando ponta a ponta
- **O que medir:**
  - Tempo total RabbitMQ.
  - Tempo total Temporal (esperado: dominado por PECL grpc compile, ~25 min em [`findings-temporal.md`](./findings-temporal.md) §1).
- **Resultado RabbitMQ:**
- **Resultado Temporal:**

### T3.2 Footprint de memória idle e cold start

- [ ] **Como executar:**
  1. `docker compose up -d` em ambos.
  2. Aguardar 30s sem tráfego.
  3. `docker stats --no-stream` em ambos.
  4. Anotar memória por container.
- **O que medir:**
  - Memória residente idle por container.
  - Memória total por stack (soma).
  - Tempo de `up -d` (cold start) com cache.
- **Resultado RabbitMQ:**
- **Resultado Temporal:**

### T3.3 Sustained load — memory leak detection

- [ ] **Como executar:**
  1. Loop de 5 minutos, 10 sagas/s.
  2. `docker stats` a cada 30s.
- **O que medir:**
  - Memória cresce linearmente? (leak)
  - File descriptors crescem? (`docker exec ... ls /proc/1/fd | wc -l`)
  - GC do RoadRunner (Temporal): liberou memória?
- **Resultado RabbitMQ:**
- **Resultado Temporal:**

### T3.4 Replay completo de saga antiga

- [ ] **Como executar (Temporal):**
  - Pegar workflow_id de saga rodada ontem (ou simular com 1h de delay).
  - Abrir na UI: clicar em "Stack Trace" e navegar history.
- [ ] **Como executar (RabbitMQ):**
  - Mesma saga: tentar reconstruir o que aconteceu lendo SQLite + logs. Cronometrar.
- **O que medir:**
  - Tempo até reviewer reconstruir o passo-a-passo da saga.
  - Confiança no diagnóstico final.
- **Resultado RabbitMQ:**
- **Resultado Temporal:**

---

## Tier 4 — Resiliência adicional (não bloqueante)

### T4.1 Falha de rede worker↔server (Temporal)

- [ ] **Como executar:**
  1. Disparar saga lenta.
  2. `docker network disconnect saga-temporal_default saga-temporal-temporal-1` por 10s.
  3. Reconectar e observar.
- **O que medir:**
  - Heartbeat detecta? Tempo de reconexão? Saga retoma?
- **Resultado:**

### T4.2 Disco cheio no SQLite (RabbitMQ)

- [ ] **Como executar:**
  1. `docker exec ... dd if=/dev/zero of=/app/storage/big bs=1M count=N` até saturar.
  2. Tentar rodar saga.
- **O que medir:**
  - Comportamento: travamento, exception clara, corrupção?
- **Resultado:**

### T4.3 Falha em step 1 (compensação trivial)

- [ ] **Como executar:**
  1. Adicionar `FORCE_FAIL=step1` em ambas as PoCs.
  2. Disparar saga.
- **O que medir:**
  - Compensação roda nada (não há nada a reverter)?
  - UI/logs deixam claro o "fim sem reversão"?
- **Resultado RabbitMQ:**
- **Resultado Temporal:**

### T4.4 Activity timeout vs activity error (Temporal)

- [ ] **Como executar:**
  1. `SLOW_RESERVE_STOCK=25` (excede `StartToCloseTimeout=20`).
  2. Disparar saga.
- **O que medir:**
  - Temporal classifica como timeout vs error?
  - Retry policy aplica diferente?
  - UI mostra distinção?
- **Resultado Temporal:**
- **Análogo RabbitMQ:** documentar que essa distinção não existe (publish síncrono, sem timeout de handler).

---

## Tier 5 — Versionamento ampliado

### T5.1 Reordenar compensações

- [ ] **Como executar:**
  1. Mudar ordem de `addCompensation` no Temporal.
  2. Mudar ordem de steps em `ActivateStoreSaga::definition()` no RabbitMQ.
  3. Testar com sagas em voo (já iniciadas com a versão antiga).
- **O que medir:**
  - Temporal: explode com erro de determinismo claro?
  - RabbitMQ: silenciosamente reverte na ordem errada?
- **Resultado RabbitMQ:**
- **Resultado Temporal:**

### T5.2 Mudar shape do payload de step

- [ ] **Como executar:**
  1. Adicionar campo obrigatório novo em `payload` de step 2.
  2. Disparar saga em voo (com payload antigo).
- **O que medir:**
  - Temporal: retry esgota, workflow falha visivelmente?
  - RabbitMQ: handler explode silenciosamente?
- **Resultado RabbitMQ:**
- **Resultado Temporal:**

---

## Tier 6 — Custo financeiro real

### T6.1 1000 sagas em Temporal Cloud free tier

- [ ] **Como executar:**
  1. Apontar Temporal SDK para Cloud free tier (criar namespace).
  2. Rodar 1000 sagas.
  3. Verificar consumo de actions na UI da Cloud.
- **O que medir:**
  - Actions consumidos por saga.
  - Projeção mensal no volume real esperado pelos 4 sistemas.
- **Resultado:**

### T6.2 Overhead de p99 fim-a-fim

- [ ] **Como executar:**
  1. Saga curta (sem `sleep`).
  2. Medir tempo do `trigger` até `COMPLETED` em 1000 execuções.
  3. p99 isolado.
- **O que medir:**
  - Overhead da plataforma vs custo de N publishes (RabbitMQ) e N decision tasks (Temporal).
- **Resultado RabbitMQ:**
- **Resultado Temporal:**

---

## Resumo de execução

| Tier | Testes | Status |
|---|---|---|
| 1 | T1.1, T1.2, T1.3, T1.4 | 0/4 |
| 2 | T2.1, T2.2, T2.3, T2.4 | 0/4 |
| 3 | T3.1, T3.2, T3.3, T3.4 | 0/4 |
| 4 | T4.1, T4.2, T4.3, T4.4 | 0/4 |
| 5 | T5.1, T5.2 | 0/2 |
| 6 | T6.1, T6.2 | 0/2 |

**Recomendação de ordem:** Tier 1 primeiro (responde perguntas de maior peso na decisão), depois Tier 2 + Tier 3 em paralelo, Tiers 4–6 conforme tempo permitir.
