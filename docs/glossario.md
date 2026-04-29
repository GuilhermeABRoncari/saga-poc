# Glossário de siglas e termos

> Sumário das siglas, termos técnicos e jargões usados ao longo do estudo. Tudo que aparece nos outros documentos (`estudo.md`, `compreensao-saga.md`, `recomendacao-saga.md`, `consideracoes.md`, `findings-*.md`, `checklist-testes.md`, `fechamento.md`) tem entrada aqui — não é preciso deduzir nada de fora do repositório.

---

## Termos centrais do estudo

| Sigla / termo                      | Significado                                                                                                                                                                                                        | Onde aparece                                    |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------- |
| **SAGA**                           | Padrão arquitetural para coordenar transações distribuídas entre microsserviços, garantindo consistência eventual via passos compensáveis. Não tem expansão como sigla; é o nome do padrão.                        | Todos os documentos.                            |
| **PoC**                            | **Proof of Concept** — protótipo concreto construído para validar uma hipótese antes de adotar uma tecnologia em produção. Aqui temos `saga-rabbitmq/`, `saga-temporal/` e (em construção) `saga-step-functions/`. | Todos os documentos.                            |
| **LIFO**                           | **Last In, First Out** — estrutura de pilha usada para ordem reversa de compensação: o último step que foi bem-sucedido é o primeiro a ser revertido quando uma saga falha.                                        | `compreensao-saga.md`, `recomendacao-saga.md`.  |
| **Step / Activity / Task**         | Um passo individual da saga (ex.: `ReserveStock`, `ChargeCredit`). Cada plataforma usa nome diferente: RabbitMQ → "step"; Temporal → "Activity"; Step Functions → "Task".                                          | Todos os documentos.                            |
| **Compensation**                   | Ação que reverte um step bem-sucedido durante um rollback. Ex.: `ReleaseStock` reverte `ReserveStock`.                                                                                                             | Todos os documentos.                            |
| **Workflow / Saga definition**     | Especificação ordenada dos steps + suas compensações + regras de retry/timeout. Em Temporal é PHP code; em RabbitMQ é classe `Saga` com `definition()`; em Step Functions é JSON ASL.                              | Todos os documentos.                            |
| **Orchestrator / Workflow worker** | Processo central que coordena o fluxo da saga: lê eventos de conclusão de step, decide próximo step, dispara compensação em falha.                                                                                 | `findings-rabbitmq.md`, `findings-temporal.md`. |
| **Service worker**                 | Processo que executa o handler concreto de um step (ex.: o código que de fato chama `marketplace-api/reserve-stock`).                                                                                              | Ambos PoCs.                                     |

---

## Tecnologias e frameworks

| Sigla / nome       | Significado                                                                                                                                                          | Onde aparece                                |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| **RabbitMQ**       | Broker de mensageria que implementa AMQP. Maduro, battle-tested, self-hosted.                                                                                        | `saga-rabbitmq/`, `findings-rabbitmq.md`.   |
| **Temporal**       | Plataforma open-source de durable execution para workflows de longa duração. Maintido por Temporal Inc; SDK PHP por Spiral Scout.                                    | `saga-temporal/`, `findings-temporal.md`.   |
| **Step Functions** | Serviço AWS managed para orquestrar workflows como state machines em ASL.                                                                                            | `recomendacao-saga.md` §2.3, próximo PoC.   |
| **AMQP**           | **Advanced Message Queuing Protocol** — padrão aberto (v0.9.1, v1.0) implementado pelo RabbitMQ, ActiveMQ, AWS MQ etc. Define exchanges, queues, bindings, ack/nack. | `findings-rabbitmq.md`, `consideracoes.md`. |
| **ASL**            | **Amazon States Language** — DSL em JSON usada por Step Functions para definir workflows (states, transitions, retry, catch).                                        | Próximo PoC.                                |
| **gRPC**           | **Google Remote Procedure Call** — protocolo binário sobre HTTP/2 usado pelo Temporal para comunicação cliente↔server. Em PHP requer extensão PECL `grpc`.           | `findings-temporal.md`, `consideracoes.md`. |
| **RoadRunner**     | Runtime PHP de longa duração mantido pela Spiral Scout. Substitui FPM em workers Temporal por causa do modelo long-lived.                                            | `findings-temporal.md`, `consideracoes.md`. |
| **Laravel**        | Framework PHP usado nos sistemas da empresa.                                                                                                                         | Todos os documentos.                        |
| **PSR**            | **PHP Standards Recommendations** — conjunto de padrões mantidos pelo PHP-FIG (autoload, logging, HTTP, etc).                                                        | Mencionado eventualmente.                   |
| **Composer**       | Gerenciador de dependências do PHP.                                                                                                                                  | Build dos PoCs.                             |
| **PHPStan**        | Static analyzer para PHP. Usado para lint customizado proibindo `date()`, `rand()`, `PDO` em workflow code.                                                          | `consideracoes.md`.                         |

