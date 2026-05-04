# Compreensão de SAGA: definição, modelos e exemplo de referência

> Este documento descreve **o que é o padrão SAGA**, os dois modelos clássicos (orquestração e coreografia), o exemplo de 3 passos usado por todas as PoCs deste estudo, e os casos em que SAGA **não** é a ferramenta certa.
>
> Para o contexto, premissas e critérios do estudo, ver [`estudo.md`](./estudo.md). Para a recomendação final, ver [`recomendacao-saga.md`](./recomendacao-saga.md). Para vocabulário, ver [`glossario.md`](./glossario.md).

---

## 1. Definição mínima

Uma **SAGA** é uma sequência de transações locais distribuídas em múltiplos serviços/recursos, na qual:

- Cada passo `Tᵢ` é uma transação local com commit atômico no seu próprio recurso.
- Para cada passo `Tᵢ` existe (idealmente) uma **ação compensatória `Cᵢ`** que desfaz semanticamente o efeito de `Tᵢ`. Não é rollback ACID — é **reversão de negócio**.
- Se a saga falhar no passo `k`, executam-se `C_{k-1}, C_{k-2}, …, C_1` em ordem reversa: **LIFO**.

O conceito surgiu (Garcia-Molina/Salem, 1987) como solução para **long-lived transactions** — operações que não cabem em uma única transação ACID porque atravessam fronteiras de banco/serviço ou levam minutos/dias para concluir. Foi reapropriado por Chris Richardson e outros como padrão arquitetural para microsserviços.

### 1.1 Quando SAGA faz sentido

- A operação envolve **mais de um recurso transacional** — bancos diferentes, serviços diferentes, sistemas externos.
- 2PC/XA não é viável (caro, bloqueante, indisponível no caso heterogêneo).
- **Consistência eventual** durante a saga é aceitável (o sistema fica temporariamente em estado intermediário).
- Cada passo tem uma **inversa de negócio** plausível: cancelar pedido, estornar pagamento, liberar reserva.

### 1.2 Propriedades exigidas dos passos

- **Atomicidade local** — cada `Tᵢ` é uma transação ACID no recurso dele.
- **Idempotência** — retries são certos; chamar `Tᵢ` duas vezes deve produzir o mesmo efeito final.
- **Compensação semântica** — `Cᵢ` reverte o efeito observável de `Tᵢ`. Se `Tᵢ` cobrou um cartão, `Cᵢ` estorna; não "desfaz" no sentido ACID.
- **Compensações também idempotentes** — porque podem ser retentadas igualmente.

---

## 2. Os dois modelos

A literatura descreve dois sabores de SAGA, distinguidos pelo **onde mora a lógica de coordenação**.

### 2.1 Orquestração

Um **coordenador central** (workflow / orchestrator / saga manager) comanda cada passo: lê o resultado do passo anterior, decide o próximo, dispara compensação em caso de falha.

```
                ┌────────────────┐
                │  Orchestrator  │
                └────────┬───────┘
            ┌────────────┼────────────┐
            ▼            ▼            ▼
        Service A    Service B    Service C
         (T1/C1)      (T2/C2)      (T3/C3)
```

- **Lógica do fluxo**: centralizada no orchestrator.
- **Cada serviço**: expõe step + compensação como operações; não conhece o fluxo todo.
- **Exemplos de implementação**: Temporal Workflow, AWS Step Functions, lib custom com tabela `saga_state`.

**Quando tende a ser melhor**: pipelines lineares, equipe única, fluxo bem definido, necessidade de auditoria centralizada.

### 2.2 Coreografia

**Não há coordenador central**. Cada serviço escuta eventos e reage publicando o próximo evento. A "saga" é a soma das reações.

```
   OrderCreated ──► ServiceA ──► StockReserved
                                       │
                                       ▼
                                  ServiceB ──► PaymentApproved
                                                    │
                                                    ▼
                                               ServiceC ──► ShippingConfirmed
```

- **Lógica do fluxo**: distribuída entre os serviços.
- **Cada serviço**: dono do próprio step **e** da própria compensação; reage a eventos.
- **Exemplos de implementação**: pub/sub em RabbitMQ/Kafka/EventBridge com lib mínima compartilhada para correlação e padrão de eventos.

**Quando tende a ser melhor**: múltiplos times donos de serviços diferentes, evolução independente, baixo acoplamento desejado, fluxo event-driven natural.

### 2.3 Comparação direta

| Aspecto                     | Orquestração                                   | Coreografia                                      |
| --------------------------- | ---------------------------------------------- | ------------------------------------------------ |
| Lógica do fluxo             | Centralizada                                   | Distribuída                                      |
| Acoplamento entre serviços  | Mais acoplado ao orquestrador                  | Mínimo (só formato de evento)                    |
| Visibilidade do fluxo       | Alta (um lugar tem o "mapa")                   | Baixa (precisa rastrear eventos)                 |
| Debug                       | Mais simples — timeline central                | Mais difícil — correlação por `saga_id`          |
| Mudança no fluxo            | Edita orchestrator                             | Coordena entre múltiplos serviços                |
| Resiliência a falha do coord. | Crítica (single point)                       | N/A — não há coordenador                         |

A escolha **não precisa ser organizacional única**. Domínios diferentes podem usar modelos diferentes; forçar um padrão único costuma ser simplificação excessiva.

---

## 3. Exemplo de referência das PoCs

Para tornar a comparação entre ferramentas neutra e reproduzível, todas as PoCs implementam o **mesmo workflow de 3 passos**, ilustrativo de um checkout multi-serviço.

