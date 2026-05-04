# Findings: PoC RabbitMQ — medições para [`recomendacao-saga.md`](./recomendacao-saga.md) §3.2

> Documento vivo. Atualizado conforme cada critério é medido. Quando todos os critérios estiverem preenchidos aqui (e o equivalente para Temporal), é hora de fechar a recomendação.
>
> ## Atualização 2026-05-04 — RabbitMQ 4.3
>
> A PoC foi originalmente medida em **RabbitMQ 3.13** (Mnesia metadata store). Em 2026-05-04 todas as medições afetadas foram refeitas em **RabbitMQ 4.3** (Khepri/Raft metadata store, Mnesia removido). Imagem: `rabbitmq:4.3-management-alpine`. Resumo dos deltas relevantes:
>
> - **Memória idle do broker:** 141 MB → **108 MiB** (−23%, ganho do Khepri vs Mnesia em single-node).
> - **Throughput batch (T1.3, 100 sagas concorrentes):** 2076 ms (48 sagas/s) → **705 ms (~142 sagas/s)** — quase **3× mais rápido**.
> - **Sustained load (T3.3, 5 min × 10 sagas/s):** 2909 → **2959 sagas/300s** (+1.7%); broker volta ao baseline pós-load (mesma assinatura "transport ephemeral" de 3.13).
> - **T1.4 broker caído:** comportamento da lib **não muda** — workers `php-amqplib` continuam crashando com `AMQPConnectionClosedException` e não auto-reconectam. Khepri afeta apenas o lado server. Gap arquitetural permanece idêntico.
> - **LOC:** sem alteração (520 LOC totais entre lib + handlers, igual a 3.13).
>
> **Implicação para HA em produção:** RabbitMQ 4.0+ removeu **classic mirrored queues**. Para HA daqui pra frente, é **quorum queues obrigatório** — semântica diferente de ack, redelivery e priority. Isso muda parte da estimativa de adoção e está registrado em §1.2.x novo.
>
> ## Escopo (atualização 2026-04-30)
>
> Este documento mede **RabbitMQ no modelo orquestrado** — orquestrador central + state machine no banco (`saga_states`/`saga_steps`) + uma biblioteca interna de saga controlando ordem de steps e disparando compensação LIFO.
>
> Em iteração posterior, ficou identificado que faltava medir **RabbitMQ coreografado** — sem orquestrador, sem state machine, lib mínima detectando erro num step e publicando evento `saga.failed` consumido por handlers idempotentes em cada serviço. Esse modelo **não foi medido aqui** e está coberto em uma 4ª PoC dedicada (`saga-rabbitmq-coreografado/`).
>
> **Como ler este documento:** todos os números, comparações com Temporal, e em especial o achado T5.1 (silent corruption sob reordenamento) referem-se ao modelo orquestrado. Não generalizar para "RabbitMQ é ruim para saga" — referem-se a "RabbitMQ-orquestrado tem essas características vs Temporal-orquestrado".

PoC vivo: [`../saga-rabbitmq/`](../saga-rabbitmq/).

---

## 1. Esforço até happy path

| Métrica                             | Valor                                       |
| ----------------------------------- | ------------------------------------------- |
| Sessão de implementação             | 1 (~2h, contando bugs)                      |
| LOC totais                          | 632 (PHP em `src/` + `bin/`)                |
| LOC da lib interna de saga          | 381 (6 arquivos)                            |
| LOC da saga + handlers de aplicação | 111 (6 arquivos)                            |
| LOC de bootstrap (`bin/`)           | 140                                         |
| Composer deps                       | 3 (`php-amqplib`, `ramsey/uuid`, `monolog`) |
| Containers Docker                   | 4 (rabbitmq + orchestrator + 2 services)    |

**Bugs encontrados durante o build (custos reais):**

1. `pcntl_fork()` herdando socket AMQP do pai → "Invalid frame type 0". Fix: abrir conexão depois do fork.
2. Resultado de step 0 não chegava ao step 2. Fix: orchestrator acumula `completed_steps[].result` antes de despachar próximo passo.
3. `FORCE_FAIL` injetado só no orchestrator, mas o handler que falha roda no service. Fix: env precisa estar no container do serviço.
4. Composer install no Alpine quebra sem `linux-headers` (sockets) e `sqlite-dev` (pdo_sqlite).
5. Gitignore `storage/*.sqlite` é ancorado à raiz; arquivos em subdiretórios escaparam. Fix: `**/storage/*.sqlite`.

