# Compreensão de SAGA: o que é, o que não é, e onde nosso caso se encaixa

> Este documento existe porque, durante o estudo, ficou claro que o termo "SAGA" é usado de forma mais ampla do que a definição clássica. O objetivo aqui é separar três coisas:
>
> 1. O que é **SAGA na literatura** (Garcia-Molina/Salem 1987, Chris Richardson).
> 2. O que **não é SAGA** (e costuma ser confundido).
> 3. Como exemplos práticos de workflows multi-serviço se encaixam (ou não) na definição clássica.

---

## 1. SAGA na literatura

### Definição mínima

Uma SAGA é uma **sequência de transações locais** distribuídas em múltiplos serviços/recursos, na qual:

- Cada passo `Tᵢ` é uma transação local, com commit atômico no seu próprio recurso.
- Para cada passo `Tᵢ` existe (idealmente) uma **ação compensatória `Cᵢ`** que desfaz semanticamente o efeito de `Tᵢ` — não é rollback ACID, é reversão de negócio.
- Se a saga falhar no passo `k`, executa-se `C_{k-1}, C_{k-2}, …, C_1` em ordem reversa (LIFO).

O conceito surgiu como solução para **long-lived transactions** (LLTs): operações que não cabem em uma única transação ACID porque atravessam fronteiras de banco/serviço ou levam minutos/dias para concluir.

### Quando SAGA faz sentido

- A operação envolve **mais de um recurso transacional** (bancos diferentes, microsserviços diferentes, sistemas externos).
- Não há possibilidade de XA / 2PC (ou ele é caro/inviável).
- É aceitável que o sistema fique **temporariamente inconsistente** (consistência eventual) durante a saga.
- Cada passo tem uma **inversa de negócio** plausível (cancelar pedido, estornar pagamento, liberar reserva).

### Os dois sabores

| Estilo           | Como decide o próximo passo                              | Onde mora a lógica            | Exemplo                                                                                                  |
| ---------------- | -------------------------------------------------------- | ----------------------------- | -------------------------------------------------------------------------------------------------------- |
| **Coreografia**  | Cada serviço escuta eventos e reage publicando o próximo | Distribuída entre os serviços | `OrderCreated` → estoque reserva → publica `StockReserved` → pagamento cobra → publica `PaymentApproved` |
| **Orquestração** | Um coordenador central comanda cada passo                | Centralizada no orquestrador  | Workflow do Temporal / `SagaOrchestrator` chamando passo a passo                                         |

### Exemplo canônico (Richardson)

`PlaceOrder` numa arquitetura de microsserviços:

1. `Order Service`: cria pedido em `PENDING`. Compensação: marcar como `CANCELLED`.
2. `Customer Service`: reserva crédito do cliente. Compensação: liberar crédito.
3. `Kitchen Service`: cria ticket. Compensação: cancelar ticket.
4. `Order Service`: aprova pedido (`APPROVED`).

Se o passo 3 falhar, executa-se a compensação do 2 e do 1.

---

## 2. O que **não é** SAGA (mas costuma ser chamado assim)

### 2.1 Job assíncrono com `failed()`

Um job na fila com método `failed()` que muda um status **não é SAGA**. É **error handling**.

```php
public function handle() { /* ... */ }
public function failed() {
    $this->domain->update(['status' => 'FAILED']);
}
```

Isso é uma transação local que falha e marca um campo. Não há sequência de passos coordenados, não há compensação semântica de outro recurso, não há orquestração.

### 2.2 Encadear dois jobs

`JobA` dispara `JobB` no final do `handle`. Isso é **pipeline assíncrono**, não SAGA. Vira saga só se: (a) houver coordenação de estado entre eles, (b) existir compensação se `JobB` falhar afetando o que `JobA` fez em outro recurso.

### 2.3 Transação de banco em um único serviço

`DB::transaction(fn() => ...)` em um único banco é ACID. SAGA existe **justamente porque** ACID não é viável atravessando recursos.

### 2.4 Webhook com retry

Receber webhook e re-tentar até funcionar é **at-least-once delivery** + idempotência. Saga só aparece se a falha definitiva precisar disparar compensações em recursos já modificados por passos anteriores.

### 2.5 "1 pra 1" com compensação trivial

Operação `A` que, se falhar, simplesmente desliga uma flag em `A` mesmo. Não há segundo recurso, não há sequência. Isso é só **rollback local** ou **state machine**.

---

## 3. Um caso prático: provisionamento multi-recurso

Para tornar a discussão concreta, consideramos um cenário comum em plataformas SaaS: ativação de uma nova conta de cliente que envolve provisionamento em múltiplos recursos heterogêneos.

