# Compreensão de SAGA: o que é, o que não é, e onde nosso caso se encaixa

> Este documento existe porque, ao discutir a aplicação de SAGA na empresa, percebi que o termo está sendo usado de forma mais ampla do que a definição clássica. O objetivo aqui é separar três coisas:
>
> 1. O que é **SAGA na literatura** (Garcia-Molina/Salem 1987, Chris Richardson).
> 2. O que **não é SAGA** (e costuma ser confundido).
> 3. O que o **tech lead chama de SAGA** no nosso contexto e como o caso do `StoreController@activate` se encaixa (ou não) nisso.

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
| **Orquestração** | Um coordenador central comanda cada passo                | Centralizada no orquestrador  | Workflow do Temporal / `SagaOrchestrator` chama passo a passo                                            |

### Exemplo canônico (livro do Richardson)

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

## 3. O caso do `StoreController@activate` no marketplace-api

### 3.1 Como está hoje (síncrono, disparado pelo front)

Fluxo atual:

1. Job `CreateDomainChallenge@handle` cria zona DNS no Route53 e resolve o desafio ACME → status `CHALLENGE_CREATION_SUCCESS`.
2. Job `IssueDomainCertificate@handle` completa o desafio, pede o certificado, sobe os arquivos pro S3, apaga o registro de desafio do Route53 → status `CERTIFICATE_ISSUANCE_SUCCESS`.
3. **Front-end chama** `StoreController@activate`, que:
   - Acha o domínio com status `CERTIFICATE_ISSUANCE_SUCCESS`.
   - Chama `UsersRepository::enableManageClients` (HTTP M2M para `users-api`) habilitando a flag no usuário.
   - Chama `OAuthClientRepository::create` (HTTP para `users-api`) criando um OAuth client.
   - Persiste `oauth_client_id` e `oauth_client_secret` na `stores`.

O ponto fraco: a etapa 3 depende da decisão do front em chamar o endpoint. Se o front não chamar (usuário fechou aba, request perdido), a loja fica com certificado pronto mas sem OAuth client e flag — estado inconsistente entre `marketplace-api` e `users-api`.

### 3.2 O que o tech lead quer (proposta)

Remover o endpoint `activate` e fazer a ativação acontecer **automaticamente** ao fim do `IssueDomainCertificate@handle`, via fila. Ou seja:

- `IssueDomainCertificate` termina com sucesso → dispara um próximo job (ex.: `ActivateStore`) que executa o que hoje está no `activate`.
- Se algum passo da ativação falhar, executa-se uma **compensação** que reverte o que já foi feito (ex.: revogar/excluir o OAuth client criado, desligar a flag `allow_manage_clients`, opcionalmente revogar o certificado/limpar S3).

### 3.3 Isso é SAGA?

**Tecnicamente, sim — embora seja uma SAGA modesta.** Aqui está o porquê:

- Atravessa **dois recursos**: `marketplace-api` (banco local, S3, Route53) e `users-api` (banco remoto, via HTTP M2M).
- Não dá para colocar tudo numa transação ACID.
- Cada passo é uma transação local com efeito observável fora do serviço (criar OAuth client é um "commit" no `users-api`).
- Existe a necessidade de compensação semântica: se o passo final no `marketplace-api` falhar depois que o `users-api` já criou o OAuth client, esse client precisa ser excluído — senão fica órfão no `users-api`.

Mapeando para o vocabulário SAGA:

| Passo | Recurso          | Ação local                                                      | Compensação                                      |
| ----- | ---------------- | --------------------------------------------------------------- | ------------------------------------------------ |
| `T₁`  | Route53          | criar hosted zone, registrar desafio ACME                       | excluir hosted zone / registro de desafio        |
| `T₂`  | Banco local + S3 | emitir certificado, salvar arquivos no S3                       | revogar certificado ACME, excluir arquivos do S3 |
| `T₃`  | `users-api`      | habilitar `allow_manage_clients`                                | desabilitar a flag                               |
| `T₄`  | `users-api`      | criar OAuth client                                              | excluir OAuth client                             |
| `T₅`  | Banco local      | persistir `oauth_client_id` e `oauth_client_secret` em `stores` | apagar esses campos                              |

Se for orquestrada (um job coordenador chamando os passos) → **SAGA por orquestração**.
Se cada job publicar evento e o próximo escutar → **SAGA por coreografia**.

### 3.4 Por que o tech lead chama isso de "1 pra 1"

Provavelmente porque, na cabeça dele, a SAGA aqui é simples: **"se o passo seguinte falhar, desfaz o anterior"**, sem ramificações, sem paralelismo, sem múltiplos serviços simultâneos. É um pipeline linear de N passos com compensação em ordem reversa — exatamente o caso de livro do SAGA, só que enxuto.

A confusão semântica é entendível: em fóruns e no dia a dia, **"SAGA"** virou jargão guarda-chuva para "fluxo assíncrono multi-passo com tratamento de falha". A definição rigorosa exige os pontos da seção 1, mas a definição do tech lead **cabe dentro dela** — não é uso indevido, é uso minimalista.

