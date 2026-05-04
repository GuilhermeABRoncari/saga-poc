# Recomendação de ferramenta para SAGA — estudo comparativo

> Este documento registra as recomendações derivadas de um estudo comparativo entre três abordagens para implementar o padrão SAGA em uma stack Laravel: RabbitMQ + biblioteca interna, Temporal e AWS Step Functions. É complementar ao [`fechamento.md`](./fechamento.md) (síntese das 6 baterias de teste), [`consideracoes.md`](./consideracoes.md) (prós/contras detalhados) e [`checklist-testes.md`](./checklist-testes.md) (matriz Tier 1-6).
>
> ## Status do estudo
>
> Este estudo é uma **comparação aberta**, sem decisão pré-fixada. Apresenta evidências para várias direções possíveis e procura orientar a escolha de SAGA por critérios técnicos mensuráveis, em vez de preferência de plataforma. Há duas iterações registradas:
>
> 1. **Primeira iteração (concluída):** três PoCs orquestradas (RabbitMQ + lib interna, Temporal, Step Functions/LocalStack) submetidas aos mesmos 20 testes Tier 1-6.
> 2. **Segunda iteração (em curso):** o estudo passou a considerar **saga coreografada** (sem orquestrador central, lib mínima detectando erros e publicando eventos de compensação consumidos por handlers idempotentes em cada serviço) como caminho viável. Algumas conclusões da primeira iteração precisam ser relidas à luz desse modelo:
>    - **T5.1** (silent corruption sob reordenamento de steps) **não se aplica** ao modelo coreografado — não há `saga_definition` central para reordenar.
>    - A discussão sobre necessidade de tabela `saga_step` no banco assume orquestração centralizada e não é universal.
>    - A comparação Tier 1-6 mediu apenas modelos orquestrados, portanto fica enviesada quando lida como avaliação de "todo o espaço de SAGA".
>
> Próximos passos do estudo:
>
> 1. Construir a 4ª PoC: `saga-rabbitmq-coreografado/` — sem tabela central, lib mínima de compensação por evento, handlers idempotentes (ver §10).
> 2. Re-projetar testes Tier 1-6 para o modelo coreografado: alguns caem (T5.1), outros mudam de forma (compensação fora de ordem, evento perdido, handler não-idempotente, loops), outros se mantêm (T1.4, T3.4).
> 3. Reformular a recomendação como **árvore de decisão** orquestração ⇄ coreografia, em vez de escolha de ferramenta única. Cada padrão se aplica a casos diferentes — coreografia para fluxos chatty/desacoplados, orquestração para fluxos com estado complexo/auditoria/timeouts não-triviais.
>
> O conteúdo abaixo desta linha é a recomendação fechada na primeira iteração e mantida como **histórico**, não como decisão final do estudo. Leia com a ressalva acima.

---

## 1. Enquadramento

A pergunta que o estudo persegue não é "qual ferramenta resolve um caso pontual?", mas **qual modelo + qual ferramenta servem como padrão sustentável para múltiplos serviços** que precisarão coordenar transações distribuídas. Esse enquadramento muda os critérios de avaliação:

| Critério                      | Caso pontual  | Padrão para múltiplos serviços             |
| ----------------------------- | ------------- | ------------------------------------------ |
| Custo de adoção inicial       | Alto          | Diluído (paga uma vez, vários consumidores)|
| Curva de aprendizado          | Custo direto  | Investimento que se amortiza               |
| Lock-in de fornecedor         | Pouco importa | Crítico                                    |
| Determinismo / debugabilidade | "Bom ter"     | Obrigatório (auditoria, postmortems)       |
| Padronização entre apps       | Marginal      | É **o objetivo** do estudo                 |
| Substrato de execução         | Único hoje    | Stack híbrida por tempo indeterminado      |

A arquitetura alvo do estudo envolve vários domínios que vão interagir entre si via SAGAs. Inevitavelmente surgirão fluxos do tipo: "pedido cria reserva no estoque, dispara pagamento, agenda transporte" — clássico cenário de orquestração multi-serviço.

### 1.1 Premissas de infraestrutura

A versão inicial deste documento partia da premissa "migração para uma orquestração de containers única no roadmap próximo, stack toda migra junto". A realidade considerada agora é mais matizada:

- **Migração para orquestrador novo é gradual, não em bloco.** Inicialmente apenas alguns serviços vão para o novo substrato; os demais continuam em Docker Swarm por tempo indeterminado.
- **Pods no novo cluster acessam serviços expostos do Swarm via internet pública.** Praticamente todos os serviços relevantes para SAGA já têm endpoint exposto. Exceções são serviços de infraestrutura (cache, etc.) que não são alvo de orquestração de SAGA.
- **Consequência prática para SAGA:** workers de orquestrador (qualquer ferramenta) podem rodar em um substrato e disparar Activities que chamam APIs HTTP residentes no outro. O argumento "Temporal não roda em Swarm" deixa de ser bloqueio técnico — vira escolha de onde colocar os workers, não impedimento.
- **A escolha precisa ser sustentável em stack híbrida**, não apenas no estado-final.

Resultado: **nenhuma das opções está descartada por critério de infra**. RabbitMQ + lib interna, Temporal (Cloud ou self-hosted) e Step Functions são todos viáveis nessa topologia híbrida.

### 1.2 Por que a primeira recomendação estava enviesada

A versão inicial fechou em "Temporal por orquestração, Cloud → self-hosted". Olhando friamente:

- Argumentos pró-Temporal eram **derivados de marketing e feature lists** (durable execution, UI de timeline, replay determinístico) — não de experiência operando o sistema localmente.
- Argumentos contra RabbitMQ + lib interna eram **especulativos** ("disciplina permanente para manter a lib viva", "30% do que o Temporal Web entrega") — não medidos.
- O custo real de Temporal em Laravel (RoadRunner em workers, determinismo, SDK de segunda classe) foi reconhecido mas **subestimado** sem nunca ter sido sentido na prática.
- O custo real de construir uma lib interna de saga (LOC, manutenção, ergonomia) foi declarado alto **sem nenhuma referência concreta**.

Conclusão honesta: estava-se decidindo com base em narrativa, não em evidência. As PoCs e os testes Tier 1-6 foram construídos justamente para gerar essa evidência.

## 2. Avaliação por ferramenta — estado atual

> **Atualização pós-PoC:** as hipóteses listadas abaixo foram validadas (ou refutadas) em 20 testes Tier 1-6. As "Hipóteses pró/contra" representam o estado pré-PoC; o resultado empírico está em [`consideracoes.md`](./consideracoes.md) §1, §2 e §3, e na síntese de [`fechamento.md`](./fechamento.md).

### 2.1 RabbitMQ + biblioteca interna de saga

**Hipóteses pró:**

- Time já domina filas e Laravel; curva de aprendizado baixa para o transport.
- Sem novo runtime (RoadRunner) nem novo modelo de execução (workflows determinísticos).
- Controle total da API: ergonomia pode ser desenhada para o estilo Laravel.
- Sem lock-in de fornecedor; AMQP é padrão aberto.
- Convive bem com qualquer substrato de containers sem mudança de runtime.

**Hipóteses contra (a validar):**

- Construir state machine + outbox + DLX + idempotência + observabilidade é trabalho recorrente.
- Sem UI de debug equivalente à Temporal Web — exigiria investir em Grafana/traces customizados.
- "Padronização" via lib interna depende de disciplina permanente para mantê-la viva e ergonômica.

**O que o PoC precisa medir:**

- LOC e horas até o happy path do workflow de referência.
- LOC e horas para cobrir compensação de todos os caminhos de falha.
- Esforço para construir visibilidade mínima aceitável (saga em andamento, falhas, compensações).
- Como fica a experiência de code review de uma saga (lê-se o fluxo sem rodar?).

### 2.2 Temporal (Cloud ou self-hosted)

**Hipóteses pró:**

- SAGA first-class via `Workflow\Saga` — compensação declarativa, LIFO automático.
- Durable execution: estado da saga vive no engine, sobrevive a crashes/deploys.
- UI de timeline (Temporal Web) entrega observabilidade out-of-the-box.
- Event sourcing + replay determinístico facilita postmortem.
- Temporal Cloud reduz overhead operacional inicial.

**Hipóteses contra (a validar):**

- SDK PHP é mantido pela Spiral Scout, não pelo core Temporal — risco de defasagem em features novas.
- RoadRunner obrigatório nos workers — runtime extra para cada app que orquestra.
- Restrições de determinismo (proibido `date()`, `sleep()`, `rand()`, DB, HTTP em workflow code) são contraintuitivas para time Laravel-first; risco de bugs sutis.
- Workflow versioning com `Workflow::getVersion()` adiciona complexidade em deploys.
- Custo Temporal Cloud cresce com volume; self-hosted exige Postgres ou MySQL 8 (**MariaDB não suportado** — ver §2.2.6 em `findings-temporal.md`) + Elasticsearch + 4 serviços (Frontend/History/Matching/Worker).