### 3.1 Forma síncrona (anti-padrão)

Fluxo disparado pelo front-end ao final de uma jornada de cadastro:

1. Job assíncrono cria infra DNS e resolve um desafio ACME → status `CHALLENGE_CREATION_SUCCESS`.
2. Job assíncrono completa o desafio, emite o certificado e armazena os arquivos em object storage → status `CERTIFICATE_ISSUANCE_SUCCESS`.
3. **Front-end chama** um endpoint de "ativação" que:
   - Localiza o registro com status `CERTIFICATE_ISSUANCE_SUCCESS`.
   - Chama um serviço externo para habilitar uma feature flag no usuário (HTTP M2M).
   - Chama o mesmo serviço externo para criar credenciais OAuth.
   - Persiste localmente o `client_id` e o `client_secret` retornados.

O ponto fraco: a etapa 3 depende da decisão do front em chamar o endpoint. Se o front não chamar (usuário fechou a aba, request perdido), a conta fica com certificado pronto mas sem credenciais OAuth e sem flag — estado inconsistente entre dois serviços.

### 3.2 Forma migrada para fila

Remover o endpoint síncrono e fazer a ativação acontecer **automaticamente** ao fim do passo de certificado, via fila. Ou seja:

- O job de emissão de certificado termina com sucesso → dispara um próximo job (`ActivateAccount`) que executa o que estava no endpoint síncrono.
- Se algum passo da ativação falhar, executa-se uma **compensação** que reverte o que já foi feito (revogar/excluir o OAuth client criado, desligar a flag, opcionalmente revogar o certificado).

### 3.3 Isso é SAGA?

**Tecnicamente, sim.** Aqui está o porquê:

- Atravessa **dois recursos transacionais distintos** (banco local + serviço externo de identidade), cada um com seu próprio commit.
- Não dá para colocar tudo numa transação ACID.
- Cada passo é uma transação local com efeito observável fora do serviço (criar OAuth client é um "commit" remoto).
- Existe a necessidade de compensação semântica: se o passo final falhar depois que o serviço externo já criou o OAuth client, esse client precisa ser excluído — caso contrário fica órfão.

Mapeando para o vocabulário SAGA:

| Passo | Recurso             | Ação local                                | Compensação                            |
| ----- | ------------------- | ----------------------------------------- | -------------------------------------- |
| `T₁`  | DNS / ACME          | criar hosted zone, registrar desafio      | excluir hosted zone / desafio          |
| `T₂`  | Banco local + S3    | emitir certificado, salvar arquivos no S3 | revogar certificado, excluir do S3     |
| `T₃`  | Serviço de identidade | habilitar feature flag                    | desabilitar a flag                     |
| `T₄`  | Serviço de identidade | criar OAuth client                        | excluir OAuth client                   |
| `T₅`  | Banco local         | persistir `client_id` e `client_secret`   | apagar esses campos                    |

Se for orquestrada (um job coordenador chamando os passos) → **SAGA por orquestração**.
Se cada job publicar evento e o próximo escutar → **SAGA por coreografia**.

### 3.4 Quando o caso parece simples ("1 pra 1")

É comum descrever uma saga deste porte como "1 pra 1": pipeline linear, sem ramificações, sem paralelismo, com cada passo tendo uma compensação direta. A definição rigorosa exige os pontos da seção 1, mas um caso minimalista (linear, poucos passos) ainda **cabe** dentro dela — não é uso indevido, é uso enxuto.

### 3.5 O que NÃO seria SAGA nesse exemplo

- Trocar o endpoint por um job que só atualiza status localmente. Sem cruzar fronteira de serviço, sem compensação multi-recurso, é só pipeline.
- Confiar em retry infinito do serviço externo sem registrar estado de saga. Eventualmente "consistente" não é o mesmo que "sagado": SAGA exige que, ao desistir, o sistema **compense** o que já foi feito.

---

## 4. Casos reais (e fortes) de SAGA na literatura

Para calibrar a expectativa do que SAGA "merece" existir:

1. **Reserva de viagem**: voo + hotel + carro, cada um em fornecedor diferente. Falha no carro → cancela voo e hotel.
2. **E-commerce checkout**: criar pedido + reservar estoque + cobrar pagamento + agendar entrega. Falha na cobrança → libera estoque e cancela pedido.
3. **Onboarding de cliente em banco**: KYC + abertura de conta + emissão de cartão + cadastro em sistema antifraude. Falha no antifraude → fecha conta, cancela cartão.
4. **Pipeline de mídia**: upload → transcode → moderação → publicação. Falha na moderação → remove de CDN, apaga transcodes.
5. **Provisionamento multi-cloud**: criar VPC AWS + criar projeto GCP + registrar DNS + emitir certificado. Falha no certificado → desfaz tudo.