**Observação:** os bugs 1 e 2 são _bugs de ergonomia da lib_ — precisariam ficar resolvidos no pacote interno antes de ser oferecido a outros times.

---

## 2. Esforço de compensação completa

| Métrica                                                      | Valor                                                                     |
| ------------------------------------------------------------ | ------------------------------------------------------------------------- |
| LOC dedicadas a compensação na lib                           | ~25 (`SagaOrchestrator::compensate` + handler de comandos de compensação) |
| LOC de handlers de compensação                               | ~30 (release_stock, refund_credit)                                        |
| Tempo adicional para implementar compensação após happy path | irrelevante — feito no mesmo ciclo                                        |
| Padrão LIFO automático                                       | implementado na lib                                                       |

Compensação **funcionou** ponta a ponta com `FORCE_FAIL=step3`: orchestrator detectou falha, disparou `refund_credit` (step 1) → `release_stock` (step 0) com IDs propagados corretamente.

---

## 3. Observabilidade out-of-the-box

### O que se enxerga sem nenhum investimento extra

- **RabbitMQ Management UI** (`http://localhost:15673`): filas, mensagens em ready/unacked, throughput por fila, conexões ativas, channels.
- **Logs por linha** dos containers (orchestrator + services): cada step e compensação imprime uma linha em stdout.
- **Estado da saga em SQLite**: `saga_id`, status (`RUNNING`/`COMPLETED`/`COMPENSATING`/`COMPENSATED`), `current_step`, payload, `completed_steps`. Inspeção via `sqlite3 storage/saga.sqlite "SELECT * FROM sagas;"`.

### O que NÃO se enxerga sem construir

- **Timeline de uma saga específica**: precisaria correlacionar logs de 3 containers diferentes pelo `saga_id` manualmente.
- **Mapa visual do workflow**: sem.
- **Replay de execução passada**: sem (logs efêmeros, estado SQLite só guarda último snapshot).
- **Histórico de retries**: sem (`basic.nack` requeue é silencioso).
- **Métricas agregadas** ("X sagas/min", "Y% compensam"): não existe; precisaria construir via Prometheus exporters.
- **Alerta "compensação falhou"**: sem; precisaria construir com DLX + alerting customizado.
- **Search por saga ID** numa UI: sem.

---

## 4. Esforço para observabilidade aceitável (estimativa)

Para chegar a "mínimo aceitável" (timeline básica + alerta de compensação falha + dashboard de sagas em andamento), a estimativa é:

| Componente                                                                             | Esforço estimado             |
| -------------------------------------------------------------------------------------- | ---------------------------- |
| Logs estruturados (JSON) com `saga_id` em todos os containers                          | 2-3 horas                    |
| Stack de logs (Loki ou ELK), pelo menos local                                          | 1 dia (1ª vez), depois reuso |
| Métricas Prometheus expostas pela lib (counters de saga.started/completed/compensated) | 4-6 horas                    |
| Dashboard Grafana padrão (sagas/min, % compensadas, p95 duração)                       | 1 dia                        |
| DLX nas filas de comando + alerta CRÍTICO em "DLX recebeu mensagem"                    | 4-6 horas                    |
| Tabela `saga_events` com history append-only (vs só snapshot) para replay grosso       | 1-2 dias                     |
| **Total estimado**                                                                     | **3-5 dias engenheiro**      |

Esse esforço é "uma vez" — depois replicado entre projetos. Mas precisa ser mantido.

---

## 5. DX em code review

### Pontos a favor

- A definição da saga é declarativa e curta (`ActivateStoreSaga::definition()` cabe em 20 linhas).
- Cada handler é uma classe com `__invoke(array $payload): array` — contrato familiar para qualquer dev Laravel.
- Sem `yield`, sem RoadRunner, sem determinismo — código é PHP comum.

### Pontos contra

- O fluxo da saga **não está em um lugar só**: definição em `ActivateStoreSaga`, lógica de execução em `SagaOrchestrator`, handlers espalhados em pastas `ServiceA/` e `ServiceB/`. Para entender "o que acontece quando step X falha?" o reviewer precisa pular entre 3-4 arquivos.
- A propagação de payload entre steps (`completed_steps[].result` mergeado em `payload`) é convenção implícita — não há tipo. Bug 2 (acima) demonstra: foi preciso testar pra descobrir que o resultado do step 0 não chegava ao step 2.
- `compensação` é "string == nome de comando": nada na assinatura do handler indica que ele é compensação vs operação direta. Erros de typo só aparecem em runtime.