**O que o PoC precisa medir:**

- Tempo de onboarding até primeiro workflow rodando (incluindo RoadRunner).
- Frequência de erros de determinismo durante desenvolvimento.
- Custo de Temporal Cloud no volume estimado dos próximos 12 meses.
- Esforço para padronizar via pacote interno escondendo as arestas do SDK.
- Comportamento real durante deploy com workflows em voo.

### 2.3 AWS Step Functions

**Hipóteses pró:**

- Managed (sem operação de cluster).
- SAGA suportado via `Catch`/`Retry` declarativo.
- Observabilidade nativa (CloudWatch + X-Ray).
- Integração direta com SQS, Lambda, EventBridge.

**Hipóteses contra:**

- State machine vive em **JSON separado do código** Laravel — versionamento, code review e testes ficam fora do fluxo PHP.
- Custo por transição (~US$ 0,025 por 1000 transições) escala desfavoravelmente em e-commerce.
- Lock-in AWS profundo; migração futura ou estratégia multi-cloud exige refazer N workflows.
- Integrações com APIs internas exigem Lambda intermediária ou HTTP Task — mais peças móveis.

**Status pós-PoC:** descartada inicialmente, **reaberta como 3ª PoC** (`saga-step-functions/`) após decisão preliminar pró-Temporal. Executada em LocalStack 3.8 com os mesmos 20 testes Tier 1-6. Resultados completos em [`findings-step-functions.md`](./findings-step-functions.md). Veredito: confirmou as hipóteses contra (lock-in profundo, custo) e adicionou achados sobre latência alta (p99=2092ms vs 22ms RabbitMQ); o atrativo "zero-ops" não compensa o pacote de desvantagens estruturais. Não muda a recomendação principal.

### 2.4 SQS puro

Não substitui SAGA engine: sem state machine, sem compensação automática, sem replay. Continua sendo a opção certa para _job queue genérico_, não para _orquestração de SAGA_. Fora do PoC.

## 3. Plano de PoC comparativo (executado)

> **Status:** PoC concluída. 20 testes Tier 1-6 executados. Resultados em [`checklist-testes.md`](./checklist-testes.md) e [`fechamento.md`](./fechamento.md). Esta seção é mantida como **registro do plano original** que guiou a execução — os critérios de §3.2 foram congelados antes do PoC e seguidos rigorosamente para evitar viés ex-post.

Objetivo (original): gerar evidência concreta antes de fechar a recomendação. Sem isso, qualquer escolha é narrativa.

### 3.1 Workflow de referência

Implementar o **mesmo** workflow nas duas opções finalistas. Foi adotada uma versão reduzida com 3 passos atravessando dois serviços fictícios:

1. `ReserveStock` — transação local com compensação `releaseStock`.
2. `ChargeCredit` — chamada HTTP cross-service com compensação `refundCredit`.
3. `ConfirmShipping` — passo final que pode falhar intencionalmente em alguns runs (`FORCE_FAIL=step3`) para exercitar reversão LIFO.

O importante: **mesmo escopo nas duas PoCs**, mesmos pontos de falha, mesmos requisitos de observabilidade.

### 3.2 Critérios de decisão (registrados antes de implementar)

Pontuar cada ferramenta nos critérios abaixo. Pesos podem ser ajustados, mas a **lista é congelada antes do PoC** para evitar que o resultado defina os critérios.

| Critério                                   | Como medir                                                                               | Por que importa                                                     |
| ------------------------------------------ | ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| **Esforço até happy path**                 | Horas reais até workflow completo rodando em ambiente local                              | Mede curva de aprendizado e DX inicial                              |
| **Esforço de compensação completa**        | LOC + horas para cobrir todos os caminhos de falha                                       | Mede o ganho real de SAGA first-class vs construído                 |
| **Observabilidade out-of-the-box**         | O que se enxerga sem investimento extra (saga em andamento, falhas, payloads)            | Diferencial frequentemente alegado do Temporal                      |
| **Esforço para observabilidade aceitável** | Horas para chegar ao mínimo aceitável (dashboard de sagas + alerta de compensação falha) | Custo real do RabbitMQ se o ganho do Temporal Web for significativo |
| **DX em code review**                      | Reviewer consegue entender o fluxo sem rodar? Onde mora a lógica?                        | Crítico para padrão usado por múltiplos serviços                    |
| **Resiliência simulada**                   | Matar worker mid-flight; simular deploy mid-flight; falha de compensação                 | Sem isso, durable execution é só promessa                           |
| **Operação simulada**                      | Quanto da operação cai no time vs no engine/managed                                      | Mede overhead recorrente, não inicial                               |
| **Custo projetado 12 meses**               | Cloud + infra + horas-engenheiro de manutenção                                           | Padronização tem custo, é preciso medir                             |
| **Risco de SDK/lib decair**                | SDK PHP do Temporal: cadência de release; lib interna: bus factor                        | Padrão precisa sobreviver a saídas de pessoas                       |

