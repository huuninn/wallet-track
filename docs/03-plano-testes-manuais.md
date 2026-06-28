# 03 — Plano de Testes Manuais

> **⚠️ NOTA DE MIGRAÇÃO:** Este documento descreve a arquitetura original com Google Firestore como camada de persistência. A persistência foi **migrada para MariaDB**. As referências ao Firestore neste documento são **históricas** e refletem o estado na época da escrita. O componente `FirestoreService` foi substituído por `WalletStore` (Eloquent/MariaDB). As coleções `transactions`, `categories`, `labels` e `sessions` do Firestore correspondem agora às tabelas homônimas no MariaDB.

> 47 casos de teste cobrindo fluxos felizes, edge cases, segurança e resiliência. Nota: os casos de OCR (CT-007 a CT-010) foram atualizados para refletir o uso do **Gemini** (em vez de Google Cloud Vision) conforme Revisão v2.

---

## Ambiente de teste

- **URL/ambiente:** Cloud Run (staging) — webhook URL
- **Telegram Bot:** bot de staging
- **Pré-condições globais:**
  - Planilha Google Sheets de staging criada e compartilhada com a Service Account
  - Coleções Firestore (`transactions`, `sessions`, `categories`, `labels`) existentes
  - Bot em execução, webhook registrado
  - chat_id do testador whitelistado

---

## Template de caso de teste

```
### CT-XXX: [Nome]
**Objetivo:** ...
**Pré-condições:** ...
**Passos:** 1. ... 2. ...
**Dados de entrada:** ...
**Resultado esperado:** ...
**Pós-condição:** ...
```

---

## 1. Fluxo de Texto (Linguagem Natural)

### CT-001: Registrar despesa completa via texto livre
**Objetivo:** Validar parse de mensagem com todos os campos implícitos.
**Pré-condições:** Estado IDLE.
**Passos:**
1. Enviar: `Paguei R$ 47,50 no almoço de hoje no restaurante italiano`
2. Aguardar resposta
3. Verificar resumo com todos os campos
**Dados de entrada:** `Paguei R$ 47,50 no almoço de hoje no restaurante italiano`
**Resultado esperado:**
- Tipo: **despesa** (keyword "Paguei")
- Valor: **47,50**
- Data: **hoje**
- Categoria sugerida: **Alimentação**
- Inline keyboard: [Confirmar] [Editar] [Cancelar]
- Estado → **AWAITING_CONFIRMATION**

### CT-002: Registrar receita via texto livre
**Objetivo:** Validar classificação de receita.
**Dados de entrada:** `Recebi R$ 5.000,00 de salário da empresa hoje`
**Resultado esperado:** Tipo **receita** (keyword "Recebi"), categoria **Salário**, valor **5000,00**.

### CT-003: Texto sem valor numérico
**Objetivo:** Validar solicitação de esclarecimento quando não há valor.
**Passos:**
1. Enviar: `Paguei o almoço no restaurante`
2. Responder ao pedido de valor: `45,90`
**Resultado esperado:** Bot pede o valor → estado **AWAITING_DATA** → ao receber `45,90`, avança para confirmação.

### CT-004: Texto com valor mas tipo ambíguo
**Objetivo:** Validar pergunta explícita quando tipo é ambíguo.
**Passos:**
1. Enviar: `R$ 200,00 do freelance que fiz`
2. Responder: `receita`
**Resultado esperado:** Bot pergunta "despesa ou receita?" → após resposta, categoria **Freelance**, tipo **receita**.

### CT-005: Texto com data no passado ("ontem")
**Dados de entrada:** `Gastei R$ 30,00 com gasolina ontem`
**Resultado esperado:** Data = dia anterior. Categoria **Transporte** (keyword "gasolina").

### CT-006: Formato de valor brasileiro (R$ 1.234,56)
**Dados de entrada:** `Paguei R$ 1.234,56 no conserto do carro`
**Resultado esperado:** Valor parseado **1234,56** (separador de milhar respeitado).

