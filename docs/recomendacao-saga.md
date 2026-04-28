# Recomendação de ferramenta SAGA — padrão organizacional

> Este documento registra o **estado atual da decisão** sobre qual ferramenta adotar como padrão de SAGA para os sistemas da empresa (e-commerce, logística/transporte, financeiro, estoque). É a saída em andamento da tarefa de definição de padrão, complementar ao estudo em [`estudo.md`](./estudo.md) e à compreensão do padrão em [`compreensao-saga.md`](./compreensao-saga.md).
>
> **Status atual: recomendação em aberto.** Após revisão das premissas (ver §1.1), nenhum critério hoje disponível elimina honestamente as opções RabbitMQ+lib interna ou Temporal. As avaliações até aqui foram **especulativas** — derivadas de leitura de docs, comparações de feature lists e analogias com outras empresas. Sem PoC concreto não há base para fechar a recomendação.
>
> **Próximo passo:** plano de PoC comparativo (§3) com critérios de decisão registrados *antes* da implementação para evitar viés ex-post.

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

Esta seção registra o que sabemos hoje. **Nenhuma das avaliações é veredito**: são hipóteses a serem testadas no PoC.

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

**Status:** marcada como descartada no `estudo.md` original (foco em RabbitMQ + Temporal). Se o PoC comparativo das duas finalistas frustrar, reabrir avaliação aqui.

### 2.4 SQS puro

Não substitui SAGA engine: sem state machine, sem compensação automática, sem replay. Continua sendo a opção certa para *job queue genérico*, não para *orquestração de SAGA*. Fora do PoC.

## 3. Plano de PoC comparativo

Objetivo: gerar evidência concreta antes de fechar a recomendação. Sem isso, qualquer escolha é narrativa.

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

## 5. Quando reavaliar premissas (antes do PoC fechar)

Se durante o PoC qualquer um destes mudar, parar e revisar antes de continuar:

- **Migração EKS for acelerada** (todos os apps em poucos meses) → vantagem operacional do Temporal aumenta.
- **Migração EKS for cancelada** ou marketplace-api voltar para Swarm → custo do Temporal sobe (workers fora do ambiente canônico).
- **Volume estimado de SAGAs ficar muito baixo** (<100/dia agregadas) → talvez nenhum dos dois se justifique; SQS + lógica simples basta.
- **Algum sistema crítico precisar de SAGA antes do PoC fechar** → decisão pontual com SQS + compensação manual; não antecipa o padrão.

## 6. Resumo de uma frase

A recomendação está **em aberto**: nenhuma das opções foi eliminada por critério técnico ou de infra após a revisão de premissas (EKS gradual + conectividade EKS↔Swarm via internet); avaliações até aqui foram especulativas, então o próximo passo é um **PoC comparativo** entre RabbitMQ+lib interna e Temporal, implementando o mesmo workflow de referência com critérios de decisão registrados *antes* da implementação para evitar viés ex-post.