### 3.1 Os passos

| Passo | Serviço (hipotético)   | Ação local                               | Compensação                              |
| ----- | ---------------------- | ---------------------------------------- | ---------------------------------------- |
| `T₁`  | `acme/inventory`       | `ReserveStock` — reserva itens           | `ReleaseStock` — libera reserva          |
| `T₂`  | `acme/payment-service` | `ChargeCredit` — cobra crédito           | `RefundCredit` — estorna cobrança        |
| `T₃`  | `acme/order-service`   | `ConfirmShipping` — confirma envio       | `CancelShipping` — cancela envio         |

### 3.2 O fluxo

```
ReserveStock  →  ChargeCredit  →  ConfirmShipping  ✓ COMPLETED
   ↓ falha       ↓ falha          ↓ falha
ReleaseStock  ←  RefundCredit  ←  CancelShipping
                                                    ✗ COMPENSATED
```

### 3.3 Cenários executados em todas as PoCs

1. **Happy path** — `T₁ → T₂ → T₃` completam; saga `COMPLETED`.
2. **Falha em `T₂`** — executa `C₁` (`ReleaseStock`); saga `COMPENSATED`.
3. **Falha em `T₃`** — executa `C₂` (`RefundCredit`), depois `C₁` (`ReleaseStock`); saga `COMPENSATED`.

Falhas são injetadas via `FORCE_FAIL=step2|step3` para garantir reprodutibilidade.

### 3.4 Por que esse exemplo é representativo

- Atravessa **três recursos transacionais distintos** (inventory DB, payment provider, order DB).
- Cada passo tem **compensação semântica clara** (não é rollback ACID).
- É **linear, curto e idempotente** — porte mínimo viável que ainda exercita LIFO completo.
- Cabe sem distorções nos quatro modelos avaliados (orquestração com state machine custom, durable execution engine, state machine managed, coreografia event-driven).

Não é o caso "mais robusto possível" de SAGA; é o caso minimalista que exercita as propriedades essenciais e permite comparação ferramenta-a-ferramenta sem o ruído de domínio específico.

---

## 4. Quando NÃO usar SAGA

Esses casos costumam ser confundidos com saga, e a confusão leva a sobre-engenharia.

### 4.1 Job assíncrono com `failed()`

Um job que cai em `failed()` e marca um status local **não é SAGA** — é error handling. Não há sequência de passos coordenados, não há compensação semântica em outro recurso.

### 4.2 Encadear dois jobs

`JobA` dispara `JobB` no final do `handle`. Isso é **pipeline assíncrono**. Vira saga apenas se houver coordenação de estado e compensação caso `JobB` falhe afetando o que `JobA` fez em outro recurso.

### 4.3 Transação de banco em um único serviço

`DB::transaction(fn() => …)` em um banco só é ACID. SAGA existe **justamente porque** ACID não cobre múltiplos recursos.

### 4.4 Webhook com retry

Receber webhook e retentar até funcionar é **at-least-once delivery + idempotência**. Saga só aparece quando a falha definitiva dispara compensação em recursos já modificados.

### 4.5 Compensação trivial em recurso único

Operação que, se falhar, só desliga uma flag no próprio recurso é **rollback local** ou **state machine** — não saga.

### 4.6 Side-effects irreversíveis sem necessidade real de compensação

Se "compensar" o passo é impraticável (e-mail enviado, cobrança liquidada e contabilizada, item físico despachado), e o negócio aceita que o efeito persista, o passo é tratado como não-compensável e a saga não precisa existir só por causa dele. Documentar a aceitação é parte do desenho.

---

## 5. Decisões transversais antes de implementar

Independente do nome dado ao padrão e da plataforma escolhida, as decisões abaixo são as mesmas e precisam ser explicitadas:

- **Orquestração vs coreografia** — usar o critério de §2.3, caso a caso.
- **Onde mora o estado da saga** — em campos de status nos próprios registros de domínio, ou em tabela `saga_state` dedicada com `(saga_id, current_step, completed_steps[], context_payload, status)`. Sagas pequenas e lineares costumam viver bem com a primeira; sagas com ramificação ou alto volume em vôo precisam da segunda.
- **Idempotência por passo** — cada `Tᵢ` e cada `Cᵢ` precisa ser idempotente. Operações que hoje sempre criam um novo registro precisam de `idempotency_key` antes de entrar em saga.
- **Quais passos são realmente compensáveis** — recursos com API reversível são compensáveis; operações que afetam outros consumidores exigem revisão de regra de negócio; side-effects irreversíveis são documentados como tal.
- **Observabilidade mínima** — log estruturado por `saga_id`, métrica de sagas em vôo / compensadas / falhadas-sem-compensação, alerta quando uma compensação falha (esse é o cenário onde o sistema fica de fato inconsistente).

---

## 6. Resumo

- **SAGA** = sequência de transações locais multi-recurso com compensações reversas (LIFO). Resolve long-lived transactions onde ACID distribuído não cabe.
- **Orquestração** = coordenador central; **coreografia** = serviços reagindo a eventos. Escolha por critério, não por dogma.
- **Não é SAGA**: job com `failed()`, pipeline assíncrono trivial, retry de webhook, transação ACID local.
- As PoCs deste estudo usam um workflow neutro de 3 passos (`ReserveStock → ChargeCredit → ConfirmShipping`) para comparar plataformas de forma reproduzível.
- Antes de implementar, definir explicitamente: modelo, localização do estado, idempotência, compensabilidade de cada passo e observabilidade.