### CT-006b: Variações de formato de valor
**Passos:**
1. `45,90 de pão na padaria` → **45,90**
2. `Gastei 45.90 no uber` → **45,90**
3. `Paguei 2000 de aluguel` → **2000,00**
4. `Recebi R$5,50 de troco` → **5,50**
**Resultado esperado:** Todos os valores parseados corretamente.

---

## 2. Fluxo de Imagem (OCR via Gemini)

### CT-007: Enviar foto de nota fiscal legível
**Objetivo:** Validar fluxo completo via Gemini multimodal.
**Pré-condições:** Foto de nota de supermercado (JPG/PNG, < 20MB) com CNPJ, itens, total, data legíveis.
**Passos:**
1. Enviar a foto da nota fiscal como imagem
2. Aguardar feedback de progresso (4 etapas editadas na mesma mensagem)
3. Verificar resumo extraído
4. Confirmar
**Resultado esperado:**
- Bot envia "Processando imagem..." e edita em 4 etapas
- Tipo: **despesa**; Descrição do estabelecimento; Valor: **total da nota**; Categoria sugerida
- Inline keyboard de confirmação
- Estado → **AWAITING_CONFIRMATION**; Firestore: `source = image`

### CT-008: Enviar foto ilegível/borrada
**Dados de entrada:** Foto completamente borrada (parede escura sem texto)
**Resultado esperado:** Bot informa que não foi possível extrair + sugere foto nítida ou texto. Estado permanece **IDLE**. Nenhum documento parcial criado.

