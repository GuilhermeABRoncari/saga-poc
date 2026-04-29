# Recomendação de ferramenta SAGA — padrão organizacional

> Este documento registra a **decisão sobre qual ferramenta adotar como padrão de SAGA** para os sistemas da empresa (e-commerce, logística/transporte, financeiro, estoque). É complementar ao estudo em [`estudo.md`](./estudo.md), à compreensão do padrão em [`compreensao-saga.md`](./compreensao-saga.md), e à síntese das 6 baterias de teste em [`fechamento.md`](./fechamento.md).
>
> **Status atual (2026-04-29): RECOMENDAÇÃO FECHADA — Adotar Temporal como padrão organizacional.**
>
> Esta recomendação está fundamentada em **20 testes Tier 1-6** executados contra **três PoCs reais** (RabbitMQ, Temporal, Step Functions/LocalStack). Detalhes em [`checklist-testes.md`](./checklist-testes.md), [`findings-rabbitmq.md`](./findings-rabbitmq.md), [`findings-temporal.md`](./findings-temporal.md), [`findings-step-functions.md`](./findings-step-functions.md), [`consideracoes.md`](./consideracoes.md) e [`fechamento.md`](./fechamento.md).
>
> **3ª PoC (Step Functions) reaberta e fechada em 2026-04-29:** confirmou que Step Functions adiciona "zero-ops" como atrativo, mas perde nos critérios qualitativos críticos (latência ~16x maior que Temporal, lock-in AWS profundo, custo $51k/ano vs ~$5k/ano self-host EKS). Não muda a recomendação — só fortalece.
>
> **Sujeita a confirmação do tech lead** com base na pergunta-chave de §6 (frequência esperada de mudanças na forma das sagas).

---

## 1. Enquadramento

A tarefa não é escolher ferramenta para um caso pontual (ex.: ativação de loja no `marketplace-api`). É **definir o padrão que será imposto a todos os sistemas** que usarão SAGA daqui pra frente. Isso muda os critérios de avaliação:

| Critério | Caso pontual | Padrão organizacional |
|---|---|---|
| Custo de adoção inicial | Alto | Diluído (paga uma vez, vários consumidores) |
| Curva de aprendizado | Custo direto | Investimento que se amortiza |
| Lock-in de fornecedor | Pouco importa | Crítico |
| Determinismo / debugabilidade | "Bom ter" | Obrigatório (auditoria, postmortems) |
| Padronização entre apps | Marginal | É **o objetivo** da tarefa |
| Substrato de execução | Swarm hoje | Swarm + EKS gradual (ver §1.1) |

Escala esperada: 4 sistemas (e-commerce, logística, financeiro, estoque) interagindo entre si via SAGAs. Inevitavelmente, vão surgir fluxos do tipo: "pedido cria reserva no estoque, dispara pagamento, agenda transporte" — clássico cenário de orquestração multi-serviço.

### 1.1 Premissas atualizadas (substituem versões anteriores)

A versão anterior deste documento partia da premissa "EKS confirmado no roadmap próximo, stack toda migra junto". Após nova conversa com o lead, a realidade é mais matizada:

- **Migração para EKS é gradual, não em bloco.** Inicialmente apenas o `marketplace-api` vai para o EKS. Os demais sistemas continuam em Docker Swarm por tempo indeterminado.
- **Pods no EKS conseguem acessar serviços expostos do Swarm via internet pública.** Praticamente todos os serviços já estão expostos (`marketplace-api`, `users-api`, `lookpay-api`, etc.). Exceções são serviços de infraestrutura como Redis, que não são alvo de orquestração de SAGA.
- **Consequência prática para SAGA:** workers de orquestrador (qualquer ferramenta) podem rodar no EKS e disparar Activities que chamam APIs Laravel residentes no Swarm via HTTPS. O argumento "Temporal não roda em Swarm" deixa de ser bloqueio técnico — vira escolha de onde colocar os workers, não impedimento.
- **Argumento "EKS evita lib interna" enfraquece.** Como a stack permanece híbrida por tempo indefinido, qualquer padrão escolhido vai conviver com Swarm por anos. A escolha precisa ser sustentável nesse cenário, não só no estado-final EKS.