### Comparação prevista com Temporal

Workflow do Temporal é **um arquivo, código sequencial com `yield`**. Reviewer lê o fluxo de cima a baixo, vê cada `addCompensation()` na linha onde foi adicionado. Mais legível em troca da curva de aprendizado de yield+determinismo.

---

## 6. Resiliência simulada

Cenários executados em ambiente local com Docker Compose. Cada cenário foi **executado e validado**.

### 6.1 Cenário A: kill `service-a` mid-handler

**Setup:** `SLOW_RESERVE_STOCK=10` (handler do step 0 dorme 10s). Trigger saga, mata `service-a` enquanto está no sleep, restart sem delay.

**Resultado:** saga completa automaticamente.

**Por quê:** mensagem em `saga.commands.service-a` ficou unacked quando o container morreu → RabbitMQ marcou `redelivered=true` → quando container voltou, processou e ackou normalmente. Saga prossegue.

### 6.2 Cenário B: kill `orchestrator` com evento em voo

**Setup:** mesmo delay de 10s. Trigger saga, mata orchestrator enquanto step 0 está sendo processado (sleep). Espera 12s (step 0 termina e publica `step.completed` em `saga.events` para fila vazia de consumer). Restart orchestrator.

**Resultado:** saga completa automaticamente.

**Por quê:** durante a janela em que orchestrator estava morto, eventos de `step.completed` se acumularam em `saga.events` (queue durable). Quando orchestrator voltou, consumiu o backlog → despachou próximos comandos → saga finalizou.

### 6.3 Cenário C: at-least-once + execução dupla (gap identificado, não testado)

**Não testado** (requer instrumentação do orchestrator com sleep entre operações), mas o gap é real e visível no código:

```
SagaOrchestrator::onEvent() {
    repo->advance(...)            // (1) DB write — saga avança step
    dispatchStep(...)             // (2) publish próximo comando em fila
}
// Após retornar, AmqpTransport.consume() faz msg.ack()  (3)
```

Se o orchestrator morrer entre (1) e (2), ou entre (2) e (3):

- A mensagem em `saga.events` é requeued porque não foi ackada.
- No restart, `onEvent` roda de novo:
  - `repo->advance` empurra **mais uma entrada** em `completed_steps[]` (duplicada).
  - `dispatchStep` publica o **mesmo comando** de novo.
- Service handler executa **duas vezes** se não for idempotente. Em produção: pagamento dobrado, estoque reservado duas vezes, OAuth client criado em duplicidade.

**Mitigações conhecidas:**

- Tornar todo handler idempotente por construção (checkar antes de agir).
- Mudar o orchestrator para "transactional outbox": escrita do estado e publicação numa só transação local, com worker separado fazendo o fan-out.
- Mudar o ack para acontecer ANTES da publicação do próximo comando (mas isso troca o problema: agora se o publish falhar, perdemos o avanço da saga).

**Importante para a comparação:** essa classe de bug **não existe** em Temporal. O event sourcing + workflow determinístico do Temporal garante que cada Activity executa exatamente uma vez (com retries idempotentes gerenciados pelo engine). Em RabbitMQ + lib interna, **toda saga em produção precisa ser construída assumindo at-least-once** — idempotência vira responsabilidade do dev, não default da plataforma.

### 6.4 Cenário D: saga "órfã" no banco

**Setup hipotético:** orchestrator morre permanentemente (deploy quebrado, bug que crasha no boot). Sagas em estado `RUNNING` ficam paradas indefinidamente.

**Resultado esperado** (não testado, mas óbvio do código): há **zero mecanismo de resume** na lib atual. O orchestrator não consulta `sagas WHERE status='RUNNING'` no boot. Mensagens podem estar em filas (recuperáveis) ou já terem sido processadas/ackadas (perdidas).

**Esforço para fechar o gap:** método `resumeStuckSagas()` rodando no boot do orchestrator — varre SQLite/DB por sagas RUNNING há mais de N minutos sem progresso, decide se republicar último comando ou compensar. **Estimativa: 1-2 dias** + testes.

