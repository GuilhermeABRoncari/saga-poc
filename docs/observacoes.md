# Observações

Notas pontuais que surgiram durante a análise mas que **não implicam decisão** ainda. Servem como registro de "já olhei, não esqueci, está aqui se alguém precisar".

---

## Variantes da imagem Docker `rabbitmq:4.3`

**Origem:** o guia [`integracao-rabbitmq-coreografado.md`](./integracao-rabbitmq-coreografado.md) Fase 1 sugere `rabbitmq:4.3-management-alpine` no Compose de dev. A pergunta natural é "quais outras tags existem para essa versão?" — análise feita em 2026-05-05 contra Docker Hub oficial.

**Link para inspeção direta (filtrado por 4.3):**
https://hub.docker.com/_/rabbitmq/tags?name=4.3

### Tags estáveis disponíveis (`4.3.0` / alias `4.3`)

| Tag                     | Base SO | Management plugin | Tamanho compr. aprox. |
| ----------------------- | ------- | ----------------- | --------------------- |
| `4.3`                   | Debian  | ❌                | ~107 MB               |
| `4.3-alpine`            | Alpine  | ❌                | ~68 MB                |
| `4.3-management`        | Debian  | ✅                | ~112 MB               |
| `4.3-management-alpine` | Alpine  | ✅                | ~80 MB                |

Também existem:

- **Tags fixas de patch:** `4.3.0`, `4.3.0-alpine`, `4.3.0-management`, `4.3.0-management-alpine` — equivalentes às acima mas pinadas no patch atual, não acompanham minor/patch novos automaticamente.
- **Release candidate:** `4.3-rc`, `4.3-rc-alpine`, `4.3-rc-management`, `4.3-rc-management-alpine` (e variantes `4.3.0-rc.1-*`) — só relevantes se for testar mudanças antes do release final.

Multi-arch: todas as variantes suportam `linux/amd64`, `linux/arm64/v8` e `linux/arm/v7` — não é critério de desempate entre tags.

### Eixos que diferenciam as tags

1. **Management plugin (`-management` vs sem sufixo)**

   - Com: habilita o Management UI em `:15672`, a Management HTTP API e — relevante para observabilidade — vem com o plugin `prometheus` já habilitado oficialmente.
   - Sem: imagem mínima, somente o broker AMQP em `:5672`. Para usar UI ou Prometheus, precisa estender a imagem (Dockerfile próprio chamando `rabbitmq-plugins enable …`) ou montar `enabled_plugins` via volume.

2. **Base SO (`-alpine` vs sem sufixo)**

   - **Alpine:** ~30-40% menor compressed, ciclo de patch de SO mais curto. Usa musl libc — historicamente estável com Erlang/OTP, mas é uma libc diferente da que upstream RabbitMQ usa nos seus benchmarks/CI principais.
   - **Debian (default):** base oficial referenciada na documentação RabbitMQ; glibc; mais "padrão" para troubleshooting (ferramentas como `apt`, shells maiores disponíveis). Trade-off é tamanho.

3. **Pinning de versão (`4.3` vs `4.3.0`)**
   - `4.3` acompanha patches futuros (4.3.1, 4.3.2…) automaticamente — bom em dev, arriscado em prod.
   - `4.3.0` fixa exatamente o patch atual — bom em prod, exige bump consciente.

### Como cada eixo bate no caso de uso atual (sem decidir)

- **Dev local (Compose):** os 4 eixos podem ir pra qualquer lado; é descartável e reproduzível. Já está em `4.3-management-alpine` por inércia.
- **Prod (K8s, Fase 6 do guia):** os eixos passam a importar — base SO afeta troubleshooting e diagnóstico em produção; pinning de patch afeta previsibilidade de upgrade; presença de `prometheus` afeta integração com stack de observabilidade existente.
- **Cluster multi-node com quorum queues:** ortogonal à escolha de tag — todas as variantes suportam quorum queues (feature do broker, não da imagem).

### Estado

Apenas observação. Decisão sobre qual tag usar em prod fica para o momento de fechar a Fase 6 do guia de integração — provavelmente em conjunto com a discussão de hardening (TLS, autenticação, definitions.json).