Resultado: **nenhuma das opções está descartada por critério de infra**. RabbitMQ+lib interna, Temporal (Cloud ou self-hosted) e Step Functions são todos viáveis nessa topologia híbrida.

### 1.2 Por que a recomendação anterior estava enviesada

A versão anterior fechou em "Temporal por orquestração, Cloud → EKS self-hosted". Olhando friamente:

- Argumentos pró-Temporal eram **derivados de marketing e feature lists** (durable execution, UI de timeline, replay determinístico) — não de experiência operando o sistema na nossa casa.
- Argumentos contra RabbitMQ+lib interna eram **especulativos** ("disciplina permanente para manter a lib viva", "30% do que o Temporal Web entrega") — não medidos.
- O custo real de Temporal em Laravel (RoadRunner em workers, determinismo, SDK de segunda classe) foi reconhecido mas **subestimado** sem nunca ter sido sentido na prática.
- O custo real de construir `mobilestock/laravel-saga` (LOC, manutenção, ergonomia) foi declarado alto **sem nenhuma referência concreta**.

Conclusão honesta: estava-se decidindo com base em narrativa, não em evidência. O lead está certo em pedir PoC antes de fechar.

## 2. Avaliação por ferramenta — estado atual

> **Atualização pós-PoC (2026-04-29):** as hipóteses listadas abaixo foram validadas (ou refutadas) em 20 testes Tier 1-6. As "Hipóteses pró/contra" representam o estado pré-PoC; o resultado empírico está em [`consideracoes.md`](./consideracoes.md) §1, §2 e §3, e na síntese de [`fechamento.md`](./fechamento.md).

### 2.1 RabbitMQ + biblioteca interna `mobilestock/laravel-saga`

**Hipóteses pró:**
- Time já domina filas e Laravel; curva de aprendizado baixa para o transport.
- Sem novo runtime (RoadRunner) nem novo modelo de execução (workflows determinísticos).
- Controle total da API: ergonomia pode ser desenhada para o jeito Laravel da casa.
- Sem lock-in de fornecedor; AMQP é padrão aberto.
- Convive bem com Swarm e EKS sem mudança de runtime.

**Hipóteses contra (a validar):**
- Construir state machine + outbox + DLX + idempotência + observabilidade é trabalho recorrente.
- Sem UI de debug equivalente à Temporal Web — precisaríamos investir em Grafana/traces customizados.
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
- Custo Temporal Cloud cresce com volume; self-hosted exige Postgres + Elasticsearch + 4 serviços (Frontend/History/Matching/Worker).

**O que o PoC precisa medir:**
- Tempo de onboarding até primeiro workflow rodando (incluindo RoadRunner).
- Frequência de erros de determinismo durante desenvolvimento.
- Custo de Temporal Cloud no volume estimado dos próximos 12 meses.
- Esforço para padronizar via pacote interno (`mobilestock/laravel-temporal-saga`) escondendo as arestas do SDK.
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

**Status (atualizado 2026-04-29):** descartada inicialmente, **reaberta como 3ª PoC** (`saga-step-functions/`) após decisão preliminar pró-Temporal. Executada em LocalStack 3.8 com os mesmos 20 testes Tier 1-6. Resultados completos em [`findings-step-functions.md`](./findings-step-functions.md). Veredito: confirmou as hipóteses contra (lock-in profundo, custo) e adicionou achados sobre latência alta (p99=2092ms vs 22ms RabbitMQ); o atrativo "zero-ops" não compensa o pacote de desvantagens estruturais. Não muda a recomendação principal.

### 2.4 SQS puro

Não substitui SAGA engine: sem state machine, sem compensação automática, sem replay. Continua sendo a opção certa para *job queue genérico*, não para *orquestração de SAGA*. Fora do PoC.

