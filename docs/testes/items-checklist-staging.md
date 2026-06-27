# Checklist de Regressão Manual — Feature Items (Staging)

> **Criado:** 2026-06-26 &nbsp;|&nbsp; **Feature:** `feature/items-dimension` &nbsp;|&nbsp; **Referência:** `docs/testes/`  
> **Status dos CTs:** ⬜ não executado | ✅ pass | ❌ fail | ⛔ blocked

---

## Ambiente de staging

| Item | Valor |
|------|-------|
| **Bot Telegram** | Bot de staging com token de desenvolvimento |
| **Planilha Sheets** | Planilha de staging compartilhada com a Service Account |
| **Firestore** | Projeto GCP de staging; collections `transactions`, `sessions` |
| **chat_id do testador** | Whitelistado em `TELEGRAM_ALLOWED_CHAT_IDS` |
| **.env de staging** | `APP_ENV=staging`, `APP_DEBUG=true` |

### Como resetar o ambiente entre testes

1. **Limpar sessão:** Firebase Console → Firestore → `sessions/{chat_id}` → Delete
2. **Limpar transações de teste:** deletar apenas transações criadas nesta sessão
3. **Limpar planilha:** manter cabeçalhos, deletar linhas de dados de teste

### Convenção para CTs de LLM (DeepSeek/Gemini)

> ⚠️ **LLM é não-determinístico.** Repetir o teste 3× e considerar a mediana (2 de 3 concordam). Anotar as 3 saídas.

---

## CTs Staging-Only (não automatizáveis)

Estes testes dependem de ambiente real (LLM, rede, cupons físicos) e não podem ser validados apenas com mocks:

### Extração Gemini (imagem)

| CT | Descrição | Status | Notas |
|----|-----------|--------|-------|
| CT-105 | Cupom fiscal com 50+ itens — Gemini extrai TODOS sem truncamento | ⬜ | Precisa de foto de cupom real com 50+ itens |
| CT-106 | Cupom com linha de DESCONTO (valor negativo) — aceitar `unitPrice < 0` | ⬜ | Cupom brasileiro com desconto na nota |
| CT-107 | Foto que não é nota fiscal → `items=[]`, sem crash | ⬜ | Foto de paisagem/cachorro |
| CT-108 | Cupom parcialmente ilegível (3 legíveis, 2 borrados) | ⬜ | Cupom danificado/borrado |

### Retrocompatibilidade real

| CT | Descrição | Status | Notas |
|----|-----------|--------|-------|
| CT-145 | Planilha com 8 colunas + items → coluna I escrita, I1 vazio | ⬜ | Precisa de planilha pré-feature |
| CT-146 | Planilha nova (linha 1 vazia) → 9 colunas com "Itens" em I1 | ⬜ | Planilha totalmente limpa |
| CT-147 | `/ultimos` lista transação antiga sem items — sem erro | ⬜ | Transação criada ANTES da feature |

### E2E com LLM real

| CT | Descrição | Status | Notas |
|----|-----------|--------|-------|
| CT-159 | Foto de cupom com 10 itens → Firestore + Sheets + Telegram (3 destinos) | ⬜ | Cupom real com 10 itens |

### Custo e performance

| CT | Descrição | Status | Notas |
|----|-----------|--------|-------|
| CT-162 | Custo de token Gemini para cupom de 50 itens (estimativa) | ⬜ | Anotar tokens usados e custo estimado |

### Segurança

| CT | Descrição | Status | Notas |
|----|-----------|--------|-------|
| CT-148 | CWE-1236: names com `=`, `+`, `-`, `@` são escapados no Sheets | ⬜ | Criar items maliciosos via wizard |
| CT-149 | XSS Telegram: `<script>` no name é escapado | ⬜ | Item com HTML injection |
| CT-163 | CWE newline: `\n` no name NÃO quebra a célula do Sheets | ⬜ | Name com quebra de linha (improvável via parser) |

---

## Instruções de setup para staging

### 1. Bot de staging
- Certificar que o webhook está registrado: `curl https://api.telegram.org/bot{TOKEN}/getWebhookInfo`
- O bot deve responder a `/start` e `/nova`

### 2. Planilha de staging
- Confirmar que a planilha está compartilhada com a Service Account (`google-sheet-202@...`)
- Para CT-145: planilha deve ter **8 colunas** de cabeçalho (A-H), I1 vazio
- Para CT-146: planilha deve estar **totalmente vazia** (linha 1 sem conteúdo)

### 3. Firestore de staging
- Para CT-147: ter pelo menos 1 transação criada ANTES da feature items (sem campo `items`)
- Verificar via Firebase Console: `transactions/{old_id}` sem `items`

### 4. Cupons fiscais para teste
- CT-105: cupom de supermercado com 50+ itens (foto nítida, JPEG/PNG < 20MB)
- CT-106: cupom com linha "DESCONTO" explícita (ex.: "- R$ 8,10")
- CT-159: cupom com exatamente ~10 itens (ideal para validar sem truncamento)

---

## Checklist rápido de funcionalidades

| Funcionalidade | Validar | Status |
|----------------|---------|--------|
| `/nova` → wizard → "Detalhar itens?" aparece após Descrição | Enviar `/nova`, preencher tipo→valor→descrição | ⬜ |
| Botão "✅ Sim" no sub-fluxo → prompt de items | Clicar Sim após pergunta | ⬜ |
| Sintaxe compacta: `Arroz x2 32.90` → parse correto | Enviar items no wizard | ⬜ |
| Botão "⏭️ Pular" → avança sem items | Clicar Pular | ⬜ |
| Enviar `pular` no campo items → items=[] | Digitar "pular" | ⬜ |
| Confirmar → coluna I no Sheets com items numerados | Abrir planilha após confirmar | ⬜ |
| Editar → botão "🛒 Itens" no picker | Clicar Editar na confirmação | ⬜ |
| Editar items → nova confirmação com items atualizados | Editar items e confirmar | ⬜ |
| Comando `limpar` zera items | Editar → 🛒 Itens → "limpar" | ⬜ |
| Múltiplas edições em sequência funcionam | Editar items 3× seguidas | ⬜ |
| `/ultimos` lista transações com items sem erro | `/ultimos 5` | ⬜ |
| Transação sem items → bloco "🛒 Itens:" NÃO aparece | Transação sem items (aluguel) | ⬜ |
| Labels + items coexistem | Transação com labels E items | ⬜ |

---

## Relatório de execução

| Data | Testador | CTs executados | Pass | Fail | Blocked | Observações |
|------|----------|---------------|------|------|---------|-------------|
| | | | | | | |