### 3.5 O que NÃO seria SAGA nesse caso

- Trocar o endpoint por um job que só atualiza status localmente. Sem cruzar fronteira de serviço, sem compensação multi-recurso, é só pipeline.
- Confiar em retry infinito do `users-api` sem registrar estado de saga. Eventualmente "consistente" não é o mesmo que "sagado": SAGA exige que, ao desistir, o sistema **compense** o que já foi feito.

---

## 4. Casos reais (e fortes) de SAGA na literatura

Para calibrar a expectativa do que SAGA "merece" existir:

1. **Reserva de viagem**: voo + hotel + carro, cada um em fornecedor diferente. Falha no carro → cancela voo e hotel.
2. **E-commerce checkout**: criar pedido + reservar estoque + cobrar pagamento + agendar entrega. Falha na cobrança → libera estoque e cancela pedido.
3. **Onboarding de cliente em banco**: KYC + abertura de conta + emissão de cartão + cadastro em sistema antifraude. Falha no antifraude → fecha conta, cancela cartão.
4. **Pipeline de mídia**: upload → transcode → moderação → publicação. Falha na moderação → remove de CDN, apaga transcodes.
5. **Provisionamento multi-cloud**: criar VPC AWS + criar projeto GCP + registrar DNS + emitir certificado. Falha no certificado → desfaz tudo.

O nosso caso (ativação de loja) é parente do #5: provisionamento atravessando recursos heterogêneos (Route53, ACME/CA, S3, `users-api` OAuth). É menor em escala mas idêntico em natureza.

---

## 5. O que decidir antes de implementar

Independente do nome ("SAGA", "pipeline com compensação", "orquestração de ativação"), as decisões de design são as mesmas:

### 5.1 Orquestração ou coreografia?

Para um pipeline linear curto (5 passos, sem ramificação), **orquestração** é mais simples de raciocinar e debugar — um único lugar tem o "mapa" da saga. Coreografia compensa quando há múltiplos serviços reagindo em paralelo a eventos.

### 5.2 Onde mora o estado da saga?

A coluna `domains.status` já carrega parte disso (`CHALLENGE_CREATION_SUCCESS`, `CERTIFICATE_ISSUANCE_SUCCESS`, …). Para uma SAGA "de verdade", normalmente cria-se uma tabela `saga_state` com `(saga_id, current_step, completed_steps[], context_payload, status)` para permitir retomar de onde parou e saber o que compensar. Para um caso pequeno como este, dá pra estender o status do `Domain` + um `Store.activation_status` antes de criar tabela nova.

### 5.3 Quais passos são realmente compensáveis?

- OAuth client: dá pra excluir via API → ✅ compensável.
- Flag `allow_manage_clients`: depende de regra de negócio (se outro recurso depende dessa flag, não é seguro desligar) → ⚠️ revisar.
- Certificado ACME: revogação é possível mas custosa e raramente necessária (certificado órfão é inofensivo) → ⚠️ aceitar como não-compensável e documentar.
- Hosted zone Route53: igual ao certificado, costuma-se manter.

Quando um passo é "irreversível na prática", a saga aceita isso e a compensação se limita aos recursos que importam. **Não é falha de design, é decisão consciente.**

### 5.4 Idempotência

Cada passo precisa ser idempotente, porque retry é certeza. `OAuthClientRepository::create` chamado duas vezes deveria retornar o client existente, não criar dois. Hoje ele cria sempre — esse é um gap a fechar antes de mover pra fila.

### 5.5 Observabilidade

Saga sem observabilidade vira pesadelo. Em qualquer implementação:

- Log estruturado por `saga_id`/`domain_id` em cada passo e cada compensação.
- Métrica de "sagas em andamento", "sagas compensadas", "sagas falhadas sem compensação".
- Alerta quando uma compensação falha (cenário onde fica realmente inconsistente).

---

## 6. Resumo executivo

- **SAGA clássica** = sequência de transações locais multi-recurso com compensações reversas. Existe pra resolver long-lived transactions onde ACID distribuído não cabe.
- **O que NÃO é SAGA**: job com `failed()`, pipeline assíncrono trivial, retry de webhook, transação de banco único.
- **O que o tech lead chama de SAGA** no caso `StoreController@activate`: pipeline linear de ativação que atravessa `marketplace-api` e `users-api`, com compensação em ordem reversa. **Cabe na definição clássica** — é uma SAGA pequena, por orquestração, modelo "1 pra 1" (cada passo tem uma compensação direta).
- **O nosso caso é exemplo legítimo** de SAGA (parente de provisionamento multi-cloud), só que em escala modesta. Os PoCs com 3 passos genéricos (`ReserveStock` → `ChargeCredit` → `ConfirmShipping`) ilustram o mecanismo, mas o exemplo real do dia a dia da empresa é a ativação de loja.
- **Antes de implementar**, definir: orquestração vs coreografia, onde mora o estado da saga, quais passos são compensáveis na prática, idempotência de cada passo, e observabilidade.