### 3.3 Estrutura física do PoC

- [`saga-rabbitmq/`](../saga-rabbitmq) — workflow de referência implementado com RabbitMQ + esboço de lib interna de saga.
- [`saga-temporal/`](../saga-temporal) — mesmo workflow implementado com `temporal/sdk` + RoadRunner workers + esboço de wrapper interno.
- Cada PoC tem README documentando: setup, como rodar, como simular falhas, métricas coletadas.

### 3.4 Cronograma proposto

- **Semana 1–2:** PoC RabbitMQ atinge happy path + compensação básica.
- **Semana 3–4:** PoC Temporal atinge happy path + compensação básica (Cloud free tier).
- **Semana 5:** simulações de resiliência nos dois; coleta final de métricas.
- **Semana 6:** documento de comparação preenchendo a tabela §3.2 e proposta de recomendação fechada.

Cronograma é estimativa; ajustar conforme escopo do workflow de referência e disponibilidade.

### 3.5 Saídas obrigatórias do PoC

- Tabela §3.2 preenchida com números, não adjetivos.
- README de cada PoC com tudo necessário para outro engenheiro reproduzir.
- Este documento (`recomendacao-saga.md`) reescrito com recomendação fechada e justificativa baseada em evidência.
- Lista de "armadilhas encontradas" por ferramenta, para virar parte da ADR final.

## 4. Critérios de qualidade que o padrão precisa garantir

Independente de qual ferramenta vencer, qualquer SAGA na arquitetura alvo precisa cumprir:

1. **Idempotência por Activity** — qualquer engine pode reentregar; toda Activity recebe `idempotency_key` derivada de `saga_id + step_name`.
2. **Estado persistido** — saga sobrevive a crashes/restarts (no engine ou no DB próprio, depende da ferramenta).
3. **Compensação documentada** — explícita por step, sem "TODO: compensar depois".
4. **Correlation ID** — `saga_id` propagado como header HTTP em todas as chamadas downstream.
5. **Observabilidade default** — saga em andamento / compensada / falha órfã / compensação que falhou são todos visíveis sem investigação.
6. **Timeout explícito** — em workflow inteiro e em cada step.
7. **Alertas** — falha de compensação **sempre** vira alerta crítico (sistema fica realmente inconsistente, humano precisa ver).

Esses critérios são input do PoC: ambas as ferramentas precisam mostrar como atendem (nativamente ou via código próprio).

## 5. Quando reavaliar premissas (mantido como referência histórica do plano pré-PoC)

Se durante o PoC qualquer um destes mudar, parar e revisar antes de continuar:

- **Migração de orquestrador for acelerada** (toda a frota em poucos meses) → vantagem operacional do Temporal aumenta.
- **Migração for cancelada** ou substrato voltar para ambiente único → custo do Temporal sobe (workers fora do ambiente canônico).
- **Volume estimado de SAGAs ficar muito baixo** (<100/dia agregadas) → talvez nenhum dos dois se justifique; SQS + lógica simples basta.
- **Algum sistema crítico precisar de SAGA antes do PoC fechar** → decisão pontual com SQS + compensação manual; não antecipa o padrão.

## 6. Pergunta-chave para calibrar a recomendação

A pergunta concreta cuja resposta calibra peso final do achado mais sério (T5.1 — silent corruption sob reordenamento):

> **"Com que frequência se espera mudar a forma de uma saga (adicionar step, reordenar, mudar compensação) vs mudar regras de negócio dentro dos passos?"**

A resposta considerada pelo estudo aponta para "regra de negócio muda com muita frequência; convém minimizar responsabilidades do dev sobre tabela central de saga".