## 3. Plano de PoC comparativo (EXECUTADO — concluído em 2026-04-29)

> **Status:** PoC concluída. 20 testes Tier 1-6 executados. Resultados em [`checklist-testes.md`](./checklist-testes.md) e [`fechamento.md`](./fechamento.md). Esta seção é mantida como **registro do plano original** que guiou a execução — os critérios de §3.2 foram congelados antes do PoC e seguidos rigorosamente para evitar viés ex-post.

Objetivo (original): gerar evidência concreta antes de fechar a recomendação. Sem isso, qualquer escolha é narrativa.

### 3.1 Workflow de referência

Implementar o **mesmo** workflow nas duas opções finalistas. Candidato natural: `ActivateStoreSaga` (caso do PR #2021 do backend), que já tem 5 passos atravessando `marketplace-api` ↔ `users-api` com compensação completa documentada em [`compreensao-saga.md`](./compreensao-saga.md) §3.3.

Se `ActivateStoreSaga` for grande demais para PoC, usar uma versão reduzida:
1. Passo A em `marketplace-api` (transação local com compensação).
2. Passo B em `users-api` (chamada HTTP com compensação).
3. Passo C que falha intencionalmente em alguns runs para exercitar compensação.

O importante: **mesmo escopo nos dois PoCs**, mesmos pontos de falha, mesmos requisitos de observabilidade.

### 3.2 Critérios de decisão (registrar antes de implementar)

Pontuar cada ferramenta nos critérios abaixo. Pesos podem ser ajustados, mas **lista é congelada antes do PoC** para evitar que o resultado defina os critérios.

| Critério | Como medir | Por que importa |
|---|---|---|
| **Esforço até happy path** | Horas reais até workflow completo rodando em ambiente local | Mede curva de aprendizado e DX inicial |
| **Esforço de compensação completa** | LOC + horas para cobrir todos os caminhos de falha | Mede o ganho real de SAGA first-class vs construído |
| **Observabilidade out-of-the-box** | O que enxergamos sem investimento extra (saga em andamento, falhas, payloads) | Diferencial frequentemente alegado do Temporal |
| **Esforço para observabilidade aceitável** | Horas para chegar ao mínimo aceitável (dashboard de sagas + alerta de compensação falha) | Custo real do RabbitMQ se o ganho do Temporal Web for significativo |
| **DX em code review** | Reviewer consegue entender o fluxo sem rodar? Onde mora a lógica? | Crítico para padrão organizacional usado por 4 times |
| **Resiliência simulada** | Matar worker mid-flight; simular deploy mid-flight; falha de compensação | Sem isso, durable execution é só promessa |
| **Operação simulada** | Quanto da operação cai no time vs no engine/managed | Mede overhead recorrente, não inicial |
| **Custo projetado 12 meses** | Cloud + infra + horas-engenheiro de manutenção | Padronização tem custo, é preciso medir |
| **Risco de SDK/lib decair** | SDK PHP do Temporal: cadência de release; lib interna: bus factor | Padrão precisa sobreviver a saídas de pessoas |

### 3.3 Estrutura física do PoC

- [`saga-rabbitmq/`](../saga-rabbitmq) — workflow de referência implementado com RabbitMQ + esboço de `mobilestock/laravel-saga`.
- [`saga-temporal/`](../saga-temporal) — mesmo workflow implementado com `temporal/sdk` + RoadRunner workers + esboço de `mobilestock/laravel-temporal-saga`.
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

Independente de qual ferramenta vencer, qualquer SAGA na empresa precisa cumprir:

1. **Idempotência por Activity** — qualquer engine pode reentregar; toda Activity recebe `idempotency_key` derivada de `saga_id + step_name`.
2. **Estado persistido** — saga sobrevive a crashes/restarts (no engine ou no nosso DB, depende da ferramenta).
3. **Compensação documentada** — explícita por step, sem "TODO: compensar depois".
4. **Correlation ID** — `saga_id` propagado como header HTTP em todas as chamadas downstream.
5. **Observabilidade default** — saga em andamento / compensada / falha órfã / compensação que falhou são todos visíveis sem investigação.
6. **Timeout explícito** — em workflow inteiro e em cada step.
7. **Alertas** — falha de compensação **sempre** vira alerta crítico (sistema fica realmente inconsistente, humano precisa ver).

Esses critérios são input do PoC: ambas as ferramentas precisam mostrar como atendem (nativamente ou via código nosso).

## 5. Quando reavaliar premissas (mantido como referência histórica do plano pré-PoC)

Se durante o PoC qualquer um destes mudar, parar e revisar antes de continuar:

- **Migração EKS for acelerada** (todos os apps em poucos meses) → vantagem operacional do Temporal aumenta.
- **Migração EKS for cancelada** ou marketplace-api voltar para Swarm → custo do Temporal sobe (workers fora do ambiente canônico).
- **Volume estimado de SAGAs ficar muito baixo** (<100/dia agregadas) → talvez nenhum dos dois se justifique; SQS + lógica simples basta.
- **Algum sistema crítico precisar de SAGA antes do PoC fechar** → decisão pontual com SQS + compensação manual; não antecipa o padrão.

## 6. Pergunta-chave para validação com o tech lead

A pergunta concreta cuja resposta calibra peso final do achado mais sério (T5.1 — silent corruption sob reordenamento):

> **"Com que frequência você espera mudar a forma de uma saga (adicionar step, reordenar, mudar compensação) vs mudar regras de negócio dentro dos passos?"**

- **Se "raramente" (típico):** RabbitMQ ainda é viável, mas exige ~17-23 dias eng + disciplina permanente em 4 times. Risco residual de silent corruption se algum dev esquecer `saga_version`.
- **Se "frequentemente" (atípico):** Temporal é seguro por construção. Risco T5.1 evitado estruturalmente.
- **Se "não sabemos":** assumir defensivamente que mudanças vão acontecer — Temporal compra a garantia automaticamente.

A resposta também calibra **timeline de adoção**: 4 sistemas × times independentes × deploys ao longo de anos = probabilidade cumulativa alta de incidentes mesmo se "raros".

## 7. Recomendação fechada (2026-04-29)

**Adotar Temporal como padrão organizacional para SAGA**, com as ressalvas técnicas abaixo.

### 7.1 Justificativa primária

20 testes Tier 1-6 executados confirmaram empiricamente:

1. **T5.1 (silent corruption sob reordenamento de steps) — achado mais grave:** RabbitMQ-PoC marca saga `COMPLETED` com state corrompido (estoque 2x, pagamento perdido) sob mudança comum (reordenar steps em deploy). Temporal panic LOUD com mensagem clara. Em 4 sistemas durante anos, esquecimento humano é certeza cumulativa.
2. **T1.4 + T4.1 (durable execution):** Temporal sobreviveu a 30s de Postgres caído + 10s de network outage; RabbitMQ-PoC: 3 workers caíram juntos com broker, sem reconexão automática.
3. **T3.4 (postmortem rico):** Temporal entrega payloads de entrada e saída de cada step automaticamente; RabbitMQ-PoC só persiste `result` da lib — payloads de entrada são perdidos para sempre.
4. **T4.4 (timeout vs error):** Temporal classifica 4 tipos distintos; RabbitMQ-PoC não tem conceito de timeout — handler travado bloqueia consumer.
5. **T2.2 (cobertura automática de falhas):** Temporal classifica `Failed` para qualquer caminho de falha terminal; RabbitMQ-PoC exige código explícito por caminho (~3-5 dias eng + disciplina permanente).

A natureza qualitativa desses critérios (correção, durabilidade, observabilidade) supera os quantitativos onde RabbitMQ ganha (latência p99 22ms vs 351ms; RAM idle 170 MB vs 439 MB; custo Cloud em escala $58k/ano).

### 7.2 Ressalvas técnicas

- **Custo de adoção real existe:** ~1 semestre de calibração para o time interiorizar a dialética determinística (proibido `date()`, `rand()`, `PDO`, `Http::` em workflow code). Mitigação: pacote interno `mobilestock/laravel-temporal-saga` + lint PHPStan + treinamento + apps/_template_.
- **Cloud só nos primeiros 6-12 meses.** Cálculo de TCO em [`fechamento.md`](./fechamento.md) §3.2 e [`consideracoes.md`](./consideracoes.md) §7: a partir de ~10M actions/mês (qualquer dos 4 sistemas após adotado), self-host EKS é financeiramente obrigatório.
- **PECL grpc + RoadRunner pesam no setup local.** Aceitar como custo one-time per-dev (~25 min na primeira vez).
- **Race condition na inicialização** (workers tentam conectar antes do server pronto): adicionar healthcheck gRPC + `depends_on` no compose canônico.
- **SDK PHP é "segunda classe"** (Spiral Scout sob contrato com Temporal Inc): mitigar com pacote interno isolando apps do SDK; fork é viável (Apache 2.0).

### 7.3 Alternativa minoritária

Se o tech lead responder à pergunta de §6 com "**a forma da saga muda raramente E o time se compromete a manter `saga_version` + lint custom + code review centralizado SEM falhar**", então RabbitMQ + lib `mobilestock/saga` continua viável.

Custo: ~17-23 dias eng inicial + manutenção recorrente + risco residual permanente. Não é recomendação errada per se; é recomendação **mais arriscada** dado o histórico humano de esquecimentos em deploys.

### 7.4 Casos pontuais

Casos pontuais que **não justificam adotar plataforma nova** (1-2 fluxos isolados, sistema legado sem prazo de migração) podem usar **SQS + lógica simples + idempotência + alerta manual**. **Não tornar isso padrão.**

### 7.5 Próximos passos

1. **Validar com o tech lead** apresentando este documento + [`fechamento.md`](./fechamento.md) + reprodução de T5.1 ao vivo ou em vídeo curto.
2. **Decidir Cloud vs self-host EKS** para os primeiros 6 meses (recomendação: começar Cloud para reduzir overhead inicial).
3. **Construir `mobilestock/laravel-temporal-saga`** como pacote interno encapsulando RoadRunner + retry policies padrão + helpers de Saga + sanity checks de determinismo.
4. **Treinar primeiros devs** com workshop de 1-2 dias + apps/_template_ canônico.
5. **Migrar primeiro caso real** — `ActivateStoreSaga` no `marketplace-api` (PR #2021 do backend).
6. **Estabelecer governance:** ADR + lint PHPStan (proíbe `date()`, `rand()`, `PDO`, `Http::` em workflow code) + code review centralizado nas primeiras 4-6 semanas.

## 8. Quando reavaliar a recomendação

Se durante a adoção qualquer um destes mudar, parar e revisar antes de continuar:

- **Volume real de sagas se confirmar muito baixo** (<1000/dia agregadas) → reconsiderar SQS + lógica simples.
- **Tech lead responder à pergunta de §6 com "raramente E o time mantém disciplina"** → revisar tradeoff RabbitMQ vs Temporal.
- **Spiral Scout perder contrato com Temporal Inc** → re-avaliar SDK PHP (custo de fork ~viável).
- **AWS lançar Step Functions com SDK PHP nativo + custo razoável** → reconsiderar.
- **Migração EKS for cancelada** → reabrir avaliação (Temporal não suporta Swarm oficialmente).

## 9. Resumo de uma frase

A recomendação está **fechada** após 20 testes Tier 1-6 contra PoCs reais: **adotar Temporal como padrão organizacional**, primariamente porque RabbitMQ-PoC produziu silent corruption real em cenário comum (reordenar steps mid-deploy) enquanto Temporal panicou loud com mensagem clara — em 4 sistemas durante anos com 4 times deploying, a probabilidade cumulativa de esquecer mitigação manual no RabbitMQ é alta demais para ser padrão organizacional, mesmo com o trade-off de ~1 semestre de calibração com a dialética determinística do Temporal.