---

## Conceitos de mensageria e durable execution

| Termo                       | Significado                                                                                                                                                                     | Onde aparece                                            |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| **Queue**                   | Estrutura FIFO (com prioridade opcional) onde mensagens aguardam processamento.                                                                                                 | `findings-rabbitmq.md`.                                 |
| **Exchange**                | Em AMQP, ponto de entrada que roteia mensagens para queues conforme bindings (direct, topic, fanout, headers).                                                                  | `findings-rabbitmq.md`.                                 |
| **Binding**                 | Regra que conecta exchange a queue.                                                                                                                                             | `findings-rabbitmq.md`.                                 |
| **DLX / DLQ**               | **Dead Letter Exchange / Dead Letter Queue** — destino para mensagens que esgotaram retries ou foram NACKed. Permite alerting e investigação.                                   | `findings-rabbitmq.md`, `consideracoes.md`.             |
| **Ack / Nack**              | **Acknowledge / Negative Acknowledge** — sinal que o consumer envia ao broker confirmando processamento (ack) ou rejeitando (nack, com requeue opcional).                       | `findings-rabbitmq.md`.                                 |
| **At-least-once delivery**  | Garantia de que cada mensagem será entregue **pelo menos uma vez** — pode haver duplicação. Default do RabbitMQ.                                                                | `findings-rabbitmq.md` §6.3, `consideracoes.md` §1.2.2. |
| **Exactly-once execution**  | Garantia mais forte: cada operação executa **exatamente uma vez**. Temporal entrega via event sourcing + workflow determinismo.                                                 | `consideracoes.md` §2.1.3.                              |
| **Durable execution**       | Capacidade de uma plataforma de preservar estado de workflows através de crashes/restarts. Estado vive no engine, não nos workers.                                              | `consideracoes.md` §2.1.2.                              |
| **Event sourcing**          | Padrão onde estado é derivado de uma sequência append-only de eventos. Temporal armazena history events em Postgres/Cassandra e reconstroi state por replay.                    | `findings-temporal.md`.                                 |
| **Determinismo (workflow)** | Propriedade de que o mesmo input produz a mesma sequência de comandos. Temporal exige isso para replay funcionar — proíbe `date()`, `rand()`, `PDO`, `Http::` em workflow code. | `consideracoes.md` §2.2.1.                              |
| **Replay**                  | Re-execução do workflow code contra o history de eventos para reconstruir state quando worker reinicia ou nova decision task chega.                                             | `findings-temporal.md`.                                 |
| **Heartbeat**               | Sinal periódico que activity envia ao server informando "estou viva". Sem heartbeat, server declara timeout.                                                                    | `findings-temporal.md`.                                 |
| **Idempotency key**         | Chave derivada de `saga_id + step_name` armazenada para evitar processamento duplicado de mesma operação.                                                                       | `consideracoes.md` §1.2.2.                              |
| **Outbox pattern**          | Padrão onde escrita de DB e publicação de mensagem ficam numa única transação local; um worker separado faz o fan-out. Resolve at-least-once em sistemas com DB próprio.        | `consideracoes.md` §1.2.1.                              |
| **Saga "órfã"**             | Saga em estado `RUNNING` que ficou parada porque o orchestrator morreu permanentemente sem retomada.                                                                            | `findings-rabbitmq.md` §6.4.                            |

---

## Métricas e termos de qualidade