### CT-009: Enviar imagem que não é nota fiscal
**Dados de entrada:** Foto de cachorro (sem texto de nota)
**Resultado esperado:** Gemini identifica que não há transação → bot admite falha ("Não identifiquei uma transação clara") + abre canal para texto/imagem. Estado → **IDLE**.
*(Ver decisão #1 em [Clarificações](./04-clarificacoes.md).)*

### CT-010: Nota fiscal com múltiplos valores (deve pegar o total)
**Dados de entrada:** Nota com itens R$ 5,50 / R$ 12,90 / R$ 8,70 e total R$ 27,10
**Resultado esperado:** Valor extraído = **R$ 27,10** (total), não valor parcial.

---

## 3. Validação

### CT-011: Despesa sem categoria (sugerir/criar)
**Passos:**
1. Enviar: `Paguei R$ 150,00 em material de pintura`
2. Editar categoria para: `Hobbies`
3. Confirmar
**Resultado esperado:** Categoria inicial "Outros" → ao editar para "Hobbies", cria nova categoria. `/categorias` posterior inclui "Hobbies".

### CT-012: Criar nova categoria via bot
**Passos:**
1. Enviar: `Gastei R$ 80,00 com ração do cachorro`
2. Editar → Categoria → digitar `Pet`
3. Confirmar
**Resultado esperado:** Categoria "Pet" criada e persistida no Firestore.

### CT-013: Valor inválido (negativo)
**Dados de entrada:** `Paguei R$ -50,00 na conta de luz`
**Resultado esperado:** Rejeita valor negativo (decisão #2). Pede valor positivo. Estado → **AWAITING_DATA**.

### CT-014: Data futura (deve confirmar)
**Dados de entrada:** `Paguei R$ 200,00 da fatura do cartão dia 25/12/2026`
**Resultado esperado:** Bot alerta "data no futuro" e pede confirmação (decisão #3).

### CT-014b: Texto não numérico no campo valor
**Passos:**
1. Enviar: `Paguei caro no restaurante`
2. Quando pedir valor, responder: `muito caro`
**Resultado esperado:** Rejeita "muito caro"; pede número.

---

## 4. Fluxo de Confirmação (Inline Keyboard)

### CT-015: Confirmar registro (fluxo feliz completo)
**Passos:**
1. Enviar: `Paguei R$ 35,00 no cinema`
2. Clicar **Confirmar**
3. Verificar Google Sheets + Firestore
**Resultado esperado:** Mensagem de sucesso; nova linha na planilha; documento no Firestore com `sync_status=synced`.

### CT-016: Editar campo antes de confirmar
**Passos:**
1. Enviar: `Paguei R$ 50,00 no almoço`
2. Editar → Valor → `75,00`
3. Confirmar
**Resultado esperado:** Resumo atualizado com valor R$ 75,00; registro gravado com 75,00.

### CT-017: Cancelar registro
**Dados de entrada:** `Gastei R$ 100,00 na farmácia` → clicar **Cancelar**
**Resultado esperado:** "Registro cancelado"; estado → IDLE; nada gravado; sessão limpa.

### CT-018: Duplo clique em Confirmar (idempotência)
**Passos:** Enviar despesa → clicar **Confirmar** 3x rapidamente
**Resultado esperado:** Exatamente 1 transação criada. Respostas extras: "Já registrado".

### CT-018b: Clicar em Confirmar após timeout de 15 minutos
**Passos:** Enviar despesa → aguardar 16 min → clicar Confirmar
**Resultado esperado:** "Sessão expirada"; transação não gravada.

---

## 5. Sugestão de Labels

### CT-019: Sugestão baseada em histórico (mesma categoria)
**Setup:** 2 transações anteriores em "Alimentação" com labels `#ifood` e `#restaurante`.
**Dados de entrada:** `Paguei R$ 32,00 na pizza do iFood`
**Resultado esperado:** Sugere `#ifood` e `#restaurante` (do histórico da categoria). Histórico tem prioridade.

### CT-020: Sugestão baseada em keywords da descrição
**Dados de entrada:** `Paguei R$ 120,00 na conta de luz da enel`
**Resultado esperado:** Sugere keywords extraídas (após remoção de stopwords): `#conta`, `#luz`, `#enel`. Máximo 5, minúsculas sem acento.

### CT-021: Aceitar/recusar labels sugeridas
**Passos:**
1. Enviar: `Paguei R$ 55,00 no iFood de japonês`
2. Editar → Labels → digitar `#japones, #domingo`
3. Confirmar
**Resultado esperado:** Labels finais `#japones #domingo`; sugestão `#ifood` não incluída.

### CT-022: Histórico melhora sugestão
**Setup:** 3 transações com label `#carro`.
**Resultado esperado:** Na 4ª transação relacionada, `#carro` sugerida (2+ ocorrências no histórico).

---

## 6. Comandos

### CT-023: /start
**Resultado esperado:** Mensagem de boas-vindas + instruções. Estado IDLE.

### CT-024: /help
**Resultado esperado:** Lista de comandos com exemplos. Estado IDLE.

### CT-025: /nova
**Resultado esperado:** Wizard passo-a-passo: Tipo → Valor → Descrição → Categoria → Labels (decisão #5).

### CT-026: /cancelar em diferentes estados
**Subtestes:** a) AWAITING_CONFIRMATION b) AWAITING_DATA c) AWAITING_EDITION d) IDLE
**Resultado esperado:** Em todos, volta a IDLE. Em IDLE: "Nada para cancelar".

### CT-027: /ultimos (default 5)
**Resultado esperado:** Retorna as 5 transações mais recentes, ordenadas por data DESC.

