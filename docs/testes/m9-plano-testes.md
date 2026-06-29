# Plano de Testes Manuais — Milestone M9: Comandos Auxiliares

> **⚠️ NOTA DE MIGRAÇÃO:** Este documento descreve a arquitetura original com Google Firestore como camada de persistência. A persistência foi **migrada para MariaDB**. As referências ao Firestore neste documento são **históricas** e refletem o estado na época da escrita. O componente `FirestoreService` foi substituído por `WalletStore` (Eloquent/MariaDB). As coleções `transactions`, `categories`, `labels` e `sessions` do Firestore correspondem agora às tabelas homônimas no MariaDB.

> **Projeto:** Wallet Track — bot Telegram de controle financeiro pessoal
> **Versão:** 1.0 — 2026-06-19
> **Escopo:** `/start`, `/help`, `/nova`, `/cancelar`, `/ultimos [n]`, `/categorias`, `/sync`, `transactions:sync-pending`; `GET /cron/sync-pending` *(substituído por `Schedule::command('transactions:sync-pending')` — esta é a abordagem atual)*
> **Referências:** `docs/02-especificacao-tecnica.md` §7, `docs/03-plano-testes-manuais.md` (CT-023 a CT-029, CT-033), `docs/04-clarificacoes.md` (decisões #5, #6, #7), `docs/06-plano-implementacao.md` §12

---

## 1. Ambiente de Teste

| Item | Valor |
|------|-------|
| **URL do webhook** | Cloud Run (staging) — ex: `https://wallet-track-xxx.a.run.app/webhook/telegram` |
| **Bot Telegram** | Bot de staging com token de desenvolvimento |
| **Planilha Sheets** | Planilha de staging (`GOOGLE_SHEETS_SPREADSHEET_ID` de dev) compartilhada com a Service Account |
| **Firestore** | Projeto GCP de staging; collections `transactions`, `sessions`, `categories`, `labels` populadas |
| **Cloud Scheduler** | Configurado no projeto de staging para `*/5 * * * *`; **ATUALIZADO:** agora chama `php artisan schedule:run` que dispara `transactions:sync-pending` via `Schedule::command` (substitui o endpoint HTTP `GET /cron/sync-pending`) |
| **chat_id do testador** | Whitelistado em `TELEGRAM_ALLOWED_CHAT_IDS` |
| **.env de staging** | `APP_ENV=staging`, `APP_DEBUG=true`, `SESSION_TIMEOUT_MINUTES=15`, `SYNC_MAX_RETRIES=3` |

### Pré-condições globais antes de iniciar a bateria

1. Bot de staging em execução (Cloud Run ou ngrok local) com webhook registrado
2. Planilha de staging vazia ou com dados de seed conhecidos
3. Firestore com as 9 categorias padrão (`firestore:seed-categories` executado)
4. Nenhuma sessão ativa para o chat_id do testador (Firestore: `sessions/{chat_id}` deletado se existir)
5. Testador logado no Telegram com o chat_id whitelistado
6. **DEPRECATED:** `CRON_SECRET_TOKEN` definido no `.env` para testes do endpoint `/cron/sync-pending` — o endpoint HTTP foi substituído por `Schedule::command('transactions:sync-pending')` em `routes/console.php`; o middleware `VerifyCronToken` foi removido

### Como resetar o ambiente entre testes

```bash
# 1. Deletar todas as transações do chat de teste no Firestore
#    (via Firebase Console ou script auxiliar)

# 2. Limpar sessão do testador
#    DELETE FROM firestore: sessions/{chat_id}

# 3. Limpar planilha (manter cabeçalhos, deletar linhas 2+)
#    Abrir planilha de staging → selecionar linhas de dados → deletar

# 4. Verificar que categorias de seed estão íntegras
php artisan firestore:seed-categories
```

---

## 2. Mapa de Cobertura

| CT | Comando / Feature | Tipo | Plano Original | Expandido aqui |
|----|-------------------|------|----------------|----------------|
| CT-023 | `/start` (M9.1) | Happy path | ✅ | — |
| CT-023a | `/start` em AWAITING_DATA | Estado | — | ✅ |
| CT-023b | `/start` em AWAITING_CONFIRMATION | Estado | — | ✅ |
| CT-023c | `/start` em AWAITING_EDITION | Estado | — | ✅ |
| CT-023d | `/start` com sessão expirada | Edge case | — | ✅ |
| CT-023e | `/start` idempotência (duas vezes) | Edge case | — | ✅ |
| CT-023f | `/start` reseta sessão → IDLE | Pós-condição | — | ✅ |
| CT-024 | `/help` (M9.2) — lista comandos | Happy path | ✅ | — |
| CT-024a | `/help` em estado não-IDLE | Estado | — | ✅ |
| CT-024b | `/help` — M9 final: todos ✅ | Regressão | — | ✅ |
| CT-024c | `/help` — formato e exemplos | Verificação | — | ✅ |
| CT-025 | `/nova` (M9.3) — wizard completo | Happy path | ✅ | — |
| CT-025a | `/nova` — Etapa 1: tipo inválido | Negativo | — | ✅ |
| CT-025b | `/nova` — Etapa 2: valor inválido | Negativo | — | ✅ |
| CT-025c | `/nova` — Etapa 2: valor zero | Negativo | — | ✅ |
| CT-025d | `/nova` — Etapa 3: descrição vazia/curta | Negativo | — | ✅ |
| CT-025e | `/nova` — Etapa 3: descrição > 500 chars | Edge case | — | ✅ |
| CT-025f | `/nova` — Etapa 4: selecionar categoria do keyboard | Happy path | — | ✅ |
| CT-025g | `/nova` — Etapa 4: digitar categoria nova | Alternativo | — | ✅ |
| CT-025h | `/nova` — Etapa 5: labels válidas | Happy path | — | ✅ |
| CT-025i | `/nova` — Etapa 5: "pular" labels | Alternativo | — | ✅ |
| CT-025j | `/nova` — Etapa 5: label inválida (< 2 chars) | Negativo | — | ✅ |
| CT-025k | `/nova` — atalho: linguagem natural pula wizard | Alternativo | — | ✅ |
| CT-025l | `/nova` durante AWAITING_CONFIRMATION | Integração | — | ✅ |
| CT-025m | `/nova` com /cancelar no meio | Fluxo cruzado | — | ✅ |
| CT-025n | `/nova` — timeout entre etapas (> 15 min) | Edge case | — | ✅ |
| CT-026 | `/cancelar` (M9.4) — todos os estados | Happy path | ✅ (subcasos) | — |
| CT-026a | `/cancelar` em IDLE → "Nada para cancelar" | Específico | — | ✅ |
| CT-026b | `/cancelar` em AWAITING_DATA | Específico | — | ✅ |
| CT-026c | `/cancelar` em AWAITING_CONFIRMATION | Específico | — | ✅ |
| CT-026d | `/cancelar` em AWAITING_EDITION | Específico | — | ✅ |
| CT-026e | `/cancelar` durante wizard `/nova` | Integração | — | ✅ |
| CT-027 | `/ultimos` (M9.5) — default 5 | Happy path | ✅ | — |
| CT-027a | `/ultimos` com 0 transações | Edge case | — | ✅ |
| CT-027b | `/ultimos` com só receitas | Variação | — | ✅ |
| CT-027c | `/ultimos` com só despesas | Variação | — | ✅ |
| CT-028 | `/ultimos [n]` — parâmetros | Happy path | ✅ | — |
| CT-028a | `/ultimos 0` → fallback 5 | Decisão #6 | — | ✅ |
| CT-028b | `/ultimos -3` → fallback 5 | Decisão #6 | — | ✅ |
| CT-028c | `/ultimos abc` → fallback 5 | Decisão #6 | — | ✅ |
| CT-028d | `/ultimos 999999` → cap 50 | Decisão #6 | — | ✅ |
| CT-028e | `/ultimos` com n > total (ex: 10 mas só 3) | Edge case | — | ✅ |
| CT-028f | `/ultimos` em estado não-IDLE | Estado | — | ✅ |
| CT-028g | `/ultimos` — verificação visual do formato | Visual | — | ✅ |
| CT-029 | `/categorias` (M9.6) — lista | Happy path | ✅ | — |
| CT-029a | `/categorias` — só defaults (9 categorias) | Variação | — | ✅ |
| CT-029b | `/categorias` — com categorias personalizadas | Variação | — | ✅ |
| CT-029c | `/categorias` — com contador de uso | Verificação | — | ✅ |
| CT-029d | `/categorias` — 0 personalizadas | Edge case | — | ✅ |
| CT-029e | `/categorias` — após criar categoria via `/nova` | Integração | — | ✅ |
| CT-029f | `/categorias` em estado não-IDLE | Estado | — | ✅ |
| CT-048 | `/sync` (M9.7) — sem pendentes | Happy path | — | ✅ |
| CT-049 | `/sync` — 1 pendente, sync ok | Happy path | — | ✅ |
| CT-050 | `/sync` — várias pendentes | Happy path | — | ✅ |
| CT-051 | `/sync` — reset de contador (após falhas) | Decisão #7 | — | ✅ |
| CT-052 | `/sync` — lock atômico (concorrência com cron) | Edge case | — | ✅ |
| CT-053 | `/sync` em estado não-IDLE | Estado | — | ✅ |
| CT-033a | Cron — 0 pendentes → no-op | Edge case | — | ✅ |
| CT-033b | Cron — 1 pendente → synced | Happy path (CT-033 original) | ✅ | — |
| CT-033c | Cron — 2 tentativas → 3ª tenta | Decisão #7 | — | ✅ |
| CT-033d | Cron — 3 falhas → `failed` + notificação | Decisão #7 | — | ✅ |
| CT-033e | Cron — `sync_attempts >= 3` → skip | Decisão #7 | — | ✅ |
| CT-033f | Cron — Sheets indisponível → incrementa | Resiliência | — | ✅ |
| CT-033g | Cron — batch de 20 pendentes | Edge case | — | ✅ |
| CT-054 | `GET /cron/sync-pending` — token válido → 200 *(substituído por Schedule::command — testes de endpoint HTTP mantidos como registro histórico)* | Happy path | — | ✅ |
| CT-055 | `GET /cron/sync-pending` — sem token → 401 *(substituído por Schedule::command — testes de endpoint HTTP mantidos como registro histórico)* | Segurança | — | ✅ |
| CT-056 | `GET /cron/sync-pending` — token inválido → 401 *(substituído por Schedule::command — testes de endpoint HTTP mantidos como registro histórico)* | Segurança | — | ✅ |
| CT-057 | `GET /cron/sync-pending` — resposta JSON válida *(substituído por Schedule::command — testes de endpoint HTTP mantidos como registro histórico)* | Verificação | — | ✅ |
| CT-058 | `/nova` durante AWAITING_CONFIRMATION — sessão anterior | Integração | — | ✅ |
| CT-059 | `/ultimos` após confirmar transação — aparece no topo | Integração | — | ✅ |
| CT-060 | `/categorias` após transação com nova categoria — uso incrementado | Integração | — | ✅ |
| CT-061 | `/sync` após restaurar Sheets — recupera pendentes | Integração | — | ✅ |
| CT-062 | Comandos com chat_id não-whitelistado → 403 | Segurança | — | ✅ |
| CT-063 | `/ultimos` com whitelist — chat_id isolado | Segurança | — | ✅ |
| CT-064 | `/categorias` com whitelist — chat_id isolado | Segurança | — | ✅ |

**Total de CTs:** 8 do plano original (CT-023 a CT-029, CT-033) + 66 expandidos/novos = **74 CTs para o M9**.

---

## 3. Casos de Teste

### 3.1 Funcionalidade: `/start` (M9.1)

> **Referência:** CT-023 (plano original), M9.1 do plano de implementação
> **Handler existente:** `App\Bot\Handlers\StartHandler` — envia mensagem de boas-vindas estática
> **⚠️ GAP conhecido:** o handler atual NÃO reseta a sessão para IDLE. A especificação M9.1 exige que `/start` resete a sessão. Ver CT-023f.

#### CT-023: `/start` — happy path (plano original)

**Funcionalidade:** `/start`
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- Estado do chat: IDLE
- Nenhuma sessão ativa no Firestore

**Passos:**
1. No Telegram, digitar `/start`
2. Aguardar resposta do bot

**Dados de entrada:** `/start`

**Resultado esperado:**
- Bot responde com mensagem de boas-vindas em PT-BR contendo:
  - Saudação "👋 **Olá! Sou o Wallet Track**"
  - Explicação dos 2 modos: texto livre e foto de nota fiscal
  - Exemplo de uso: *"Gastei R$ 45,90 no almoço de ontem"*
  - Referência ao comando `/help`
- Parse mode: HTML (tags `<b>`, `<i>` renderizadas corretamente no Telegram)
- Estado do chat permanece IDLE

**Pós-condição:** Estado = IDLE, nenhuma sessão criada.

---

#### CT-023a: `/start` em AWAITING_DATA

**Funcionalidade:** `/start`
**Tipo:** estado
**Prioridade:** alta

**Pré-condições:**
- Estado do chat: AWAITING_DATA (ex: usuário enviou "Paguei o almoço" sem valor e o bot perguntou "Qual o valor?")
- Sessão ativa no Firestore com `state = 'awaiting_data'`

**Passos:**
1. Provocar estado AWAITING_DATA: enviar `Paguei o almoço no restaurante`
2. Bot pergunta "Qual o valor?" — **não responder**
3. Digitar `/start`
4. Aguardar resposta

**Resultado esperado:**
- Bot responde com mensagem de boas-vindas (mesma do CT-023)
- Estado do chat volta para IDLE
- Sessão anterior é limpa (`sessions/{chat_id}` deletado do Firestore)
- Se o usuário enviar nova mensagem após `/start`, o fluxo começa do zero (nova extração)

**Pós-condição:** Estado = IDLE, sessão limpa, transação pendente descartada.

---

#### CT-023b: `/start` em AWAITING_CONFIRMATION

**Funcionalidade:** `/start`
**Tipo:** estado
**Prioridade:** alta

**Pré-condições:**
- Estado do chat: AWAITING_CONFIRMATION
- Sessão ativa com `state = 'awaiting_confirmation'`, `draft` populado, `message_id_confirm` definido

**Passos:**
1. Provocar estado AWAITING_CONFIRMATION: enviar `Paguei R$ 35,00 no cinema`
2. Bot mostra resumo + inline keyboard [Confirmar][Editar][Cancelar] — **não clicar em nada**
3. Digitar `/start`

**Resultado esperado:**
- Bot responde com mensagem de boas-vindas
- Estado → IDLE
- Sessão limpa (draft descartado)
- O inline keyboard anterior continua visível no chat (mensagem antiga), mas se o usuário clicar em Confirmar/Editar/Cancelar, o callback deve ser rejeitado (mensagem: "Operação já cancelada" ou "Sessão não encontrada")

**Pós-condição:** Estado = IDLE, transação NÃO gravada, sessão limpa.

---

#### CT-023c: `/start` em AWAITING_EDITION

**Funcionalidade:** `/start`
**Tipo:** estado
**Prioridade:** média

**Pré-condições:**
- Estado do chat: AWAITING_EDITION
- Sessão ativa com `state = 'awaiting_edition'`, campo `awaiting_field = 'amount'`

**Passos:**
1. Provocar AWAITING_EDITION: enviar `Gastei R$ 100,00 na farmácia` → clicar **Editar** → clicar **💵 Valor**
2. Bot pergunta "Qual o novo valor?" — **não responder**
3. Digitar `/start`

**Resultado esperado:**
- Boas-vindas exibidas
- Estado → IDLE
- Edição em andamento descartada

**Pós-condição:** Estado = IDLE, sessão limpa.

---

#### CT-023d: `/start` com sessão expirada

**Funcionalidade:** `/start`
**Tipo:** edge case
**Prioridade:** média

**Pré-condições:**
- Sessão com `updated_at` > 15 minutos atrás (simular alterando o timestamp no Firestore, ou aguardar 16 minutos)
- Estado nominal: qualquer não-IDLE

**Passos:**
1. Criar sessão expirada: enviar transação, aguardar 16 minutos, NÃO confirmar
2. Digitar `/start`

**Resultado esperado:**
- Bot NÃO deve mostrar mensagem de "Sessão expirada" — o `/start` simplesmente funciona
- Boas-vindas exibidas
- Sessão expirada é limpa silenciosamente
- Estado → IDLE

**Pós-condição:** Estado = IDLE.

---

#### CT-023e: `/start` idempotência

**Funcionalidade:** `/start`
**Tipo:** edge case
**Prioridade:** baixa

**Pré-condições:**
- Estado IDLE

**Passos:**
1. Digitar `/start`
2. Aguardar resposta
3. Digitar `/start` novamente
4. Aguardar resposta

**Resultado esperado:**
- Ambas as invocações retornam a mesma mensagem de boas-vindas
- Nenhum erro, nenhuma duplicação de sessão
- Estado permanece IDLE

**Pós-condição:** Estado = IDLE.

---

#### CT-023f: `/start` reseta sessão → IDLE (pós-condição)

**Funcionalidade:** `/start`
**Tipo:** pós-condição
**Prioridade:** alta

**Pré-condições:**
- Estado AWAITING_CONFIRMATION com draft válido (ex: `Paguei R$ 50,00 no mercado`)
- Firestore: `sessions/{chat_id}` existe com `state = 'awaiting_confirmation'`

**Passos:**
1. Verificar no Firebase Console que `sessions/{chat_id}` existe
2. Digitar `/start`
3. Verificar Firebase Console novamente

**Resultado esperado:**
- Após `/start`, o documento `sessions/{chat_id}` NÃO existe mais (foi deletado)
- Estado do chat = IDLE
- Se o usuário enviar uma nova transação em seguida, uma sessão NOVA é criada (sem resquícios da anterior)

**Pós-condição:** `sessions/{chat_id}` não existe no Firestore.

---

### 3.2 Funcionalidade: `/help` (M9.2)

> **Referência:** CT-024 (plano original), M9.2 do plano de implementação
> **Handler existente:** `App\Bot\Handlers\HelpHandler` — lista todos os 7 comandos planejados com ✅/⏳
> **⚠️ GAP conhecido:** o handler atual marca `/nova`, `/cancelar`, `/ultimos`, `/categorias`, `/sync` como ⏳ (em breve). No M9 final, TODOS devem ser ✅ (ativos). Ver CT-024b.

#### CT-024: `/help` — lista comandos (plano original)

**Funcionalidade:** `/help`
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- Estado IDLE

**Passos:**
1. Digitar `/help`

**Resultado esperado:**
- Bot responde com mensagem formatada em HTML contendo:
  - Título "🆘 **Comandos do Wallet Track**"
  - Lista de 7 comandos, cada um em uma linha com ícone de status e descrição
  - Legenda: ✅ ativo / ⏳ em breve
  - Dica final sobre linguagem natural

**Pós-condição:** Estado = IDLE.

---

#### CT-024a: `/help` em estado não-IDLE

**Funcionalidade:** `/help`
**Tipo:** estado
**Prioridade:** média

**Pré-condições:**
- Estado AWAITING_CONFIRMATION (provocar com `Paguei R$ 30,00 no uber`)

**Passos:**
1. Em AWAITING_CONFIRMATION, digitar `/help`
2. Verificar mensagem
3. Voltar ao inline keyboard anterior e clicar Confirmar

**Resultado esperado:**
- `/help` exibe a lista de comandos normalmente
- Estado NÃO é alterado pelo `/help` (permanece AWAITING_CONFIRMATION)
- O inline keyboard anterior continua funcional (clicar Confirmar grava a transação)

**Pós-condição:** Estado = AWAITING_CONFIRMATION (preservado), transação pode ser confirmada.

---

#### CT-024b: `/help` — M9 final: todos os comandos ativos

**Funcionalidade:** `/help`
**Tipo:** regressão
**Prioridade:** alta

**Pré-condições:**
- M9 totalmente implementado

**Passos:**
1. Digitar `/help`
2. Verificar cada linha de comando

**Resultado esperado:**
- `/start` → ✅ ativo
- `/help` → ✅ ativo
- `/nova` → ✅ ativo *(não ⏳)*
- `/cancelar` → ✅ ativo *(não ⏳)*
- `/ultimos [n]` → ✅ ativo *(não ⏳)*
- `/categorias` → ✅ ativo *(não ⏳)*
- `/sync` → ✅ ativo *(não ⏳)*

**Pós-condição:** N/A.

---

#### CT-024c: `/help` — verificação de formato e exemplos

**Funcionalidade:** `/help`
**Tipo:** verificação visual
**Prioridade:** baixa

**Passos:**
1. Digitar `/help`
2. Verificar visualmente:
   - Parse mode HTML funcionando (tags `<b>`, `<code>`, `<i>` renderizadas)
   - Emojis renderizados (🆘, ✅, ⏳, 💬)
   - Comandos dentro de `<code>` (fonte monoespaçada)
   - Exemplo de linguagem natural em `<i>` (itálico)
   - Sem quebras de linha estranhas ou escaping incorreto

**Resultado esperado:**
- Formatação limpa, legível, profissional
- Nenhum caractere de escape visível (ex: `&lt;` ao invés de `<`)

**Pós-condição:** N/A.

---

### 3.3 Funcionalidade: `/nova` — Wizard Passo a Passo (M9.3)

> **Referência:** CT-025 (plano original), decisão #5 das Clarificações
> **⚠️ NÃO IMPLEMENTADO:** handler `/nova` não existe. Deve ser criado seguindo a sequência da decisão #5.
>
> **Sequência do wizard (decisão #5):**
> 1. Tipo (despesa / receita)
> 2. Valor (R$ 50,00 ou 50.00)
> 3. Descrição (mín 2, máx 500 chars)
> 4. Categoria (inline keyboard com top categorias + "digitar outra")
> 5. Labels (separadas por vírgula, ou "pular")
>
> **Atalho:** a qualquer momento o usuário pode enviar descrição livre completa que o DeepSeek tenta extrair, pulando o wizard.

#### CT-025: `/nova` — wizard completo, todas as etapas válidas (plano original)

**Funcionalidade:** `/nova`
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- Estado IDLE
- Firestore com categorias padrão (9) + 2 personalizadas de testes anteriores: "Pet", "Hobbies"

**Passos:**
1. Digitar `/nova`
2. **Etapa 1 (Tipo):** bot pergunta "Qual o tipo da transação? (despesa / receita)" → responder `despesa`
3. **Etapa 2 (Valor):** bot pergunta "Qual o valor? Ex: `R$ 50,00` ou `50.00`" → responder `149,90`
4. **Etapa 3 (Descrição):** bot pergunta "Descreva a transação em poucas palavras:" → responder `Material de escritório`
5. **Etapa 4 (Categoria):** bot mostra inline keyboard com top categorias + botão "✏️ Digitar outra" → clicar **Alimentação** (ou categoria do keyboard)
6. **Etapa 5 (Labels):** bot pergunta "Quer adicionar labels? (separadas por vírgula, ou 'pular')" → responder `#escritorio, #material`
7. Bot mostra resumo final + inline keyboard [Confirmar][Editar][Cancelar]
8. Clicar **Confirmar**

**Dados de entrada:**
- Tipo: `despesa`
- Valor: `149,90`
- Descrição: `Material de escritório`
- Categoria: `Alimentação` (do keyboard)
- Labels: `#escritorio, #material`

**Resultado esperado:**
- Cada etapa avança corretamente para a próxima
- Resumo final exibe: Tipo=Despesa, Valor=R$ 149,90, Descrição="Material de escritório", Categoria=Alimentação, Labels=#escritorio #material
- Ao confirmar: "✅ Transação registrada!" + dados na planilha e Firestore

**Pós-condição:**
- Transação no Firestore: `type=expense`, `amount=149.90`, `description="Material de escritório"`, `category="Alimentação"`, `labels=["escritorio", "material"]`, `sync_status=synced`
- Linha na planilha Sheets com 9 colunas preenchidas
- Estado = IDLE, sessão limpa

---

#### CT-025a: `/nova` — Etapa 1: entrada inválida de tipo

**Funcionalidade:** `/nova`
**Tipo:** negativo
**Prioridade:** alta

**Pré-condições:**
- Estado IDLE

**Passos:**
1. Digitar `/nova`
2. Etapa 1: responder `ganho` (não é "despesa" nem "receita")
3. Aguardar
4. Responder `despezas` (erro de digitação)
5. Aguardar
6. Responder `` (vazio — apenas enviar mensagem sem texto, se possível, ou espaço)
7. Aguardar
8. Responder `despesa`

**Resultado esperado:**
- Passo 2 (`ganho`): bot rejeita e repete a pergunta. Mensagem: "Por favor, responda apenas **despesa** ou **receita**."
- Passo 4 (`despezas`): mesma rejeição
- Passo 6 (vazio): bot rejeita, pede input válido
- Passo 8 (`despesa`): aceito, avança para etapa 2 (Valor)
- Bot NÃO avança enquanto o tipo não for exatamente "despesa" ou "receita" (case-insensitive aceitável)

**Pós-condição:** Wizard na etapa 2 (Valor).

---

#### CT-025b: `/nova` — Etapa 2: valor inválido

**Funcionalidade:** `/nova`
**Tipo:** negativo
**Prioridade:** alta

**Pré-condições:**
- Wizard na etapa 2 (Valor), após tipo = `despesa`

**Passos:**
1. Responder `-50` (negativo)
2. Aguardar
3. Responder `caro demais` (texto não numérico)
4. Aguardar
5. Responder `R$ -30,00` (negativo formatado)
6. Aguardar
7. Responder `50`

**Resultado esperado:**
- Passo 1 (`-50`): rejeitado. Mensagem: "O valor da transação deve ser positivo. Use o formato `R$ 50,00` ou apenas `50,00`." (decisão #2)
- Passo 3 (`caro demais`): rejeitado. Mensagem pedindo um número.
- Passo 5 (`R$ -30,00`): rejeitado (valor negativo)
- Passo 7 (`50`): aceito como R$ 50,00. Avança para etapa 3.

**Pós-condição:** Wizard na etapa 3 (Descrição), valor = 50.00.

---

#### CT-025c: `/nova` — Etapa 2: valor zero

**Funcionalidade:** `/nova`
**Tipo:** negativo
**Prioridade:** alta

**Pré-condições:**
- Wizard na etapa 2 (Valor)

**Passos:**
1. Responder `0`
2. Aguardar
3. Responder `0,00`
4. Aguardar
5. Responder `R$ 0`

**Resultado esperado:**
- Todas as tentativas rejeitadas
- Mensagem: "O valor precisa ser maior que zero." (decisão #2)

**Pós-condição:** Wizard permanece na etapa 2.

---

#### CT-025d: `/nova` — Etapa 3: descrição vazia ou curta

**Funcionalidade:** `/nova`
**Tipo:** negativo
**Prioridade:** média

**Pré-condições:**
- Wizard na etapa 3 (Descrição)

**Passos:**
1. Responder `A` (1 caractere)
2. Aguardar
3. Responder `` (vazio)
4. Aguardar
5. Responder `Almoço no restaurante`

**Resultado esperado:**
- Passo 1 (`A`): rejeitado. Mensagem: "A descrição deve ter pelo menos 2 caracteres."
- Passo 3 (vazio): rejeitado
- Passo 5 (`Almoço no restaurante`): aceito. Avança para etapa 4.

**Pós-condição:** Wizard na etapa 4 (Categoria).

---

#### CT-025e: `/nova` — Etapa 3: descrição > 500 caracteres

**Funcionalidade:** `/nova`
**Tipo:** edge case
**Prioridade:** baixa

**Pré-condições:**
- Wizard na etapa 3 (Descrição)

**Passos:**
1. Enviar uma string com 600 caracteres (ex: "Lorem ipsum dolor sit amet, consectetur adipiscing elit. " repetido até atingir 600+)
2. Verificar comportamento

**Resultado esperado:**
- Descrição truncada em 497 caracteres + "..."
- Bot notifica: "Sua descrição foi resumida para 500 caracteres." (decisão #9)
- Avança para etapa 4 com a descrição truncada

**Dados de teste:**
```
Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. FINAL
```
(aproximadamente 600 caracteres)

**Pós-condição:** Descrição truncada em ~500 chars no Firestore.

---

#### CT-025f: `/nova` — Etapa 4: selecionar categoria do keyboard

**Funcionalidade:** `/nova`
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- Wizard na etapa 4 (Categoria)
- Firestore com categorias: Alimentação, Transporte, Moradia, Saúde, Educação, Lazer, Salário, Freelance, Outros

**Passos:**
1. Bot mostra mensagem "Qual a categoria?" + inline keyboard com top categorias (ex: 5-6 mais usadas) + botão "✏️ Digitar outra"
2. Clicar em **Transporte**

**Resultado esperado:**
- Bot confirma seleção (ex: "Categoria: **Transporte**")
- Avança para etapa 5 (Labels)
- Keyboard some ou é substituído

**Pós-condição:** Wizard na etapa 5, categoria = "Transporte".

---

#### CT-025g: `/nova` — Etapa 4: digitar categoria nova

**Funcionalidade:** `/nova`
**Tipo:** alternativo
**Prioridade:** alta

**Pré-condições:**
- Wizard na etapa 4 (Categoria)
- Categoria "Pet" NÃO existe no Firestore

**Passos:**
1. Na etapa 4, clicar **✏️ Digitar outra**
2. Bot pergunta "Qual a nova categoria?"
3. Responder `Pet`
4. Bot pergunta confirmação: "Criar categoria **Pet**? (sim / não)"
5. Responder `sim`

**Resultado esperado:**
- Categoria "Pet" criada no Firestore (`categories/pet` com `display_name="Pet"`, `is_default=false`, `use_count=0`)
- Wizard avança para etapa 5
- `/categorias` posterior inclui "Pet"

**Pós-condição:**
- Firestore: `categories/pet` existe
- Wizard na etapa 5, categoria = "Pet"

---

#### CT-025h: `/nova` — Etapa 5: labels válidas

**Funcionalidade:** `/nova`
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- Wizard na etapa 5 (Labels)

**Passos:**
1. Bot pergunta "Quer adicionar labels? (separadas por vírgula, ou 'pular')"
2. Responder `#ifood, #japones, #domingo`

**Resultado esperado:**
- Labels parseadas: `["ifood", "japones", "domingo"]`
- Bot mostra resumo final incluindo as labels
- Avança para confirmação

**Pós-condição:** Wizard concluído, resumo exibido, estado = AWAITING_CONFIRMATION.

---

#### CT-025i: `/nova` — Etapa 5: "pular" labels

**Funcionalidade:** `/nova`
**Tipo:** alternativo
**Prioridade:** média

**Pré-condições:**
- Wizard na etapa 5 (Labels)

**Passos:**
1. Responder `pular`

**Resultado esperado:**
- Labels = array vazio `[]`
- Bot NÃO inclui seção de labels no resumo
- Avança para confirmação

**Pós-condição:** Resumo sem labels, estado = AWAITING_CONFIRMATION.

---

#### CT-025j: `/nova` — Etapa 5: label inválida

**Funcionalidade:** `/nova`
**Tipo:** negativo
**Prioridade:** média

**Pré-condições:**
- Wizard na etapa 5 (Labels)

**Passos:**
1. Responder `a, #ok` (label "a" tem 1 caractere)
2. Aguardar
3. Responder `#ok, #ok` (duplicada)

**Resultado esperado:**
- Passo 1: bot rejeita ou alerta que "a" é muito curta (mín 2 caracteres após trim). Pode aceitar "#ok" e ignorar "a", ou pedir para corrigir.
- Passo 3: labels duplicadas são deduplicadas (apenas uma "#ok" no resumo)

**Pós-condição:** Labels válidas aceitas, inválidas ignoradas ou rejeitadas.

---

#### CT-025k: `/nova` — atalho: linguagem natural pula o wizard

**Funcionalidade:** `/nova`
**Tipo:** alternativo
**Prioridade:** alta

**Pré-condições:**
- Estado IDLE (ou durante o wizard — decisão #5: "a qualquer momento")

**Passos:**
1. Digitar `/nova`
2. Na etapa 1 (Tipo), em vez de responder "despesa" ou "receita", digitar: `Paguei R$ 87,50 no supermercado ontem`
3. Aguardar processamento

**Resultado esperado:**
- Bot detecta que o input é uma descrição completa (não é "despesa"/"receita")
- Envia o texto para o DeepSeek para extração
- Se DeepSeek extrair com sucesso: mostra resumo + inline keyboard de confirmação (wizard é pulado)
- Se DeepSeek falhar: volta para o wizard na etapa apropriada
- Comportamento equivalente a enviar a mesma mensagem em IDLE (fluxo de texto natural)

**Dados de entrada:** `Paguei R$ 87,50 no supermercado ontem`

**Resultado esperado (extração bem-sucedida):**
- Tipo: despesa
- Valor: 87,50
- Descrição: supermercado
- Data: ontem
- Categoria sugerida: Alimentação
- Estado → AWAITING_CONFIRMATION

**Pós-condição:** Transação pronta para confirmar, wizard abortado.

---

#### CT-025l: `/nova` durante AWAITING_CONFIRMATION

**Funcionalidade:** `/nova`
**Tipo:** integração
**Prioridade:** alta

**Pré-condições:**
- Estado AWAITING_CONFIRMATION (transação pendente: `Paguei R$ 30,00 no uber`)
- Sessão ativa com draft

**Passos:**
1. Em AWAITING_CONFIRMATION, digitar `/nova`
2. Completar o wizard com dados DIFERENTES (ex: tipo=receita, valor=5000, desc="Salário")
3. Confirmar a nova transação

**Resultado esperado:**
- `/nova` inicia o wizard do zero
- A sessão anterior (transação do uber) é descartada (sessão limpa/sobrescrita)
- A transação do uber NÃO é gravada
- A nova transação (salário) é gravada corretamente

**Pós-condição:**
- 1 transação no Firestore (Salário, receita, R$ 5000,00)
- 0 transações do uber
- Estado = IDLE

---

#### CT-025m: `/nova` com `/cancelar` no meio do wizard

**Funcionalidade:** `/nova`
**Tipo:** fluxo cruzado
**Prioridade:** alta

**Pré-condições:**
- Estado IDLE

**Passos:**
1. Digitar `/nova`
2. Etapa 1: responder `despesa`
3. Etapa 2: responder `100`
4. Etapa 3: **digitar `/cancelar`** (em vez da descrição)

**Resultado esperado:**
- Bot cancela o wizard
- Mensagem: "🚫 Transação cancelada. Você pode começar de novo quando quiser — é só me mandar uma mensagem."
- Estado → IDLE
- Nenhum dado parcial do wizard é persistido

**Repetir o teste cancelando em outras etapas:**
- Cancelar na etapa 1 (antes de responder tipo)
- Cancelar na etapa 4 (antes de escolher categoria)
- Cancelar na etapa 5 (antes de responder labels)

**Resultado esperado (todos):** Wizard cancelado, IDLE, nada persistido.

**Pós-condição:** Estado = IDLE, Firestore sem transação parcial.

---

#### CT-025n: `/nova` — timeout entre etapas (> 15 minutos)

**Funcionalidade:** `/nova`
**Tipo:** edge case
**Prioridade:** média

**Pré-condições:**
- Estado IDLE
- `SESSION_TIMEOUT_MINUTES=15` (ou ajustar para 1 minuto em staging para acelerar o teste)

**Passos:**
1. Digitar `/nova`
2. Etapa 1: responder `despesa`
3. Etapa 2: responder `50`
4. **Aguardar 16 minutos** (ou o tempo configurado + 1 min)
5. Etapa 3: responder `Teste timeout`

**Resultado esperado:**
- Após o timeout, a sessão do wizard expira
- Ao tentar continuar na etapa 3, bot informa "Sua sessão expirou (15 min sem interação)..."
- Estado → IDLE
- Nenhum dado parcial gravado

**Pós-condição:** Estado = IDLE, sessão limpa.

---

### 3.4 Funcionalidade: `/cancelar` (M9.4)

> **Referência:** CT-026 (plano original, com 4 subcasos)
> **Handler existente:** `App\Bot\Handlers\CancelarHandler` — sempre chama `clearSession()` + `notifyCancelled()`
> **⚠️ GAP conhecido:** o handler atual NÃO verifica se há sessão ativa. Em IDLE, ele envia "🚫 Transação cancelada..." em vez de "Nada para cancelar" (requisito do CT-026 subcaso d). Isso precisa ser corrigido na implementação.

#### CT-026a: `/cancelar` em IDLE → "Nada para cancelar"

**Funcionalidade:** `/cancelar`
**Tipo:** estado específico
**Prioridade:** alta

**Pré-condições:**
- Estado IDLE
- Nenhuma sessão ativa no Firestore (`sessions/{chat_id}` não existe)

**Passos:**
1. Digitar `/cancelar`

**Resultado esperado:**
- Bot responde com mensagem indicando que não há nada para cancelar
- Texto esperado: "Não há nada para cancelar no momento. Você está no início — é só me mandar uma mensagem para começar." (ou similar)
- Estado permanece IDLE
- **NÃO** deve enviar "🚫 Transação cancelada" (essa mensagem é para quando há uma transação em andamento)

**Pós-condição:** Estado = IDLE, sem alterações.

---

#### CT-026b: `/cancelar` em AWAITING_DATA

**Funcionalidade:** `/cancelar`
**Tipo:** estado específico
**Prioridade:** alta

**Pré-condições:**
- Estado AWAITING_DATA (ex: bot perguntando "Qual o valor?")

**Passos:**
1. Provocar AWAITING_DATA: enviar `Paguei o almoço`
2. Bot pergunta "Qual o valor?" — **não responder**
3. Digitar `/cancelar`

**Resultado esperado:**
- Bot confirma cancelamento: "🚫 Transação cancelada. Você pode começar de novo quando quiser — é só me mandar uma mensagem."
- Estado → IDLE
- Sessão limpa
- Pergunta anterior ("Qual o valor?") fica no chat mas é inócua — se o usuário responder depois, o bot deve processar como nova transação

**Pós-condição:** Estado = IDLE, sessão deletada.

---

#### CT-026c: `/cancelar` em AWAITING_CONFIRMATION

**Funcionalidade:** `/cancelar`
**Tipo:** estado específico
**Prioridade:** alta

**Pré-condições:**
- Estado AWAITING_CONFIRMATION (resumo + inline keyboard visível)

**Passos:**
1. Enviar `Paguei R$ 99,00 na farmácia`
2. Bot mostra resumo + [Confirmar][Editar][Cancelar] — **não clicar nos botões**
3. Digitar `/cancelar` (comando via texto, não o botão Cancelar do keyboard)
4. Tentar clicar **Confirmar** no inline keyboard antigo

**Resultado esperado:**
- Passo 3: bot confirma cancelamento. "🚫 Transação cancelada..."
- Estado → IDLE, sessão limpa
- Passo 4: callback rejeitado. Toast: "Operação já cancelada" (ou CT-047: callback de sessão antiga rejeitado)

**Pós-condição:** Estado = IDLE, transação NÃO gravada.

---

#### CT-026d: `/cancelar` em AWAITING_EDITION

**Funcionalidade:** `/cancelar`
**Tipo:** estado específico
**Prioridade:** média

**Pré-condições:**
- Estado AWAITING_EDITION (usuário está editando um campo)

**Passos:**
1. Enviar `Gastei R$ 200,00 no shopping` → clicar **Editar** → clicar **💵 Valor**
2. Bot pergunta "Qual o novo valor?" — **não responder**
3. Digitar `/cancelar`

**Resultado esperado:**
- Cancelamento confirmado
- Estado → IDLE
- Edição descartada; transação original também descartada (não fica pendente)

**Pós-condição:** Estado = IDLE, nenhuma transação pendente.

---

#### CT-026e: `/cancelar` durante wizard `/nova`

**Funcionalidade:** `/cancelar`
**Tipo:** integração
**Prioridade:** alta

**Pré-condições:**
- Wizard `/nova` em andamento (qualquer etapa)

**Passos:**
1. Digitar `/nova`
2. Etapa 1: responder `despesa`
3. Digitar `/cancelar`

**Resultado esperado:**
- Wizard cancelado
- Estado → IDLE
- Nenhum dado parcial do wizard gravado

**Repetir nas etapas 3, 4, 5:**

**Pós-condição:** Estado = IDLE, sem transação parcial.

---

### 3.5 Funcionalidade: `/ultimos [n]` (M9.5)

> **Referência:** CT-027, CT-028 (plano original), decisão #6 das Clarificações
> **⚠️ NÃO IMPLEMENTADO:** handler `/ultimos` não existe. Deve usar `FirestoreService::listRecent()`.
>
> **Regra da decisão #6:**
> ```php
> $n = intval($param);
> if ($n < 1 || $n > 50) { $n = 5; }  // fallback silencioso
> $n = min($n, $totalTransactions);     // clamp no total disponível
> ```

#### CT-027: `/ultimos` — default 5 transações (plano original)

**Funcionalidade:** `/ultimos`
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- Firestore com pelo menos 5 transações para o chat_id do testador, com datas variadas

**Passos:**
1. Digitar `/ultimos`

**Dados de entrada:** `/ultimos` (sem parâmetro)

**Resultado esperado:**
- Bot responde com as 5 transações mais recentes
- Ordenadas por data decrescente (mais recente primeiro)
- Cada transação mostra: Data, Descrição, Valor, Tipo (Despesa/Receita), Categoria, Labels (se houver)
- Formato tabular ou lista legível em PT-BR

**Pós-condição:** Estado inalterado.

---

#### CT-027a: `/ultimos` com 0 transações

**Funcionalidade:** `/ultimos`
**Tipo:** edge case
**Prioridade:** alta

**Pré-condições:**
- Firestore: **zero** transações para o chat_id do testador
- Limpar todas as transações do testador antes do teste

**Passos:**
1. Digitar `/ultimos`

**Resultado esperado:**
- Bot responde: "📭 Nenhuma transação registrada ainda."
- Ou similar, indicando ausência de dados
- Sem erro, sem mensagem vazia

**Pós-condição:** Estado inalterado.

---

#### CT-027b: `/ultimos` com apenas receitas

**Funcionalidade:** `/ultimos`
**Tipo:** variação
**Prioridade:** média

**Pré-condições:**
- Firestore: 3 transações, todas `type=income` para o chat_id do testador

**Passos:**
1. Digitar `/ultimos`

**Resultado esperado:**
- Lista 3 transações, todas exibindo Tipo = **Receita**
- Valores em verde ou sem indicador de despesa

**Pós-condição:** Estado inalterado.

---

#### CT-027c: `/ultimos` com apenas despesas

**Funcionalidade:** `/ultimos`
**Tipo:** variação
**Prioridade:** média

**Pré-condições:**
- Firestore: 3 transações, todas `type=expense`

**Passos:**
1. Digitar `/ultimos`

**Resultado esperado:**
- Lista 3 transações, todas Tipo = **Despesa**

**Pós-condição:** Estado inalterado.

---

#### CT-028: `/ultimos [n]` — parâmetros válidos (plano original)

**Funcionalidade:** `/ultimos [n]`
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- Pelo menos 12 transações no Firestore para o chat_id

**Passos:**
1. Digitar `/ultimos 10` → verificar 10 transações
2. Digitar `/ultimos 3` → verificar 3 transações

**Resultado esperado:**
- Passo 1: exatamente 10 transações listadas
- Passo 2: exatamente 3 transações listadas

**Pós-condição:** Estado inalterado.

---

#### CT-028a: `/ultimos 0` → fallback 5

**Funcionalidade:** `/ultimos [n]`
**Tipo:** decisão #6
**Prioridade:** alta

**Passos:**
1. Digitar `/ultimos 0`

**Resultado esperado:**
- **Não** retorna lista vazia nem erro
- Fallback silencioso para 5 transações (decisão #6: `$n < 1 → $n = 5`)
- Nenhuma mensagem avisando sobre o fallback (é "silencioso")

**Pós-condição:** Estado inalterado.

---

#### CT-028b: `/ultimos -3` → fallback 5

**Funcionalidade:** `/ultimos [n]`
**Tipo:** decisão #6
**Prioridade:** alta

**Passos:**
1. Digitar `/ultimos -3`

**Resultado esperado:**
- Fallback silencioso para 5 transações
- Nenhum erro ou mensagem sobre valor negativo

**Pós-condição:** Estado inalterado.

---

#### CT-028c: `/ultimos abc` → fallback 5

**Funcionalidade:** `/ultimos [n]`
**Tipo:** decisão #6
**Prioridade:** alta

**Passos:**
1. Digitar `/ultimos abc`

**Resultado esperado:**
- `intval("abc")` = 0 → `$n < 1` → fallback 5
- Nenhum erro "parâmetro inválido" — fallback silencioso

**Pós-condição:** Estado inalterado.

---

#### CT-028d: `/ultimos 999999` → cap em 50

**Funcionalidade:** `/ultimos [n]`
**Tipo:** decisão #6
**Prioridade:** alta

**Pré-condições:**
- Pelo menos 50 transações no Firestore para o chat_id do testador

**Passos:**
1. Digitar `/ultimos 999999`

**Resultado esperado:**
- Cap em 50 (máximo definido na decisão #6: `$n > 50 → $n = 5`? Não: a regra é `if ($n < 1 || $n > 50) { $n = 5; }`)
- **Correção:** de acordo com a decisão #6, `999999 > 50` → `$n = 5` (fallback), não 50.
- ⚠️ **Verificar com o implementador:** a decisão #6 diz que > 50 vira 5 (fallback) ou cap em 50? A tabela na decisão mostra `/ultimos 999999` → 50 (cap), mas o código mostra `$n > 50 → $n = 5`. Isso precisa ser clarificado. O comportamento esperado MAIS PROVÁVEL é cap em 50, não fallback para 5. **Marque como [AMBIGUOUS — NEEDS CLARIFICATION] se o implementador seguir o código literal.**

**Resultado esperado (interpretação corrigida):**
- `/ultimos 999999` → lista no máximo 50 transações (cap, não fallback)

**Pós-condição:** Estado inalterado.

---

#### CT-028e: `/ultimos` com n > total disponível

**Funcionalidade:** `/ultimos [n]`
**Tipo:** edge case
**Prioridade:** média

**Pré-condições:**
- Firestore: apenas 3 transações para o chat_id

**Passos:**
1. Digitar `/ultimos 10`

**Resultado esperado:**
- `$n = min(10, 3)` = 3 → lista 3 transações
- Nenhum erro, nenhuma indicação de que pediu mais do que existe

**Pós-condição:** Estado inalterado.

---

#### CT-028f: `/ultimos` em estado não-IDLE

**Funcionalidade:** `/ultimos [n]`
**Tipo:** estado
**Prioridade:** média

**Pré-condições:**
- Estado AWAITING_CONFIRMATION (transação pendente)

**Passos:**
1. Provocar AWAITING_CONFIRMATION: enviar `Paguei R$ 45,00 no almoço`
2. Com o resumo + keyboard visível, digitar `/ultimos 3`

**Resultado esperado:**
- `/ultimos` executa normalmente (lista 3 transações)
- Estado NÃO é alterado (permanece AWAITING_CONFIRMATION)
- A transação pendente continua disponível para confirmar/editar/cancelar

**Pós-condição:** Estado = AWAITING_CONFIRMATION preservado.

---

#### CT-028g: `/ultimos` — verificação visual do formato

**Funcionalidade:** `/ultimos [n]`
**Tipo:** visual
**Prioridade:** baixa

**Pré-condições:**
- Firestore com transações de tipos variados (despesa e receita, com e sem labels)

**Passos:**
1. Digitar `/ultimos 5`
2. Verificar visualmente:
   - Cada transação em linha separada ou bloco distinto
   - Data no formato `DD/MM/AAAA`
   - Valor no formato `R$ X.XXX,XX`
   - Tipo indicado como **Despesa** ou **Receita**
   - Labels prefixadas com `#`
   - Transações ordenadas da mais recente para a mais antiga
   - Parse mode HTML funcionando (negrito, emojis se usados)

**Resultado esperado:**
- Formatação consistente com o padrão brasileiro
- Fácil leitura no celular (mensagem não é muito larga)

**Pós-condição:** Estado inalterado.

---

### 3.6 Funcionalidade: `/categorias` (M9.6)

> **Referência:** CT-029 (plano original)
> **⚠️ NÃO IMPLEMENTADO:** handler `/categorias` não existe. Deve usar `FirestoreService::getCategories()`.

#### CT-029: `/categorias` — lista completa (plano original)

**Funcionalidade:** `/categorias`
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- Firestore: 9 categorias padrão seedadas
- Pelo menos 2 categorias personalizadas criadas em testes anteriores (ex: "Pet", "Hobbies")

**Passos:**
1. Digitar `/categorias`

**Resultado esperado:**
- Bot lista todas as categorias (padrão + personalizadas)
- Cada categoria mostra: nome e contador de uso (ex: "Alimentação (12)")
- Categorias ordenadas (ex: alfabeticamente ou por uso)
- Distinção visual entre padrão e personalizada (opcional, ex: 🏷 para padrão, ✏️ para personalizada)

**Pós-condição:** Estado inalterado.

---

#### CT-029a: `/categorias` — apenas defaults (9 categorias)

**Funcionalidade:** `/categorias`
**Tipo:** variação
**Prioridade:** média

**Pré-condições:**
- Firestore: apenas as 9 categorias padrão (executar `firestore:seed-categories` para resetar)
- Nenhuma categoria personalizada

**Passos:**
1. Digitar `/categorias`

**Resultado esperado:**
- Lista exatamente 9 categorias:
  - Alimentação
  - Transporte
  - Moradia
  - Saúde
  - Educação
  - Lazer
  - Salário
  - Freelance
  - Outros
- Contadores zerados ou refletindo uso real de testes

**Pós-condição:** Estado inalterado.

---

#### CT-029b: `/categorias` — com categorias personalizadas

**Funcionalidade:** `/categorias`
**Tipo:** variação
**Prioridade:** média

**Pré-condições:**
- Firestore: 9 padrão + 3 personalizadas: "Pet", "Hobbies", "Investimentos"

**Passos:**
1. Digitar `/categorias`

**Resultado esperado:**
- Lista 12 categorias (9 padrão + 3 personalizadas)
- Categorias personalizadas visivelmente identificáveis (ex: sem ícone de padrão, ou marcadas como "personalizada")

**Pós-condição:** Estado inalterado.

---

#### CT-029c: `/categorias` — com contador de uso

**Funcionalidade:** `/categorias`
**Tipo:** verificação
**Prioridade:** alta

**Pré-condições:**
- Firestore com transações que usam categorias específicas múltiplas vezes:
  - Alimentação: 5 transações
  - Transporte: 3 transações
  - Pet: 1 transação

**Passos:**
1. Digitar `/categorias`
2. Verificar os números ao lado de cada categoria

**Resultado esperado:**
- Alimentação exibe `(5)`
- Transporte exibe `(3)`
- Pet exibe `(1)`
- Categorias sem uso exibem `(0)` ou o contador é omitido

**Pós-condição:** Estado inalterado.

---

#### CT-029d: `/categorias` — 0 personalizadas

**Funcionalidade:** `/categorias`
**Tipo:** edge case
**Prioridade:** baixa

**Pré-condições:**
- Apenas categorias padrão, nenhuma personalizada

**Passos:**
1. Digitar `/categorias`

**Resultado esperado:**
- Se o bot agrupa por "Padrão" e "Personalizadas", a seção "Personalizadas" mostra "Nenhuma categoria personalizada" ou é omitida
- Sem erro

**Pós-condição:** Estado inalterado.

---

#### CT-029e: `/categorias` — após criar categoria via `/nova`

**Funcionalidade:** `/categorias`
**Tipo:** integração
**Prioridade:** alta

**Pré-condições:**
- Estado IDLE

**Passos:**
1. Digitar `/categorias` — anotar a lista atual (sem "Academia")
2. Usar `/nova` para criar transação com categoria NOVA "Academia" (digitar na etapa 4)
3. Confirmar transação
4. Digitar `/categorias` novamente

**Resultado esperado:**
- Passo 4: "Academia" aparece na lista de categorias
- Contador de uso = 1 para "Academia"

**Pós-condição:** Categoria "Academia" existe no Firestore com `use_count=1`.

---

#### CT-029f: `/categorias` em estado não-IDLE

**Funcionalidade:** `/categorias`
**Tipo:** estado
**Prioridade:** baixa

**Pré-condições:**
- Estado AWAITING_DATA

**Passos:**
1. Provocar AWAITING_DATA
2. Digitar `/categorias`
3. Continuar o fluxo anterior (responder à pergunta pendente)

**Resultado esperado:**
- `/categorias` executa normalmente
- Estado não é alterado
- O fluxo pendente continua funcional

**Pós-condição:** Estado = AWAITING_DATA preservado.

---

### 3.7 Funcionalidade: `/sync` (M9.7)

> **Referência:** Decisão #7 das Clarificações
> **⚠️ NÃO IMPLEMENTADO:** handler `/sync` não existe. Deve disparar o comando `transactions:sync-pending` e resetar `sync_attempts`.
>
> **Comportamento esperado (decisão #7):**
> - Query Firestore por `sync_status='pending'` AND `sync_attempts < 3`
> - Para cada: tenta `SheetsService::appendTransaction()`
> - Sucesso → `sync_status='synced'`
> - Falha → `sync_attempts += 1`
> - `/sync` manual: reseta `sync_attempts = 0` antes de tentar
> - Lock atômico para evitar conflito com cron

#### CT-048: `/sync` — sem transações pendentes

**Funcionalidade:** `/sync`
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- Firestore: todas as transações do chat_id com `sync_status = 'synced'`
- Nenhuma transação `pending`

**Passos:**
1. Digitar `/sync`

**Resultado esperado:**
- Bot responde: "✅ Nenhuma transação pendente. Tudo sincronizado!" (ou similar)
- Nenhum erro
- Nenhuma chamada desnecessária à Sheets API

**Pós-condição:** Estado inalterado, nenhuma alteração no Firestore.

---

#### CT-049: `/sync` — 1 transação pendente, sync bem-sucedido

**Funcionalidade:** `/sync`
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- 1 transação no Firestore com `sync_status = 'pending'`, `sync_attempts = 0`
- Sheets está acessível (planilha compartilhada)

**Passos:**
1. Criar pendente: simular falha no Sheets (revogar acesso temporariamente) → registrar transação → restaurar acesso
2. Digitar `/sync`
3. Verificar planilha Sheets
4. Verificar Firestore

**Resultado esperado:**
- Passo 2: bot responde "✅ 1 transação sincronizada com a planilha!"
- Passo 3: transação aparece na planilha com as 9 colunas
- Passo 4: `sync_status = 'synced'`, `sync_attempts = 1` (incrementado após sucesso)

**Pós-condição:** Transação synced, planilha atualizada.

---

#### CT-050: `/sync` — várias transações pendentes

**Funcionalidade:** `/sync`
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- 5 transações com `sync_status = 'pending'`, `sync_attempts = 0`
- Sheets acessível

**Passos:**
1. Digitar `/sync`

**Resultado esperado:**
- Bot responde: "✅ 5 transações sincronizadas com a planilha!"
- Todas as 5 aparecem na planilha
- Todas com `sync_status = 'synced'` no Firestore

**Pós-condição:** 5 transações synced.

---

#### CT-051: `/sync` — reset de contador (decisão #7)

**Funcionalidade:** `/sync`
**Tipo:** decisão #7
**Prioridade:** alta

**Pré-condições:**
- 1 transação com `sync_status = 'pending'`, `sync_attempts = 2` (já falhou 2 vezes via cron)
- Sheets agora está acessível

**Passos:**
1. Digitar `/sync`

**Resultado esperado:**
- `/sync` reseta `sync_attempts = 0` para a transação
- Tenta sincronizar → sucesso → `sync_status = 'synced'`, `sync_attempts = 1`
- Se `/sync` não resetasse, a transação teria `sync_attempts = 3` e seria marcada como `failed` na próxima tentativa

**Pós-condição:** Transação synced com contador resetado. Prova de que o reset funciona: a transação não foi para `failed`.

---

#### CT-052: `/sync` — lock atômico (concorrência com cron)

**Funcionalidade:** `/sync`
**Tipo:** edge case
**Prioridade:** alta

**Pré-condições:**
- Cloud Scheduler ativo (cron a cada 5 min)
- 10 transações pendentes

**Passos:**
1. Digitar `/sync` manualmente
2. Imediatamente (ou simultaneamente) disparar o cron endpoint:
   `# DEPRECATED: o endpoint HTTP foi substituído por Schedule::command. Use: php artisan transactions:sync-pending`
   `curl -H "X-Cron-Token: <secret>" https://<staging>/cron/sync-pending`

**Resultado esperado:**
- Apenas UMA das execuções processa cada transação (lock atômico)
- Nenhuma transação aparece duplicada na planilha
- Nenhuma transação tem `sync_attempts` incrementado 2 vezes para a mesma tentativa
- Nenhum erro 500 em nenhuma das chamadas

**Pós-condição:** Todas as transações synced, sem duplicação.

---

#### CT-053: `/sync` em estado não-IDLE

**Funcionalidade:** `/sync`
**Tipo:** estado
**Prioridade:** baixa

**Pré-condições:**
- Estado AWAITING_CONFIRMATION
- 1 transação pendente de sync

**Passos:**
1. Provocar AWAITING_CONFIRMATION
2. Digitar `/sync`

**Resultado esperado:**
- `/sync` executa independentemente do estado da conversa
- Estado da conversa NÃO é alterado
- Transação pendente continua disponível para confirmar

**Pós-condição:** Estado = AWAITING_CONFIRMATION preservado; transações pendentes synced.

---

### 3.8 Funcionalidade: `transactions:sync-pending` (M9.8)

> **Referência:** CT-033 (plano original), decisão #7 das Clarificações
> **⚠️ NÃO IMPLEMENTADO:** comando artisan `transactions:sync-pending` não existe.
>
> **Comportamento (decisão #7):**
> - Query: `sync_status='pending'` AND `sync_attempts < 3`, ordenado por `created_at ASC`, limit 20
> - Sucesso → `sync_status='synced'`, `sync_attempts += 1`
> - Falha → `sync_attempts += 1`, registra `sync_error_message` e `sync_last_attempt_at`
> - Após 3 falhas → `sync_status='failed'` + notificação Telegram

#### CT-033a: Cron — 0 pendentes, sem erro

**Funcionalidade:** `transactions:sync-pending`
**Tipo:** edge case
**Prioridade:** média

**Pré-condições:**
- Firestore: 0 transações com `sync_status = 'pending'`

**Passos:**
1. Executar: `php artisan transactions:sync-pending`

**Resultado esperado:**
- Comando termina sem erro (exit code 0)
- Output: "0 transações pendentes encontradas." ou similar
- Nenhuma chamada à Sheets API

**Pós-condição:** Nenhuma alteração.

---

#### CT-033b: Cron — 1 pendente, sync bem-sucedido (CT-033 original)

**Funcionalidade:** `transactions:sync-pending`
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- 1 transação com `sync_status='pending'`, `sync_attempts=0`
- Sheets acessível

**Passos:**
1. Executar: `php artisan transactions:sync-pending`

**Resultado esperado:**
- Comando reporta: "1 processada(s), 0 falha(s)"
- Firestore: `sync_status='synced'`, `sync_attempts=1`
- Planilha: transação aparece

**Pós-condição:** Transação synced.

---

#### CT-033c: Cron — 2 tentativas anteriores, 3ª tenta

**Funcionalidade:** `transactions:sync-pending`
**Tipo:** decisão #7
**Prioridade:** alta

**Pré-condições:**
- 1 transação com `sync_status='pending'`, `sync_attempts=2` (já falhou 2 vezes)
- Sheets agora acessível (restaurar acesso)

**Passos:**
1. Executar: `php artisan transactions:sync-pending`

**Resultado esperado:**
- Transação é processada (`sync_attempts=2 < 3` → incluída na query)
- Sucesso → `sync_status='synced'`, `sync_attempts=3`
- **NÃO** vai para `failed` (só iria se a 3ª tentativa falhasse)

**Pós-condição:** Transação synced com 3 tentativas totais.

---

#### CT-033d: Cron — 3 falhas → `failed` + notificação

**Funcionalidade:** `transactions:sync-pending`
**Tipo:** decisão #7
**Prioridade:** alta

**Pré-condições:**
- 1 transação com `sync_status='pending'`, `sync_attempts=2`
- Sheets INACESSÍVEL (ex: revogar compartilhamento da planilha com a Service Account)

**Passos:**
1. Executar: `php artisan transactions:sync-pending`

**Resultado esperado:**
- Transação é processada (2 < 3 → incluída)
- Falha ao chamar Sheets API
- `sync_attempts` incrementado para 3
- Como `sync_attempts >= 3` → `sync_status = 'failed'`
- `sync_error_message` preenchido com a mensagem de erro
- `sync_last_attempt_at` atualizado
- **Notificação enviada ao usuário no Telegram:** "⚠️ Uma transação não pôde ser sincronizada com a planilha após 3 tentativas. Use /sync para tentar novamente."

**Pós-condição:**
- Firestore: `sync_status='failed'`, `sync_attempts=3`
- Usuário notificado no Telegram

---

#### CT-033e: Cron — `sync_attempts >= 3` → skip

**Funcionalidade:** `transactions:sync-pending`
**Tipo:** decisão #7
**Prioridade:** alta

**Pré-condições:**
- 1 transação com `sync_status='failed'`, `sync_attempts=3`
- 1 transação com `sync_status='pending'`, `sync_attempts=4` (raro, mas possível se houve bug)

**Passos:**
1. Executar: `php artisan transactions:sync-pending`

**Resultado esperado:**
- Ambas as transações são IGNORADAS (query: `sync_attempts < 3`)
- Comando reporta 0 processadas
- Nenhuma chamada à Sheets API para essas transações

**Pós-condição:** Transações não alteradas.

---

#### CT-033f: Cron — Sheets indisponível, incrementa contador

**Funcionalidade:** `transactions:sync-pending`
**Tipo:** resiliência
**Prioridade:** alta

**Pré-condições:**
- 3 transações pendentes com `sync_attempts=0`
- Sheets INACESSÍVEL

**Passos:**
1. Executar: `php artisan transactions:sync-pending`

**Resultado esperado:**
- Todas as 3 falham
- `sync_attempts` incrementado para 1 em cada
- `sync_status` permanece `'pending'` (não `'failed'` ainda — só após 3 falhas)
- Comando NÃO lança exceção (exit code 0 ou captura erro)
- Log registra o erro

**Pós-condição:** 3 transações com `sync_attempts=1`, `sync_status='pending'`.

---

#### CT-033g: Cron — batch de 20 pendentes (limite da query)

**Funcionalidade:** `transactions:sync-pending`
**Tipo:** edge case
**Prioridade:** baixa

**Pré-condições:**
- 25 transações com `sync_status='pending'`, `sync_attempts=0`

**Passos:**
1. Executar: `php artisan transactions:sync-pending`

**Resultado esperado:**
- Apenas 20 são processadas (limite da query: `limit 20`)
- 5 permanecem pendentes para a próxima execução (5 min depois)
- Ordem de processamento: `created_at ASC` (mais antigas primeiro)

**Pós-condição:** 20 synced/failed, 5 pending para próxima rodada.

---

### 3.9 Funcionalidade: `GET /cron/sync-pending` (M9.9) — **DEPRECATED** *(substituído por `Schedule::command('transactions:sync-pending')` em `routes/console.php`)*

> **Referência:** M9.9 do plano de implementação
> **⚠️ SUBSTITUÍDO:** O endpoint HTTP `GET /cron/sync-pending` e o middleware `VerifyCronToken` foram removidos. O cron agora é disparado via `Schedule::command('transactions:sync-pending')` no Laravel Scheduler (`routes/console.php`). Os testes CT-054 a CT-057 abaixo são mantidos como **registro histórico** da implementação original.
>
> **Header esperado (histórico):** `X-Cron-Token: <CRON_SECRET_TOKEN>`

#### CT-054: `GET /cron/sync-pending` — token válido → 200 *(substituído por Schedule::command — teste mantido como registro histórico)*

**Funcionalidade:** endpoint cron
**Tipo:** happy path
**Prioridade:** alta

**Pré-condições:**
- `CRON_SECRET_TOKEN` definido no `.env` (DEPRECATED)
- Endpoint mapeado em `routes/web.php` (M11: migrado para `routes/api.php`)

**Passos:**
1. `# DEPRECATED: o endpoint HTTP foi substituído por Schedule::command. Use: php artisan transactions:sync-pending`
   Executar: `curl -H "X-Cron-Token: ${CRON_SECRET_TOKEN}" https://<staging>/cron/sync-pending`

**Resultado esperado:**
- HTTP 200 OK
- Corpo JSON com estrutura:
  ```json
  {
    "processed": 0,
    "failed": 0,
    "message": "Sync completed"
  }
  ```
- (ou números > 0 se houver pendentes)

**Pós-condição:** N/A.

---

#### CT-055: `GET /cron/sync-pending` — sem token → 401 *(substituído por Schedule::command — teste mantido como registro histórico)*

**Funcionalidade:** endpoint cron
**Tipo:** segurança
**Prioridade:** alta

**Passos:**
1. `# DEPRECATED: o endpoint HTTP foi substituído por Schedule::command. Use: php artisan transactions:sync-pending`
   Executar: `curl -v https://<staging>/cron/sync-pending`

**Resultado esperado:**
- HTTP 401 Unauthorized
- Corpo: `{"error": "Unauthorized"}` ou similar
- NENHUMA transação processada
- Log registra tentativa de acesso não autorizado

**Pós-condição:** Nenhuma alteração no sistema.

---

#### CT-056: `GET /cron/sync-pending` — token inválido → 401 *(substituído por Schedule::command — teste mantido como registro histórico)*

**Funcionalidade:** endpoint cron
**Tipo:** segurança
**Prioridade:** alta

**Passos:**
1. `# DEPRECATED: o endpoint HTTP foi substituído por Schedule::command. Use: php artisan transactions:sync-pending`
   Executar: `curl -H "X-Cron-Token: token-errado-123" https://<staging>/cron/sync-pending`

**Resultado esperado:**
- HTTP 401 Unauthorized
- Mesmo comportamento do CT-055

**Pós-condição:** Nenhuma alteração.

---

#### CT-057: `GET /cron/sync-pending` — resposta JSON estruturada *(substituído por Schedule::command — teste mantido como registro histórico)*

**Funcionalidade:** endpoint cron
**Tipo:** verificação
**Prioridade:** média

**Pré-condições:**
- Token válido
- 2 transações pendentes, ambas syncáveis

**Passos:**
1. `# DEPRECATED: o endpoint HTTP foi substituído por Schedule::command. Use: php artisan transactions:sync-pending`
   Executar: `curl -s -H "X-Cron-Token: ${CRON_SECRET_TOKEN}" https://<staging>/cron/sync-pending | python3 -m json.tool`

**Resultado esperado:**
- JSON válido e parseável
- Estrutura consistente:
  ```json
  {
    "processed": 2,
    "failed": 0,
    "synced_ids": ["<firestore_id_1>", "<firestore_id_2>"],
    "message": "Sync completed"
  }
  ```
- Headers: `Content-Type: application/json`

**Pós-condição:** 2 transações synced.

---

### 3.10 Funcionalidade: Integração e Fluxos Cruzados

> Testes que verificam a interação entre os comandos do M9 e os fluxos já existentes (M3-M8).

#### CT-058: `/nova` durante AWAITING_CONFIRMATION — sessão anterior descartada

**Funcionalidade:** integração `/nova` × state machine
**Tipo:** integração
**Prioridade:** alta

**Pré-condições:**
- Estado AWAITING_CONFIRMATION com transação A (ex: `Paguei R$ 30,00 no uber`)

**Passos:**
1. Em AWAITING_CONFIRMATION, digitar `/nova`
2. Wizard começa — completar com transação B (ex: receita, R$ 5000, Salário)
3. Confirmar transação B
4. Verificar Firestore

**Resultado esperado:**
- Apenas transação B existe no Firestore
- Transação A foi descartada quando `/nova` iniciou
- Nenhum erro, sem dados órfãos

**Pós-condição:** 1 transação (B), estado = IDLE.

---

#### CT-059: `/ultimos` após confirmar transação — nova transação no topo

**Funcionalidade:** integração `/ultimos` × confirmação
**Tipo:** integração
**Prioridade:** alta

**Pré-condições:**
- Firestore com 3 transações antigas

**Passos:**
1. Digitar `/ultimos 3` — anotar as 3 transações
2. Enviar `Paguei R$ 99,99 no teste de integração` → Confirmar
3. Digitar `/ultimos 4`

**Resultado esperado:**
- Passo 3: a nova transação ("teste de integração", R$ 99,99) aparece no **topo** da lista (mais recente)
- As 3 transações antigas aparecem em seguida, na mesma ordem de antes

**Pós-condição:** Estado = IDLE.

---

#### CT-060: `/categorias` após transação com nova categoria — uso incrementado

**Funcionalidade:** integração `/categorias` × confirmação
**Tipo:** integração
**Prioridade:** alta

**Pré-condições:**
- Categoria "Viagem" NÃO existe

**Passos:**
1. Digitar `/categorias` — confirmar que "Viagem" não está na lista
2. Via `/nova` ou edição: criar transação com categoria NOVA "Viagem"
3. Confirmar transação
4. Digitar `/categorias`

**Resultado esperado:**
- Passo 4: "Viagem" aparece com contador `(1)`
- Categoria "Viagem" existe no Firestore: `categories/viagem` com `use_count=1`

**Pós-condição:** Categoria persistida e contabilizada.

---

#### CT-061: `/sync` após restaurar Sheets — recupera pendentes

**Funcionalidade:** integração `/sync` × Sheets recovery
**Tipo:** integração
**Prioridade:** alta

**Pré-condições:**
- 3 transações com `sync_status='pending'` (criadas enquanto Sheets estava offline)

**Passos:**
1. Confirmar que as 3 transações NÃO estão na planilha
2. Restaurar acesso ao Sheets (compartilhar novamente)
3. Digitar `/sync`
4. Verificar planilha

**Resultado esperado:**
- `/sync` reporta 3 sincronizadas
- As 3 transações aparecem na planilha, nas linhas seguintes
- Firestore: todas com `sync_status='synced'`

**Pós-condição:** Planilha atualizada, sem duplicação.

---

### 3.11 Funcionalidade: Segurança — Whitelist nos Comandos

> **Referência:** M2 (middleware `ValidateTelegramWebhook`), CT-034 a CT-036 (plano original)

#### CT-062: Comandos com chat_id não-whitelistado → 403

**Funcionalidade:** segurança — whitelist
**Tipo:** segurança
**Prioridade:** alta

**Pré-condições:**
- `TELEGRAM_ALLOWED_CHAT_IDS` contém apenas o chat_id do testador principal
- Um segundo chat_id (ex: 999999999) configurado para enviar mensagens

**Passos:**
1. Do chat NÃO whitelistado, enviar `/start`
2. Do chat NÃO whitelistado, enviar `/help`
3. Do chat NÃO whitelistado, enviar `/ultimos`
4. Do chat NÃO whitelistado, enviar `/sync`

**Resultado esperado:**
- **Todas** as requisições recebem HTTP 403 Forbidden
- Nenhuma mensagem de resposta é enviada ao chat não-whitelistado
- Nenhum dado é acessado ou modificado
- Log registra tentativa com `reason = 'chat_id_not_allowed'`

**Pós-condição:** Nenhuma alteração no sistema.

---

#### CT-063: `/ultimos` — isolamento por chat_id

**Funcionalidade:** segurança — isolamento
**Tipo:** segurança
**Prioridade:** alta

**Pré-condições:**
- Firestore: chat_id A tem 5 transações; chat_id B tem 3 transações DIFERENTES
- Ambos os chat_ids whitelistados para este teste

**Passos:**
1. Do chat A, digitar `/ultimos 10`
2. Do chat B, digitar `/ultimos 10`

**Resultado esperado:**
- Chat A vê APENAS as 5 transações do chat A
- Chat B vê APENAS as 3 transações do chat B
- Nenhum vazamento de dados entre chats

**Pós-condição:** Dados isolados por chat_id.

---

#### CT-064: `/categorias` — isolamento por chat_id

**Funcionalidade:** segurança — isolamento
**Tipo:** segurança
**Prioridade:** média

**Pré-condições:**
- Categorias personalizadas diferentes em chats diferentes (se aplicável)
- Nota: categorias são globais (compartilhadas entre chats no modelo atual), mas o uso é por chat. Verificar se o contador de uso é global ou por chat.

**Passos:**
1. Do chat A, usar categoria "Academia" em 3 transações
2. Do chat B, digitar `/categorias`

**Resultado esperado:**
- O contador de uso exibido para "Academia" reflete o uso GLOBAL (3) — se o design for global
- OU "Academia" pode nem aparecer para o chat B se categorias personalizadas forem isoladas por chat
- ⚠️ **Verificar com o implementador:** qual o escopo do `use_count`? Global ou por chat_id?

**Pós-condição:** Consistente com o design escolhido.

---

## 4. Testes de Regressão

Antes de marcar o M9 como concluído, execute estes testes para garantir que os milestones anteriores não foram quebrados:

### Fluxo de texto (M3)
- [ ] CT-001: Enviar `Paguei R$ 47,50 no almoço de hoje` → parse correto, confirmação
- [ ] CT-003: Texto sem valor → bot pede valor
- [ ] CT-006b: Variações de formato de valor → todas parseadas

### Fluxo de imagem (M4)
- [ ] CT-007: Foto de nota fiscal → OCR funciona
- [ ] CT-008: Foto borrada → admite falha

### Confirmação (M7)
- [ ] CT-015: Confirmar grava Sheets + Firestore
- [ ] CT-016: Editar campo funciona
- [ ] CT-017: Cancelar limpa sessão
- [ ] CT-018: Duplo clique → idempotência

### Sugestão de labels (M8)
- [ ] CT-019: Histórico de labels sugerido
- [ ] CT-020: Keywords extraídas da descrição

### Persistência (M5, M6)
- [ ] CT-030: Linha no Sheets com 9 colunas
- [ ] CT-031: Documento no Firestore completo

### Segurança (M2)
- [ ] CT-034: Webhook sem token → 401
- [ ] CT-035: Token inválido → 401
- [ ] CT-036: Chat não-whitelistado → 403

### Timeout (M7)
- [ ] CT-043: Sessão expirada após 15 min

### Callback antigo (M7)
- [ ] CT-047: Callback de sessão cancelada rejeitado

---

## 5. Matriz de Risco

| Risco | Prob. | Impacto | Features afetadas | Mitigação |
|-------|-------|---------|-------------------|-----------|
| **Race condition `/sync` × cron** | Média | **Alta** — duplicação na planilha | `/sync`, `transactions:sync-pending` | Lock atômico via Firestore transaction (`processing=true`). Testar com CT-052. |
| **`/nova` não reseta sessão anterior** | Média | **Alta** — dados misturados | `/nova`, state machine | Sempre chamar `clearSession()` ao iniciar `/nova`. Testar com CT-025l, CT-058. |
| **`/cancelar` em IDLE envia mensagem errada** | **Alta** (handler atual não verifica) | Baixa — UX ruim | `/cancelar` | Handler deve verificar se há sessão ativa antes de notificar. Testar com CT-026a. |
| **`/start` não reseta sessão** | **Alta** (handler atual não reseta) | Média — sessão órfã | `/start` | Handler deve chamar `clearSession()`. Testar com CT-023f. |
| **`/help` mostra comandos como ⏳ após M9** | **Alta** (handler tem array estático) | Baixa — UX | `/help` | Atualizar `HelpHandler::commands()` para `true` em todos. Testar com CT-024b. |
| **`/ultimos 999999` comportamento ambíguo** | Média | Baixa — edge case raro | `/ultimos` | Clarificar se > 50 é cap (50) ou fallback (5). Ver CT-028d. |
| **`/sync` sem lock — dupla execução** | Baixa | **Alta** — duplicação | `/sync` | Implementar lock com `processing` flag no Firestore. |
| **3 falhas → notificação não enviada** | Baixa | Média — usuário não sabe | `transactions:sync-pending` | Testar notificação explicitamente no CT-033d. |
| **Cron endpoint sem token acessível** | Baixa | **Crítica** — exposição | `GET /cron/sync-pending` (DEPRECATED — substituído por `Schedule::command`) | Middleware de token obrigatório. Testar CT-055, CT-056. |
| **Whitelist bypass em comandos** | Muito Baixa | **Crítica** — vazamento | Todos os comandos | Middleware validado no M2. Testar CT-062, CT-063. |
| **Wizard `/nova` — timeout entre etapas** | Média | Média — UX frustrante | `/nova` | Timeout de 15 min se aplica ao wizard. Testar CT-025n. |
| **Categorias personalizadas com `use_count` errado** | Baixa | Baixa — métrica incorreta | `/categorias` | Testar CT-060 para validação ponta a ponta. |

---

## 6. Critérios de Aceitação Globais do M9

### Por funcionalidade

| # | Critério | CTs que validam |
|---|----------|-----------------|
| M9.1 | `/start` envia boas-vindas e reseta sessão para IDLE em qualquer estado | CT-023, CT-023a–CT-023f |
| M9.2 | `/help` lista todos os 7 comandos com ✅ ativo no M9 final | CT-024, CT-024a–CT-024c |
| M9.3 | `/nova` wizard 5 etapas funciona; atalho de linguagem natural funciona | CT-025, CT-025a–CT-025n |
| M9.4 | `/cancelar` funciona em todos os 4 estados; em IDLE diz "Nada para cancelar" | CT-026a–CT-026e |
| M9.5 | `/ultimos [n]` com fallback silencioso, cap, clamp | CT-027, CT-027a–CT-027c, CT-028, CT-028a–CT-028g |
| M9.6 | `/categorias` lista padrão + personalizadas com uso | CT-029, CT-029a–CT-029f |
| M9.7 | `/sync` processa pendentes, reseta contador, não conflita com cron | CT-048–CT-053 |
| M9.8 | `transactions:sync-pending` query correta, 3 falhas → failed + notificação | CT-033a–CT-033g |
| M9.9 | `GET /cron/sync-pending` com auth token, resposta JSON *(substituído por `Schedule::command('transactions:sync-pending')` — CTs mantidos como registro histórico)* | CT-054–CT-057 |
| Seg. | Comandos respeitam whitelist; isolamento por chat_id | CT-062–CT-064 |
| Integ. | Comandos não quebram fluxos existentes (M3–M8) | CT-058–CT-061 |

### Definition of Done do M9

- [ ] Todos os 57 CTs executados com status PASS (ou FAIL documentado com justificativa)
- [ ] Nenhum CT bloqueado sem plano de ação
- [ ] Handlers `/nova`, `/ultimos`, `/categorias`, `/sync` implementados e registrados no `BotLoader`
- [ ] Comando `transactions:sync-pending` implementado
- [ ] ~~Rota `GET /cron/sync-pending` implementada com middleware de token~~ *(SUBSTITUÍDO: o cron agora usa `Schedule::command('transactions:sync-pending')` em `routes/console.php`; o middleware `VerifyCronToken` foi removido)*
- [ ] `HelpHandler::commands()` atualizado: todos ✅
- [ ] `StartHandler` reseta sessão para IDLE
- [ ] `CancelarHandler` distingue IDLE vs. sessão ativa
- [ ] `vendor/bin/phpunit --filter CommandsTest` ou suíte equivalente 100% verde
- [ ] Smoke test pós-deploy executado (ver §7)

---

## 7. Procedimento de Smoke Test Pós-Deploy

Executar **após o deploy do M9 em staging** (ou produção), antes de marcar como done. Sequência mínima para validar que o M9 está funcional:

### Ordem de execução (≈ 15 minutos)

| # | CT | O que fazer | O que verificar | Tempo estimado |
|---|----|-------------|-----------------|----------------|
| 1 | CT-023 | `/start` | Boas-vindas, estado IDLE | 30s |
| 2 | CT-024 | `/help` | Lista 7 comandos ✅ | 30s |
| 3 | CT-029 | `/categorias` | Lista categorias padrão | 30s |
| 4 | CT-027 | `/ultimos` | Lista transações (ou "Nenhuma") | 30s |
| 5 | CT-025 | `/nova` wizard completo → confirmar | Wizard 5 etapas OK, transação na planilha | 3 min |
| 6 | CT-023b | Em AWAITING_CONFIRMATION → `/start` | Sessão limpa, boas-vindas | 1 min |
| 7 | CT-026a | `/cancelar` em IDLE | "Nada para cancelar" | 30s |
| 8 | CT-026c | `/cancelar` em AWAITING_CONFIRMATION | Sessão limpa | 1 min |
| 9 | CT-028 | `/ultimos 3` | Lista correta, ordenada | 30s |
| 10 | CT-028c | `/ultimos abc` | Fallback 5 | 30s |
| 11 | CT-049 | `/sync` com 1 pendente | Sync OK, planilha atualizada | 2 min |
| 12 | CT-054 | `curl` cron endpoint com token | 200 OK, JSON válido | 1 min |
| 13 | CT-055 | `curl` cron endpoint sem token | 401 | 30s |
| 14 | CT-015 | Enviar texto livre → Confirmar | Fluxo M3+M7 intacto | 1 min |
| 15 | CT-059 | `/ultimos` após confirmar | Nova transação no topo | 30s |

**Tempo total estimado:** ≈ 13 minutos

### Critério de smoke test aprovado

- **14/15 PASS** (93%) — com zero FAIL em testes de prioridade alta
- Se houver FAIL, documentar no campo de observação e abrir issue
- Se `CT-026a` falhar (mensagem errada em IDLE), é **bloqueante** para M9? Não — é UX. Pode ser corrigido em patch. Mas deve ser documentado.

---

## 8. Observações e Riscos Operacionais

### 8.1 Gaps encontrados entre especificação e implementação atual

| Gap | Descrição | Severidade | Ação necessária |
|-----|-----------|------------|-----------------|
| **GAP-01** | `StartHandler` não reseta sessão (CT-023f) | Média | Adicionar `FirestoreService::clearSession()` ao handler |
| **GAP-02** | `CancelarHandler` não distingue IDLE de estados ativos (CT-026a) | Baixa | Adicionar verificação: se `getSession() === null`, enviar "Nada para cancelar" |
| **GAP-03** | `HelpHandler::commands()` lista `/nova`, `/cancelar`, `/ultimos`, `/categorias`, `/sync` como `false` (CT-024b) | Baixa | Atualizar array para `true` em todos |
| **GAP-04** | Handlers `/nova`, `/ultimos`, `/categorias`, `/sync` não existem | **Crítica** | Implementar (escopo do M9) |
| **GAP-05** | Comando `transactions:sync-pending` não existe | **Crítica** | Implementar (M9.8) |
| **GAP-06** | Rota `GET /cron/sync-pending` não existe | **Crítica** → **RESOLVIDO:** substituído por `Schedule::command('transactions:sync-pending')` em `routes/console.php` | Implementar (M9.9) |
| **GAP-07** | `BotLoader` não registra handlers para os novos comandos | **Crítica** | Registrar após implementar GAP-04 |

### 8.2 Preocupações operacionais

1. **Race condition no `/sync`**: se o lock atômico não for implementado corretamente, `/sync` manual e o cron podem processar a mesma transação simultaneamente, causando duplicação na planilha. **Mitigação:** usar `FirestoreService::tryAcquireSessionProcessingFlag()` ou implementar lock similar no nível da transação (campo `processing` na collection `transactions`). Testar com CT-052.

2. **Isolamento de `use_count` em categorias**: o modelo atual do Firestore (`categories/{name}`) tem `use_count` global — todos os chats compartilham o mesmo contador. Se no futuro houver múltiplos usuários, isso pode ser um problema. Para 1 usuário (escopo atual), é aceitável.

3. **`/ultimos` sem índice composto**: a query `listRecent(chatId, limit)` exige índice composto `chat_id ASC, date DESC` no Firestore. Sem ele, a query falha com `FAILED_PRECONDITION`. Verificar se o índice foi criado antes de testar.

4. **Truncamento de descrição no wizard**: a etapa 3 do `/nova` deve aplicar o limite de 500 caracteres (decisão #9), mas o truncamento com "..." pode cortar no meio de um caractere UTF-8 multi-byte. Usar `mb_substr($desc, 0, 497) . '...'`.

5. **Formato de data no `/ultimos`**: o Firestore armazena data como string ISO (`2026-06-19`). O bot deve formatar para `19/06/2026` (DD/MM/AAAA) ao exibir. Testar com CT-028g.

6. **Comportamento do fallback em `/ultimos` com n > 50**: a decisão #6 mostra uma tabela onde `/ultimos 999999` → 50 (cap), mas o pseudocódigo mostra `if ($n > 50) { $n = 5; }` (fallback). Isso é contraditório. **Recomendação:** cap em 50, não fallback para 5 — é mais útil para o usuário. Documentar a decisão final.

### 8.3 Dependências de milestones anteriores que impactam o M9

| Dependência | Milestone | Status | Impacto no M9 se falhar |
|-------------|-----------|--------|-------------------------|
| `FirestoreService::listRecent()` | M5 | ✅ Implementado | `/ultimos` quebra |
| `FirestoreService::getCategories()` | M5 | ✅ Implementado | `/categorias` quebra |
| `FirestoreService::clearSession()` | M5 | ✅ Implementado | `/start`, `/cancelar` não resetam |
| `FirestoreService::setSession()` | M5 | ✅ Implementado | `/nova` wizard não persiste estado |
| `SyncSheet` action | M6 | ✅ Implementado | `/sync`, cron não funcionam |
| `SheetsService::appendTransaction()` | M6 | ✅ Implementado | Sync pendente falha |
| State machine (Router) | M7 | ✅ Implementado | `/nova` integração frágil |
| `SuggestCategory` | M8 | ✅ Implementado | `/nova` etapa 4 sem sugestões |
| `SuggestLabels` | M8 | ✅ Implementado | `/nova` etapa 5 sem sugestões |
| Whitelist middleware | M2 | ✅ Implementado | Segurança dos comandos ok |
| Índices Firestore (`chat_id`+`date`) | M5 | ⚠️ Verificar | `/ultimos` quebra sem índice |

---

## 9. Checklist de Validação

### Funcionalidade: `/start`
- [ ] CT-023: `/start` em IDLE — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-023a: `/start` em AWAITING_DATA — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-023b: `/start` em AWAITING_CONFIRMATION — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-023c: `/start` em AWAITING_EDITION — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-023d: `/start` com sessão expirada — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-023e: `/start` idempotência — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-023f: `/start` reseta sessão → IDLE — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Funcionalidade: `/help`
- [ ] CT-024: `/help` lista comandos — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-024a: `/help` em estado não-IDLE — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-024b: M9 final: todos ✅ — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-024c: Verificação de formato — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Funcionalidade: `/nova`
- [ ] CT-025: Wizard completo happy path — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025a: Etapa 1 — tipo inválido — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025b: Etapa 2 — valor inválido — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025c: Etapa 2 — valor zero — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025d: Etapa 3 — descrição curta — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025e: Etapa 3 — descrição > 500 chars — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025f: Etapa 4 — selecionar do keyboard — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025g: Etapa 4 — digitar categoria nova — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025h: Etapa 5 — labels válidas — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025i: Etapa 5 — "pular" labels — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025j: Etapa 5 — label inválida — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025k: Atalho linguagem natural — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025l: `/nova` durante AWAITING_CONFIRMATION — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025m: `/nova` com `/cancelar` no meio — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025n: Timeout entre etapas — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Funcionalidade: `/cancelar`
- [ ] CT-026a: Em IDLE → "Nada para cancelar" — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-026b: Em AWAITING_DATA — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-026c: Em AWAITING_CONFIRMATION — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-026d: Em AWAITING_EDITION — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-026e: Durante wizard `/nova` — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Funcionalidade: `/ultimos [n]`
- [ ] CT-027: Default 5 — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-027a: 0 transações — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-027b: Só receitas — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-027c: Só despesas — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-028: Parâmetros válidos (10, 3) — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-028a: `/ultimos 0` → 5 — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-028b: `/ultimos -3` → 5 — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-028c: `/ultimos abc` → 5 — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-028d: `/ultimos 999999` → cap — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-028e: n > total disponível — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-028f: Em estado não-IDLE — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-028g: Verificação visual — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Funcionalidade: `/categorias`
- [ ] CT-029: Lista completa — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-029a: Só defaults — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-029b: Com personalizadas — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-029c: Contador de uso — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-029d: 0 personalizadas — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-029e: Após criar via `/nova` — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-029f: Em estado não-IDLE — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Funcionalidade: `/sync`
- [ ] CT-048: Sem pendentes — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-049: 1 pendente → synced — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-050: Várias pendentes — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-051: Reset de contador — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-052: Lock atômico (concorrência) — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-053: Em estado não-IDLE — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Funcionalidade: `transactions:sync-pending`
- [ ] CT-033a: 0 pendentes — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-033b: 1 pendente → synced — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-033c: 2 tentativas → 3ª tenta — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-033d: 3 falhas → failed + notificação — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-033e: attempts >= 3 → skip — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-033f: Sheets indisponível — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-033g: Batch de 20 — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Funcionalidade: `GET /cron/sync-pending` *(DEPRECATED — substituído por `Schedule::command('transactions:sync-pending')`)*
- [ ] CT-054: Token válido → 200 — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-055: Sem token → 401 — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-056: Token inválido → 401 — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-057: Resposta JSON válida — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Integração e Segurança
- [ ] CT-058: `/nova` durante AWAITING_CONFIRMATION — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-059: `/ultimos` após confirmar — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-060: `/categorias` após nova categoria — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-061: `/sync` após restaurar Sheets — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-062: Comandos não-whitelistado → 403 — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-063: `/ultimos` isolamento chat_id — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-064: `/categorias` isolamento chat_id — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Aprovação Final
- [ ] Todos os CTs de prioridade alta: ⬜ PASS
- [ ] Nenhum CT bloqueado sem justificativa
- [ ] Handlers implementados e registrados
- [ ] Comando artisan + rota cron implementados
- [ ] Smoke test pós-deploy executado
- [ ] Regressão M3–M8 validada
- [ ] **Aprovado por:** _______________ **Data:** ___/___/___