| Termo                   | Significado                                                                                                                     | Onde aparece                  |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------- | ----------------------------- |
| **LOC**                 | **Lines of Code** — métrica simples para comparar tamanho de implementações.                                                    | Todos os findings.            |
| **DX**                  | **Developer Experience** — qualidade subjetiva de trabalhar com a tecnologia (legibilidade, ferramental, mensagens de erro).    | `consideracoes.md`, findings. |
| **p50 / p95 / p99**     | Percentis de latência. p50 = mediana; p99 = só 1% das requests demoraram mais que esse valor.                                   | T6.2, `findings-*.md`.        |
| **SLO**                 | **Service Level Objective** — meta de qualidade do serviço (ex.: "p99 < 100ms").                                                | T4.4, `consideracoes.md`.     |
| **TCO**                 | **Total Cost of Ownership** — custo total de adoção considerando infra + eng + operação ao longo do tempo.                      | `recomendacao-saga.md`.       |
| **Bus factor**          | Quantas pessoas precisam sair antes do conhecimento se perder. Bus factor=1 é precário.                                         | `consideracoes.md` §1.2.6.    |
| **Postmortem**          | Análise depois de incidente. Aqui especificamente: capacidade de reconstruir o que aconteceu numa saga arbitrária.              | T3.4, `consideracoes.md`.     |
| **Silent corruption**   | Erro que produz dado inconsistente sem alerta ou exception — pior tipo de bug.                                                  | T5.1, `consideracoes.md` §5.  |
| **Observabilidade**     | Capacidade de inspecionar o sistema em runtime (logs, métricas, traces, timelines).                                             | Todos os findings.            |
| **Postmortem-friendly** | Capacidade de uma plataforma de facilitar investigação de incidentes (history rico, payloads preservados, replay programático). | T3.4.                         |

---

## Termos de infra e operação

| Sigla / nome         | Significado                                                                                                     | Onde aparece                  |
| -------------------- | --------------------------------------------------------------------------------------------------------------- | ----------------------------- |
| **Docker**           | Plataforma de containerização.                                                                                  | Todos os PoCs.                |
| **Docker Compose**   | Ferramenta para definir e rodar aplicações multi-container localmente.                                          | Todos os PoCs.                |
| **Docker Swarm**     | Orquestrador de containers da Docker Inc, usado pela empresa hoje.                                              | `recomendacao-saga.md` §1.1.  |
| **K8s / Kubernetes** | Orquestrador de containers padrão de mercado.                                                                   | `recomendacao-saga.md`.       |
| **EKS**              | **Elastic Kubernetes Service** — Kubernetes managed da AWS.                                                     | `recomendacao-saga.md`.       |
| **Helm**             | Package manager para Kubernetes; chart oficial Temporal usa Helm.                                               | `consideracoes.md` §2.2.5.    |
| **Healthcheck**      | Endpoint ou comando que confirma se um serviço está pronto para receber tráfego.                                | `findings-temporal.md` bug 3. |
| **Aurora**           | Serviço de Postgres/MySQL managed da AWS, recomendado para self-host EKS de Temporal.                           | `recomendacao-saga.md`.       |
| **OpenSearch**       | Fork do Elasticsearch managed pela AWS. Temporal usa para indexação avançada.                                   | `consideracoes.md`.           |
| **CloudWatch**       | Logs/métricas managed da AWS.                                                                                   | `recomendacao-saga.md` §2.3.  |
| **X-Ray**            | Tracing distribuído da AWS.                                                                                     | `recomendacao-saga.md` §2.3.  |
| **Lambda**           | FaaS (Function as a Service) da AWS — execução serverless de funções. Step Functions integra nativo com Lambda. | `recomendacao-saga.md` §2.3.  |
| **Bref**             | Custom runtime PHP para AWS Lambda. Permite rodar Laravel em Lambda.                                            | Próximo PoC.                  |
| **LocalStack**       | Emulador local de serviços AWS (Step Functions, SQS, Lambda etc). Permite PoC sem custo.                        | Próximo PoC.                  |

---

## Conceitos AWS (relevantes para Step Functions)