O exemplo de provisionamento descrito em §3 é parente do #5: provisionamento atravessando recursos heterogêneos. É menor em escala mas idêntico em natureza.

---

## 5. O que decidir antes de implementar

Independente do nome ("SAGA", "pipeline com compensação", "orquestração de ativação"), as decisões de design são as mesmas:

### 5.1 Orquestração ou coreografia?

Os dois sabores não são intercambiáveis. Critérios práticos para escolher:

| Critério                                                                | Tende para                                                                          |
| ----------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| Pipeline linear curto (≤5 passos), single-team, fluxo bem definido      | **Orquestração** — único lugar tem o "mapa" da saga, debug simples                  |
| Múltiplos times donos de serviços diferentes, evolução independente     | **Coreografia** — acoplamento mínimo, cada serviço só conhece os eventos que assina |
| Estado complexo (timeouts, retries por step, dependências entre passos) | **Orquestração** — coordenação central simplifica raciocínio                        |
| Fluxo chatty event-driven, vários serviços reagindo em paralelo         | **Coreografia** — pub/sub é o modelo natural                                        |
| Auditoria/compliance exige timeline central da saga                     | **Orquestração** — observabilidade out-of-the-box                                   |
| Compensação por step é local e idempotente por construção               | **Coreografia** — cada serviço dono da sua compensação                              |

A escolha **não é binária organizacional** — em uma plataforma com vários domínios, casos diferentes podem usar padrões diferentes. Forçar um padrão único para todos os casos costuma ser simplificação excessiva.

### 5.2 Onde mora o estado da saga?

Há dois extremos. Em uma ponta, o estado vive distribuído nos próprios registros de domínio (um campo `status` por entidade), o que costuma bastar para sagas pequenas e lineares. Na outra ponta, cria-se uma tabela `saga_state` com `(saga_id, current_step, completed_steps[], context_payload, status)` para permitir retomar de onde parou e saber o que compensar — abordagem necessária quando o número de instâncias em vôo é alto ou quando há ramificações. Não há resposta universal: a complexidade da saga determina o nível de instrumentação que vale a pena pagar.

### 5.3 Quais passos são realmente compensáveis?

- Recurso reversível via API (criar/excluir, habilitar/desabilitar) → ✅ compensável.
- Operação que afeta outros consumidores (desligar uma flag pode quebrar outra funcionalidade) → ⚠️ revisar regra de negócio antes de assumir compensação.
- Operação custosa de reverter mas inofensiva se mantida (ex.: certificado emitido) → ⚠️ aceitar como não-compensável e documentar.
- Side-effects irreversíveis (e-mail enviado, cobrança realizada) → exigem compensação semântica diferente (e-mail de correção, estorno).

Quando um passo é "irreversível na prática", a saga aceita isso e a compensação se limita aos recursos que importam. **Não é falha de design, é decisão consciente.**

### 5.4 Idempotência

Cada passo precisa ser idempotente, porque retry é certeza. Uma chamada `OAuthClient::create` chamada duas vezes deveria retornar o client existente, não criar dois. Operações que hoje sempre criam um novo registro precisam ganhar idempotency key antes de entrar em uma saga.

### 5.5 Observabilidade

Saga sem observabilidade vira pesadelo. Em qualquer implementação:

- Log estruturado por `saga_id` em cada passo e cada compensação.
- Métrica de "sagas em andamento", "sagas compensadas", "sagas falhadas sem compensação".
- Alerta quando uma compensação falha (cenário onde fica realmente inconsistente).

---

## 6. Resumo executivo

- **SAGA clássica** = sequência de transações locais multi-recurso com compensações reversas. Existe para resolver long-lived transactions onde ACID distribuído não cabe.
- **O que NÃO é SAGA**: job com `failed()`, pipeline assíncrono trivial, retry de webhook, transação de banco único.
- Casos minimalistas (pipeline linear curto com compensação direta) ainda **cabem na definição clássica** — são sagas pequenas, por orquestração ou coreografia, no modelo "1 pra 1".
- Os PoCs deste estudo usam um workflow genérico de 3 passos (`ReserveStock` → `ChargeCredit` → `ConfirmShipping`) para ilustrar o mecanismo de forma neutra e comparável entre as ferramentas avaliadas.
- **Antes de implementar**, definir: orquestração vs coreografia, onde mora o estado da saga, quais passos são compensáveis na prática, idempotência de cada passo, e observabilidade.
