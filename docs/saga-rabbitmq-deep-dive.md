# Deep Dive: RabbitMQ + AMQP (notas de estudo)

> Material conceitual complementar a [`estudo.md`](./estudo.md). Foi originalmente escrito durante a primeira PoC; o **código-fonte** que ilustrava cada conceito foi removido daqui — o PoC vivo em [`../saga-rabbitmq/`](../saga-rabbitmq/) é a referência canônica de implementação. Este documento mantém apenas a parte conceitual, lições e o que falta para produção.

---

## 1. Conceitos fundamentais do AMQP 0-9-1

O RabbitMQ implementa o protocolo AMQP 0-9-1. Estes são os building blocks:

### Exchange

Entidade onde mensagens são publicadas. Roteia para filas usando regras (bindings). Tipos:

| Tipo      | Comportamento                            | Exemplo                              |
| --------- | ---------------------------------------- | ------------------------------------ |
| `direct`  | Routing key exata (1:1)                  | `routing_key=orders` → fila `orders` |
| `fanout`  | Broadcast para todas as filas vinculadas | Notificações multi-consumer          |
| `topic`   | Routing key com wildcards                | `*.log`, `#.error`                   |
| `headers` | Roteamento por headers da mensagem       | Content-based routing                |

O **default exchange** (`''`) é um direct implícito que roteia pelo nome da fila — **é o que a PoC usa**.

### Queue

Buffer que armazena mensagens. Propriedades:

- `durable`: metadados sobrevivem reinícios do broker.
- `exclusive`: vinculada a 1 conexão, deletada ao fechar.
- `auto-delete`: removida quando último consumer desconecta.
- `queue_declare` é **idempotente** — cria se não existe, valida se já existe.

### Binding

Regra que liga exchange → queue, opcionalmente filtrada por routing key.

```
Exchange ---[routing_key=X]---> Queue
```

### Message

Payload binário + atributos:

- `delivery_mode`: 1 (transient) ou **2 (persistent)**.
- `content_type`, `timestamp`, `message_id`, `correlation_id`, `reply_to`.
- Persistente = salva em disco (requer fila durable para sobreviver reinícios).

### Connection e Channel

- **Connection**: TCP socket de longa duração entre app e broker (1 por processo).
- **Channel**: multiplexação lógica dentro de 1 connection (leve, pode ter muitos).
- Channels compartilham 1 TCP → menos overhead de rede.

```
App  ──TCP──  RabbitMQ
  └── Channel 1 (consumer)
  └── Channel 2 (publisher)
  └── Channel 3 (...)
```

> **Lição aprendida na PoC:** quando se usa `pcntl_fork()` em PHP para rodar dois consumers no mesmo processo (commands e compensations), a **conexão AMQP precisa ser aberta DEPOIS do fork**. Caso contrário pai e filho compartilham o mesmo socket TCP e os frames AMQP corrompem ("Invalid frame type 0", "Framing error"). É um caso onde forks em PHP exigem cuidado de inicialização.

### Acknowledgment

Confirmação de processamento:

| Método         | Descrição                                                            |
| -------------- | -------------------------------------------------------------------- |
| `basic.ack`    | Positiva — broker remove a mensagem                                  |
| `basic.nack`   | Rejeição com opção de requeue (extensão RabbitMQ, suporta múltiplas) |
| `basic.reject` | Rejeição simples (AMQP padrão, 1 por vez)                            |

- **Delivery tag**: ID monotonicamente crescente por canal.
- Sem ack + conexão cai → mensagem volta pra fila automaticamente (flag `redeliver=true`).

### Prefetch (QoS)

`basic_qos(prefetch_size, prefetch_count, global)`:

- `prefetch_count=1`: 1 mensagem por vez (fair dispatch, menor throughput).
- Produção recomenda **100-300** para throughput ótimo.
- PoC usa 1 para processar sequencialmente.

---

## 2. Tipos de filas no RabbitMQ 4.x

### Quorum Queues

- Baseadas em **Raft consensus** (líder + followers replicados).
- Obrigatórias para HA em 4.x (Classic Mirrored foram **removidas**).
- Mínimo 3 nodes para tolerância a falhas.
- Dados movidos ativamente para disco, conjunto funcional em RAM.
- Declarar com argumento `x-queue-type: quorum`.

### Classic Queues v2

- Single-node, sem replicação nativa em 4.x.
- Filas transitórias não-exclusivas **depreciadas desde 4.3.0**.
- PoC usa classic queue (single-node, durable) — suficiente para dev/teste.

### Combinações de durabilidade

| Fila      | Mensagem            | Sobrevive reinício? |
| --------- | ------------------- | ------------------- |
| durable   | persistent (mode 2) | Sim                 |
| durable   | transient (mode 1)  | Não (msg perdida)   |
| transient | qualquer            | Não (tudo perdido)  |

---

## 3. Ciclo confiável de mensagens

### Consumer Acknowledgments

- **Auto-ack** (`no_ack=true`): msg considerada entregue ao enviar. Rápido, **inseguro**.
- **Manual ack** (`no_ack=false`): app decide quando confirmar. Mais seguro.
- Se conexão cair com msgs não confirmadas → **requeue automático**.
- **Idempotência obrigatória**: msgs podem ser reentregues.

### Publisher Confirms

- Broker envia `basic.ack` no mesmo canal após aceitar a mensagem.
- Para msgs persistentes: ack após escrita em disco.
- Para msgs roteáveis: ack após aceita por todas as filas.
- PoC **não usa** publisher confirms (necessário em produção).

### Fluxo completo de uma mensagem