### CT-028: /ultimos com parâmetro
**Passos:**
1. `/ultimos 10` → 10 transações
2. `/ultimos 3` → 3 transações
3. `/ultimos abc` → fallback silencioso 5 (decisão #6)
4. `/ultimos 999999` → truncado para 50
**Resultado esperado:** Regra única e defensiva (decisão #6).

### CT-029: /categorias
**Resultado esperado:** Lista categorias padrão + personalizadas, com contador de uso.

---

## 7. Persistência (Firestore + Sheets)

### CT-030: Verificar linha no Google Sheets após confirmação
**Resultado esperado:** Linha com 9 colunas preenchidas corretamente.

### CT-031: Verificar documento no Firestore
**Resultado esperado:** Documento com todos os campos: date, description, amount, type, category, labels, source, `sync_status=synced`, timestamps.

### CT-032: Simular falha no Google Sheets
**Como simular:** Revogar sharing da planilha OU usar spreadsheet_id inválido.
**Passos:** Provocar falha → enviar e confirmar despesa.
**Resultado esperado:** Firestore salva com `sync_status=pending`; bot informa problema; sem erro 500.

### CT-033: Sincronização posterior de pendente
**Passos:** Restaurar acesso ao Sheets → aguardar cron 5min (ou `/sync`).
**Resultado esperado:** Transação aparece na planilha; `sync_status` → `synced`. Sem duplicação.

---

## 8. Segurança

### CT-034: Webhook sem secret token
```bash
curl -X POST <webhook-url> -H "Content-Type: application/json" -d '{"update_id":123,"message":{"text":"teste"}}'
```
**Resultado esperado:** HTTP **401 Unauthorized**.

### CT-035: Webhook com secret token inválido
```bash
curl -X POST <webhook-url> -H "X-Telegram-Bot-Api-Secret-Token: errado" -d '{...}'
```
**Resultado esperado:** HTTP **401 Unauthorized**.

### CT-036: Mensagem de chat_id não autorizado
**Dados de entrada:** Token válido + chat_id fora da whitelist.
**Resultado esperado:** HTTP **403 Forbidden**; nada processado.

---

## 9. Resiliência

### CT-037: DeepSeek indisponível (fallback manual)
**Como simular:** API key inválida OU endpoint mock retornando 500.
**Resultado esperado:** Bot não retorna erro 500; inicia fluxo manual guiado; usuário consegue registrar.

### CT-038: Timeout de processamento de imagem
**Como testar:** Enviar imagem grande (~15MB).
**Resultado esperado:** Telegram não recebe timeout (webhook respondeu 200); resultado chega após processamento; feedback de progresso em 4 etapas.

### CT-039: Recovery após erro
**Passos:**
1. Provocar erro (ex: CT-008 imagem borrada)
2. Imediatamente enviar: `Paguei R$ 20,00 no uber`
**Resultado esperado:** Estado volta a IDLE após erro; nova mensagem processada normalmente.

---

## 10. Edge Cases

### CT-040: Mensagem muito longa (descrição > 500 caracteres)
**Dados de entrada:** Descrição de ~300-600 caracteres.
**Resultado esperado:** Trunca em 500 caracteres com "..."; notifica usuário sobre truncamento (decisão #9).

### CT-041: Caracteres especiais e emoji
**Dados de entrada:** `Paguei R$ 75,00 no 🍕 com os amigos ❤️`
**Resultado esperado:** Parse correto; emojis mantidos em Firestore e Sheets (decisão #10).

### CT-042: Múltiplas transações em sequência rápida
**Dados de entrada:** 3 mensagens em < 3s.
**Resultado esperado:** Cada mensagem gera seu próprio resumo; estados não se misturam; 3 registros distintos.

### CT-043: Sessão expirada por timeout (15 minutos)
**Passos:** Enviar despesa → aguardar 16 min → enviar nova despesa.
**Resultado esperado:** Sessão anterior expirou (não gravada); nova processada normalmente.

### CT-044: Mensagem vazia ou apenas espaços
**Dados de entrada:** `   ` (espaços)
**Resultado esperado:** Bot pede mensagem válida; estado IDLE; sem erro.

### CT-045: Valor com centavos zero (R$ 50)
**Dados de entrada:** `Paguei R$ 50 no mercado`
**Resultado esperado:** Valor **50,00**; sempre 2 casas decimais.

### CT-046: Transação com vírgula na descrição
**Dados de entrada:** `Paguei R$ 150,00 na loja de roupas, sapatos e acessórios`
**Resultado esperado:** Valor **150,00**; descrição mantém vírgulas; parser não confunde com decimal.

### CT-047: Clicar em botão de inline keyboard de sessão antiga
**Passos:**
1. Despesa A → Cancelar
2. Despesa B (nova keyboard)
3. Clicar Confirmar na keyboard A (cancelada)
**Resultado esperado:** Rejeita callback antigo; "Transação já cancelada/processada".

**Cobertura estendida (fix do picker — CT-047):**
- **Callback de X (resumo original) continua aceito** — `message_id_confirm` permanece como âncora válida.
- **Callback de Y (picker de edição) é aceito** — `message_id_edit_picker` é persistido como segunda âncora; o bug do picker de edição (clicar "Editar" e depois qualquer campo) está corrigido.
- **Picker é deletado ao concluir edição (decisão 3A)** — após o usuário responder validamente ao prompt de edição, o picker Y é removido do chat e seu `message_id_edit_picker` é limpo da sessão.
- **Botão "📝 Observações" adicionado ao picker (P7-B)** — 6º botão com `callback_data: 'edit:observations'`, layout 2 colunas × 3 linhas.
- **Teclado de confirmação restaurado após edição (decisão 1A)** — ao clicar "Editar", o teclado de X é removido; após edição concluída, o teclado [Confirmar][Editar][Cancelar] é restaurado em X.
- **Teclado de X removido em confirm/cancel (decisão 2A)** — ao confirmar ou cancelar, o teclado inline de X é removido (markup=null).
- **Picker é deletado após confirm e cancel (P3=B)** — em ambos, o `deleteMessage(Y)` é chamado (best-effort) antes de limpar a sessão.
- **Sessão legacy sem IDs válidos aceita callback** (P4=B) — não trava o usuário se a sessão estiver corrompida.

---

## Testes de Regressão

- [ ] Comandos básicos (/start, /help) funcionam após qualquer alteração
- [ ] Fluxo de texto natural não quebra com alterações no prompt
- [ ] Fluxo de imagem (OCR via Gemini) não quebra
- [ ] Google Sheets: dados existentes não corrompidos
- [ ] Firestore: estrutura de documentos mantém compatibilidade
- [ ] Inline keyboards funcionam após updates da API Telegram
- [ ] Webhook security (secret token + chat_id whitelist) ativo
- [ ] Timeout de sessão (15min) funcional
- [ ] Idempotência de confirmação não afetada

---

## Checklist de Validação

### Fluxo de Texto
- [ ] CT-001: Registrar despesa completa — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-002: Registrar receita — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-003: Texto sem valor — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-004: Tipo ambíguo — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-005: Data no passado — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-006: Formato brasileiro — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-006b: Variações de formato — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Fluxo de Imagem (OCR)
- [ ] CT-007: Nota fiscal legível — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-008: Foto ilegível — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-009: Imagem não-fiscal — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-010: Múltiplos valores — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Validação
- [ ] CT-011: Despesa sem categoria — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-012: Criar nova categoria — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-013: Valor inválido — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-014: Data futura — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-014b: Texto não numérico — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Confirmação
- [ ] CT-015: Confirmar (fluxo feliz) — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-016: Editar campo — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-017: Cancelar — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-018: Idempotência — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-018b: Confirmar após timeout — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Sugestão de Labels
- [ ] CT-019: Histórico — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-020: Keywords — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-021: Aceitar/recusar — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-022: Histórico melhora sugestão — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Comandos
- [ ] CT-023: /start — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-024: /help — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-025: /nova — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-026: /cancelar — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-027: /ultimos (default) — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-028: /ultimos com parâmetro — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-029: /categorias — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Persistência
- [ ] CT-030: Linha no Sheets — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-031: Documento no Firestore — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-032: Falha Sheets — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-033: Sincronização posterior — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Segurança
- [ ] CT-034: Sem secret token — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-035: Token inválido — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-036: Chat_id não autorizado — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Resiliência
- [ ] CT-037: DeepSeek indisponível — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-038: Timeout imagem — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-039: Recovery após erro — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Edge Cases
- [ ] CT-040: Mensagem longa — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-041: Emojis — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-042: Múltiplas rápidas — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-043: Sessão expirada — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-044: Mensagem vazia — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-045: Valor inteiro — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-046: Vírgula na descrição — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED
- [ ] CT-047: Callback de keyboard antiga — ⬜ PASS / ⬜ FAIL / ⬜ BLOCKED

### Aprovação Final
- [ ] Todos os testes de prioridade alta: ⬜ PASS
- [ ] Nenhum teste bloqueado sem justificativa
- [ ] Ambiguidades resolvidas (ver [Clarificações](./04-clarificacoes.md))
- [ ] **Aprovado por:** _______________ **Data:** ___/___/___