| Sigla / nome                     | Significado                                                                                                                                 |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| **AWS**                          | **Amazon Web Services** — provedor de cloud.                                                                                                |
| **SQS**                          | **Simple Queue Service** — fila gerenciada da AWS.                                                                                          |
| **SNS**                          | **Simple Notification Service** — pub/sub gerenciado.                                                                                       |
| **EventBridge**                  | Bus de eventos gerenciado, sucessor do CloudWatch Events.                                                                                   |
| **IAM**                          | **Identity and Access Management** — controle de permissões da AWS.                                                                         |
| **ARN**                          | **Amazon Resource Name** — identificador único de recursos AWS.                                                                             |
| **VPC**                          | **Virtual Private Cloud** — rede virtual isolada na AWS.                                                                                    |
| **Task Token**                   | Mecanismo Step Functions que permite uma Task aguardar callback assíncrono externo (`SendTaskSuccess`/`SendTaskFailure`).                   |
| **State Machine**                | Em Step Functions, é a definição completa do workflow em ASL.                                                                               |
| **Express vs Standard Workflow** | Modos do Step Functions: Express (rápido, alto volume, sem history persistente) vs Standard (durável, com replay, ~$0.025/1000 transições). |

---

## Termos do framework e código PHP

| Termo                                           | Significado                                                                                                              |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| **PDO**                                         | **PHP Data Objects** — abstração de DB do PHP. Usado nas duas PoCs para SQLite/Postgres/MySQL.                           |
| **WAL**                                         | **Write-Ahead Logging** — modo de SQLite que permite leituras concorrentes com 1 writer. Habilitado em T6.2.             |
| **PRAGMA busy_timeout**                         | Diretiva SQLite que define quanto tempo (ms) esperar antes de falhar em "database is locked".                            |
| **opcache**                                     | Cache de bytecode do PHP. Habilitado em FPM, desabilitado em CLI por default.                                            |
| **PECL**                                        | **PHP Extension Community Library** — repositório de extensões C do PHP. PECL grpc é exigência do SDK Temporal PHP.      |
| **FPM**                                         | **FastCGI Process Manager** — runtime PHP padrão para web.                                                               |
| **Generator / `yield`**                         | Recurso PHP que permite uma função produzir valores incrementalmente. Temporal usa para suspender/retomar workflow code. |
| **`#[WorkflowInterface]`, `#[ActivityMethod]`** | PHP attributes (decorators) usados pelo SDK Temporal para marcar classes/métodos.                                        |

---

## Sistemas e contexto da empresa

| Termo                                   | Significado                                                                                          |
| --------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| **`marketplace-api`**                   | Sistema PHP/Laravel responsável por loja, produtos, estoque. Foco do PR #2021 (`ActivateStoreSaga`). |
| **`users-api`**                         | Sistema PHP/Laravel responsável por usuários, OAuth, billing.                                        |
| **`lookpay-api`**                       | Sistema PHP/Laravel responsável por pagamentos.                                                      |
| **GraphQL Gateway**                     | Camada de federação que expõe APIs internas como um schema único.                                    |
| **`laravel-resilience`**                | Pacote interno de circuit breaker.                                                                   |
| **`mobilestock/laravel-saga`**          | Pacote esboçado na PoC RabbitMQ (lib que precisaríamos construir).                                   |
| **`mobilestock/laravel-temporal-saga`** | Pacote interno proposto para encapsular o SDK Temporal + lint + helpers.                             |
| **`ActivateStoreSaga`**                 | Saga real do PR #2021, usada como caso-base. Versão reduzida (3 steps) implementada nas PoCs.        |

---

## Códigos de erro específicos

| Código        | Origem       | Significado                                                                                                                                                                                |
| ------------- | ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **TMPRL1100** | Temporal SDK | Determinismo violado durante replay — workflow code produziu comando diferente do que está registrado em history. Apareceu em T1.1 (timer adicionado/removido) e T5.1 (steps reordenados). |

---

## Status convention da checklist

| Símbolo | Significado                                                                                |
| ------- | ------------------------------------------------------------------------------------------ |
| `[ ]`   | Não executado                                                                              |
| `[~]`   | Em andamento OU executado parcialmente                                                     |
| `[x]`   | Executado, resultado anotado                                                               |
| `[!]`   | Executado, identificou bloqueio/bug que precisa ser tratado antes de continuar             |
| `[⏭]`   | Pulado / não-executável neste contexto (ex.: requer dev externo, requer credenciais Cloud) |

---

## Como usar este documento

- **Ao escrever novos docs do estudo:** se introduzir uma sigla nova, adicionar entrada aqui.
- **Ao ler docs antigos:** se encontrar sigla desconhecida, verificar aqui antes de buscar fora do repo.
- **Para revisão por terceiros (tech lead, novos devs):** este é o ponto de partida — entendendo este glossário, dá para ler qualquer outro documento do estudo sem dependência externa.