```
Producer                    RabbitMQ                     Consumer
   │                           │                            │
   │──basic_publish(msg)──────>│                            │
   │                           │──armazena na fila─────────>│
   │                           │                            │
   │                           │──basic_deliver(msg)──────> │
   │                           │                            │──processa
   │                           │                            │
   │                           │<──────────basic_ack(tag)───│
   │                           │──remove da fila            │
```

---

## 4. Imagem Docker: rabbitmq:4.x-management-alpine

### Tags

- `4.x` = versão do RabbitMQ.
- `management` = plugin Management habilitado (UI web + HTTP API).
- `alpine` = base Alpine Linux (~40MB vs ~150MB Debian).

### Portas

| Porta | Protocolo | Uso                               |
| ----- | --------- | --------------------------------- |
| 5672  | AMQP      | Protocolo principal de mensageria |
| 15672 | HTTP      | Management UI + REST API          |
| 25672 | Erlang    | Inter-node (clustering)           |
| 4369  | EPMD      | Erlang Port Mapper Daemon         |
| 5671  | AMQPS     | AMQP + TLS                        |

### ENV vars

| Variável                 | Descrição                                        |
| ------------------------ | ------------------------------------------------ |
| `RABBITMQ_DEFAULT_USER`  | Usuário admin criado no 1o boot                  |
| `RABBITMQ_DEFAULT_PASS`  | Senha do admin                                   |
| `RABBITMQ_DEFAULT_VHOST` | Virtual host padrão                              |
| `RABBITMQ_ERLANG_COOKIE` | Cookie para clustering (nodes devem ter o mesmo) |

### Volumes (produção)

- `/var/lib/rabbitmq`: dados persistentes (mnesia database, message store).

### Healthcheck

- `rabbitmq-diagnostics -q check_running` (usado na PoC).
- Alternativa mais rigorosa: `rabbitmq-diagnostics -q check_port_connectivity`.

### Internals

- Escrito em **Erlang/OTP** — concorrência nativa via processos leves.
- Cada fila = 1 processo Erlang.
- **Mnesia** = banco de dados distribuído do Erlang para metadados.
- Message store: `msg_store_persistent` (disco) + `msg_store_transient` (RAM).

---

## 5. Biblioteca: php-amqplib

- **GitHub:** https://github.com/php-amqplib/php-amqplib
- **Packagist:** https://packagist.org/packages/php-amqplib/php-amqplib (9.4M+ installs)

Implementação **PHP pura** do protocolo AMQP 0-9-1. Não requer extensão PECL `amqp` — usa sockets PHP nativos (extensão `sockets` necessária).

### Classes principais

| Classe/Método               | Função                             |
| --------------------------- | ---------------------------------- |
| `AMQPStreamConnection`      | Conexão TCP via PHP streams        |
| `AMQPMessage`               | Payload + propriedades da mensagem |
| `$channel->queue_declare()` | Declara fila (idempotente)         |
| `$channel->basic_publish()` | Publica mensagem                   |
| `$channel->basic_consume()` | Registra consumer callback         |
| `$channel->basic_qos()`     | Controle de prefetch               |
| `$channel->wait()`          | Blocking wait por mensagens        |

### Atrito de build

Em imagens Alpine PHP, compilar `ext-sockets` exige `linux-headers` e `pdo_sqlite` exige `sqlite-dev`. Sem essas dependências o `composer install` falha resolvendo `php-amqplib/php-amqplib` que tem `ext-sockets *` como requisito hard.

---

## 6. Links de referência

### AMQP e RabbitMQ

- Conceitos AMQP 0-9-1: https://www.rabbitmq.com/tutorials/amqp-concepts
- Tutorial Hello World PHP: https://www.rabbitmq.com/tutorials/tutorial-one-php
- Tutorial Work Queues: https://www.rabbitmq.com/tutorials/tutorial-two-php
- Filas (tipos, durabilidade): https://www.rabbitmq.com/docs/queues
- Quorum Queues: https://www.rabbitmq.com/docs/quorum-queues
- Confirmações (ack/nack/qos): https://www.rabbitmq.com/docs/confirms
- Dead Letter Exchanges: https://www.rabbitmq.com/docs/dlx
- Docker image: https://hub.docker.com/_/rabbitmq

### php-amqplib

- GitHub: https://github.com/php-amqplib/php-amqplib
- Packagist: https://packagist.org/packages/php-amqplib/php-amqplib

### SAGA Pattern (teoria)

- Paper original (Garcia-Molina, 1987): https://www.cs.cornell.edu/andru/cs711/2002fa/reading/sagas.pdf
- Microservices Patterns (Chris Richardson): https://microservices.io/patterns/data/saga.html
- Orchestration vs Choreography: https://microservices.io/patterns/data/saga.html#example-orchestration-based-saga

---

## 7. O que falta para produção (vs a PoC atual)

| Aspecto                 | PoC                              | Produção                                     |
| ----------------------- | -------------------------------- | -------------------------------------------- |
| Estado da saga          | SQLite local em volume Docker    | Tabela `saga_state` em RDBMS replicado       |
| Publicação              | Fire-and-forget                  | Transactional outbox pattern                 |
| Recebimento             | Sem confirm                      | Publisher confirms                           |
| Falhas                  | Exceção → compensação imediata   | DLX + retry queue com backoff                |
| Idempotência            | Nenhuma (handlers fake)          | Unique constraint / dedup por step           |
| Rastreamento            | Logs `echo` por linha            | Correlation ID distribuído + structured logs |
| HA                      | Single-node classic queue        | Quorum Queues (3+ nodes)                     |
| Monitoramento           | Management UI                    | Prometheus + Grafana + alertas               |
| Compensações que falham | Mensagem `nack` (volta pra fila) | DLX + alerta crítico (operador humano)       |