**Implicação:** a alternativa minoritária de §7.3 (RabbitMQ + lib interna com `saga_version`) fica enfraquecida sob orquestração — sua pré-condição era "time se compromete a manter `saga_version` + lint custom + code review centralizado SEM falhar", o oposto de "mínimo de responsabilidades sobre tabela central". Em Temporal, regra de negócio vive em **Activities (PHP comum)**, sem `Workflow::getVersion()` no dia-a-dia — o melhor caso para Temporal: paga zero custo de versionamento em deploys frequentes de regra. Detalhamento em [`fechamento.md`](./fechamento.md) §5.

A leitura mais ampla, no entanto, é que essa preferência também sinaliza interesse por **modelos sem state machine central** — o que reabre coreografia como caminho legítimo, não apenas Temporal como ferramenta. Daí o plano da 4ª PoC em §10.

## 7. Recomendação consolidada da primeira iteração

**Adotar Temporal como padrão para SAGA orquestrada**, com as ressalvas técnicas abaixo. Esta recomendação é válida para o ramo orquestrado do estudo; o ramo coreografado segue em avaliação.

### 7.1 Justificativa primária

20 testes Tier 1-6 executados confirmaram empiricamente:

1. **T5.1 (silent corruption sob reordenamento de steps) — achado mais grave:** RabbitMQ-PoC marca saga `COMPLETED` com state corrompido (estoque 2x, pagamento perdido) sob mudança comum (reordenar steps em deploy). Temporal panic LOUD com mensagem clara. Em múltiplos serviços ao longo de anos, esquecimento humano é certeza cumulativa.
2. **T1.4 + T4.1 (durable execution):** Temporal sobreviveu a 30s de Postgres caído + 10s de network outage; RabbitMQ-PoC: 3 workers caíram juntos com broker, sem reconexão automática.
3. **T3.4 (postmortem rico):** Temporal entrega payloads de entrada e saída de cada step automaticamente; RabbitMQ-PoC só persiste `result` da lib — payloads de entrada são perdidos para sempre.
4. **T4.4 (timeout vs error):** Temporal classifica 4 tipos distintos; RabbitMQ-PoC não tem conceito de timeout — handler travado bloqueia consumer.
5. **T2.2 (cobertura automática de falhas):** Temporal classifica `Failed` para qualquer caminho de falha terminal; RabbitMQ-PoC exige código explícito por caminho (~3-5 dias eng + disciplina permanente).

A natureza qualitativa desses critérios (correção, durabilidade, observabilidade) supera os quantitativos onde RabbitMQ ganha (latência p99 22ms vs 351ms; RAM idle 137 MiB vs 439 MB em 4.3; custo Cloud em escala $58k/ano).

### 7.2 Ressalvas técnicas

- **Custo de adoção real existe:** ~1 semestre de calibração para o time interiorizar a dialética determinística (proibido `date()`, `rand()`, `PDO`, `Http::` em workflow code). Mitigação: pacote interno (wrapper Laravel-Temporal) + lint PHPStan + treinamento + template canônico.
- **Cloud só nos primeiros 6-12 meses.** Cálculo de TCO em [`fechamento.md`](./fechamento.md) §3.2 e [`consideracoes.md`](./consideracoes.md) §7: a partir de ~10M actions/mês (qualquer dos serviços envolvidos depois de adotado), self-host é financeiramente obrigatório.
- **PECL grpc + RoadRunner pesam no setup local.** Aceitar como custo one-time per-dev (~25 min na primeira vez).
- **Race condition na inicialização** (workers tentam conectar antes do server pronto): adicionar healthcheck gRPC + `depends_on` no compose canônico.
- **SDK PHP é "segunda classe"** (Spiral Scout sob contrato com Temporal Inc): mitigar com pacote interno isolando apps do SDK; fork é viável (Apache 2.0).

### 7.3 Alternativa minoritária no ramo orquestrado

A pré-condição para que a alternativa "RabbitMQ + lib interna com versionamento manual" seja viável era: "**a forma da saga muda raramente E o time se compromete a manter `saga_version` + lint custom + code review centralizado SEM falhar**". A preferência sinalizada pelo estudo ("mínimo de responsabilidades ao dev pra manusear `saga_step` no banco") conflita diretamente com manter `saga_version` na coluna e bumpar a cada mudança de forma.

Custo estimado de RabbitMQ + lib (orquestrado) seria ~17-23 dias eng inicial + manutenção recorrente + risco residual permanente. Caminho não retomado dentro do ramo orquestrado — mas o ramo **coreografado** é discussão separada (§10).

### 7.4 Casos pontuais