Em Temporal isso é problema do engine; o cliente não precisa pensar.

---

## 7. Operação simulada

Não medido formalmente. Observações qualitativas do dev local:

- RabbitMQ container sobe em ~5s (após pull). Healthcheck pronto em 10-15s.
- Management UI funciona out-of-the-box; navegar é intuitivo.
- Reset completo (`docker compose down -v`) é trivial.
- Não há "bugs operacionais" do RabbitMQ em si — é maduro, comportamento previsível.
- Toda a complexidade operacional vai para o **time de plataforma futura**: clustering em produção, quorum queues, monitoring, DLX, alerting.

Em produção, operação do RabbitMQ é trabalho dedicado. Não trivial, mas conhecido.

### 7.1 Implicações de adoção em RabbitMQ 4.x (atualização 2026-05-04)

A partir do 4.0, **classic mirrored queues foram removidas** do produto. Para HA (cenário de produção real), a única opção suportada é **quorum queues** + Khepri. Diferenças relevantes vs classic-mirrored:

- **Replicação Raft em vez de leader-follower assíncrono**: minoria perde escritas → mais seguro, mas exige cluster ímpar (3, 5 nós) e tolera apenas N/2−1 falhas.
- **`basic.cancel` em vez de fechar canal** quando consumer estoura timeout: a lib precisa tratar `Closure` graciosamente em vez de assumir que channel-closed = morte.
- **`acquired-count` vs `delivery-count`**: returns sem falha não contam para limites de poison message — muda a heurística de DLX.
- **Priority de mensagem**: era 2:1 ratio em classic, agora **strict ordering em 32 níveis** em quorum. Se a lib usar prioridade, comportamento muda.

**Impacto na estimativa de adoção:** a lista de débitos pré-produção em `consideracoes.md` ganha um item — _redesenhar declaração de filas para quorum_, com testes específicos de redelivery sob falha de líder Raft. Custo: ~2-3 dias.

---

## 8. Custo projetado 12 meses

| Item                                                       | RabbitMQ self-hosted                             | Temporal Cloud (estimativa)                        |
| ---------------------------------------------------------- | ------------------------------------------------ | -------------------------------------------------- |
| Infra                                                      | 3 nodes c/ RAM/CPU dedicados (~$200-400/mês AWS) | $100-200/mês (Essentials/Growth)                   |
| Engenharia para construir lib + observabilidade            | 3-5 dias inicial + manutenção recorrente         | 0 (lib do Temporal já existe; só RoadRunner setup) |
| Engenharia para operar                                     | 1 dia/mês (médio, monitoring + DLX)              | 0 (Cloud) ou ~1 dia/mês (self-hosted EKS)          |
| Risco de outage por bug em lib interna                     | médio-alto (código novo)                         | baixo (Temporal core)                              |
| **Comparação justa só vai ser feita após PoC do Temporal** |                                                  |                                                    |

---

## 9. Risco de SDK/lib decair

### Biblioteca interna de saga (a desenvolver)

- **Bus factor**: 1 (quem escreveu).
- **Mantenedores**: time interno; depende de prioridade contínua.
- **Roadmap**: ad-hoc; sem garantia de manutenção quando autor sai.
- **Documentação**: precisaria ser construída do zero.
- **Casos extremos**: precisariam ser descobertos sozinhos (crash recovery, idempotência, deduplicação) — e cada um custa engenharia.

### Comparação com SDK `temporal/sdk`

- v2.17.1 (mar/2026), 2.4M installs, 384 stars.
- Mantenedor: Spiral Scout (sob contrato com Temporal Inc).
- Risco real: ser de "segunda classe" frente a Go/Java. Mitigação: lib interna que isola apps do SDK.
- Atualizações regulares, segue versões do core Temporal.

**Conclusão preliminar:** uma lib interna sobre RabbitMQ tem risco maior de decair, mas o gap pode ser mitigado com disciplina + testes — desde que o investimento na manutenção seja contínuo.

---

## 10. Próximas medições pendentes

- [ ] Operacional sob carga (ainda não medido — exigiria load test).
- [ ] Comportamento sob deploy mid-flight (rolling restart de orchestrator).
- [ ] Testes simétricos no PoC Temporal (todos os critérios acima).
- [ ] Comparação lado a lado quando ambos PoCs estiverem completos.