Casos pontuais que **não justificam adotar plataforma nova** (1-2 fluxos isolados, sistema legado sem prazo de migração) podem usar **SQS + lógica simples + idempotência + alerta manual**. **Não tornar isso padrão.**

### 7.5 Próximos passos (ramo orquestrado)

1. **Validar o ramo orquestrado** apresentando este documento + [`fechamento.md`](./fechamento.md) + reprodução de T5.1 em vídeo curto.
2. **Decidir Cloud vs self-host** para os primeiros 6 meses (recomendação: começar Cloud para reduzir overhead inicial).
3. **Construir wrapper interno Laravel-Temporal** como pacote isolando RoadRunner + retry policies padrão + helpers de Saga + sanity checks de determinismo.
4. **Treinar primeiros devs** com workshop de 1-2 dias + template canônico.
5. **Migrar primeiro caso real.**
6. **Estabelecer governance:** ADR + lint PHPStan (proíbe `date()`, `rand()`, `PDO`, `Http::` em workflow code) + code review centralizado nas primeiras 4-6 semanas.

## 8. Quando reavaliar a recomendação

Se durante a adoção qualquer um destes mudar, parar e revisar antes de continuar:

- **Volume real de sagas se confirmar muito baixo** (<1000/dia agregadas) → reconsiderar SQS + lógica simples.
- **Spiral Scout perder contrato com Temporal Inc** → re-avaliar SDK PHP (custo de fork é viável).
- **AWS lançar Step Functions com SDK PHP nativo + custo razoável** → reconsiderar.
- **Migração entre substratos for cancelada** → reabrir avaliação (Temporal não suporta Swarm oficialmente).

## 9. Resumo de uma frase

A primeira iteração do estudo, sobre **modelos orquestrados**, recomenda **Temporal**. Uma segunda iteração trouxe coreografia para a comparação, e a recomendação final precisa ser uma **árvore de decisão** orquestração ⇄ coreografia, não uma escolha única de ferramenta. Detalhes na próxima seção.

---

## 10. Plano da 4ª PoC — saga coreografada (proposto)

> Esta seção é proposta de trabalho, ainda não totalmente executada. Será detalhada em documento próprio antes do build (`saga-rabbitmq-coreografado/README.md`).

**Modelo a implementar:**

- Mesmos 3 passos do workflow de referência (`ReserveStock` → `ChargeCredit` → `ConfirmShipping`).
- Cada serviço publica eventos de domínio em tópico RabbitMQ (`stock.reserved`, `credit.charged`, `shipping.failed`).
- Step seguinte é disparado por subscription no evento anterior — sem orquestrador.
- Quando qualquer step publica `<step>.failed`, a lib publica evento `saga.<id>.failed` num tópico fanout.
- Cada serviço **ouve** `saga.<id>.failed` e roda sua compensação **se aplicável** (idempotente via dedup-key).
- Sem tabela `saga_states`, sem `saga_definition`, sem `saga_version`.

**Lib estimada:** <100 LOC. Responsabilidades: detectar exception em handler de evento → publicar `saga.failed` → decorator de handler de compensação fazendo dedup via tabela leve `compensation_log(saga_id, step, applied_at)`.

**Tier 1-6 reaplicado (esboço):**

| Teste original | Sobrevive? | Forma adaptada                                                                  |
| -------------- | ---------- | ------------------------------------------------------------------------------- |
| T1.1 versionamento | não se aplica | Não há saga_definition central                                              |
| T1.4 falha de persistência | sim | RabbitMQ broker caído por 30s — eventos são reentregues?                       |
| T2.2 cobertura de falhas | adaptado | Compensação **idempotente** sob retry: estoque devolvido 2x = bug ou ok?      |
| T3.4 postmortem | adaptado | Sem timeline central — correlation-id + logs distribuídos resolvem?           |
| T5.1 reordenar steps | não se aplica | Não há ordem central; cada serviço só conhece sua subscription                |
| **Novo: ordering** | adicionado | Compensação chega antes do evento de sucesso — handler idempotente sobrevive?   |
| **Novo: handler perdido** | adicionado | Serviço offline quando `saga.failed` é publicado — DLQ + replay funciona?     |
| **Novo: loop** | adicionado | Handler de compensação falha sempre — sistema detecta e para?                  |

**Critérios de comparação adicionados:**

- Acoplamento entre serviços (coreografia ganha por construção).
- Custo cognitivo de debugar saga distribuída sem timeline central.
- Disciplina exigida do dev (idempotência por handler).
- Comportamento sob ordering parcial de eventos.
