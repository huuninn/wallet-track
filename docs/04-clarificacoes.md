# 04 — Clarificações

> Adendo à especificação técnica resolvendo 10 pontos ambíguos identificados pelo `manual-tester`. Estas decisões são **definitivas** e substituem qualquer redação ambígua na especificação original.

---

## 1. Comportamento para imagem não-fiscal (CT-009)

**Decisão:** O bot tenta extrair o que puder via Gemini e, se não conseguir campos válidos, admite a falha e abre canal para texto — **sem forçar o comando `/nova`**.

**Comportamento:** Bot informa "Não identifiquei uma transação clara nesta imagem 🙁. Você pode descrever a transação por texto ou enviar outra foto." O estado permanece ativo aguardando texto ou nova imagem.

**Justificativa:** Mantém o fluxo conversacional sem impor comandos extras. Evita frustração.

---

## 2. Valores negativos e zero (CT-013)

**Decisão:**
- **Valor negativo**: **Rejeita** e pede valor positivo. Mensagem: *"O valor da transação deve ser positivo. Use o formato `R$ 50,00` ou apenas `50,00`."*
- **Valor zero**: **Rejeita** (transação deve ter valor > 0). Mensagem: *"O valor precisa ser maior que zero."*

**Regra:** Parser e validator aplicam `valor > 0` como invariante. Nunca tratam negativo como absoluto (mascara erro do usuário).

**Justificativa:** É controle pessoal de gastos, não contabilidade de partidas dobradas. Estornos futuros podem ser `type: refund` com valor positivo.

---

## 3. Data futura (CT-014)

**Decisão:** **Alerta e pede confirmação.**

**Comportamento:** *"A data informada (25/12/2026) está no futuro. Você quer registrar uma despesa prevista ou confirmar essa data? (sim / não / use `hoje` para data atual)"*

**Justificativa:** O usuário pode registrar conta agendada, mas a confirmação forçada previne erros de digitação invisíveis (ex: 2026 em vez de 2025).

---

## 4. Algoritmo exato da heurística de sugestão de labels (CT-020)

### Constantes
```php
MAX_SUGGESTED_LABELS = 5;
MIN_TOKEN_LENGTH     = 3;
HISTORY_LIMIT        = 10;

STOPWORDS_PT_BR = [
    'de','da','do','das','dos',
    'em','no','na','nos','nas',
    'a','o','as','os','ao','à','às','aos',
    'e','ou','mas','que','se','não',
    'com','para','pra','por','pelo','pela','pelos','pelas',
    'um','uma','uns','umas',
    'é','foi','são','está','estava',
];
```

### Passo a passo

1. **Extrair keywords da descrição**
   - Converte para minúsculas + `Normalizer::normalize($desc, Normalizer::FORM_KD)` (remove acentos)
   - Tokeniza por espaços/pontuação: `preg_split('/[\s,.;:!?()\[\]{}\-—\/]+/u', ...)`
   - Remove tokens em `STOPWORDS_PT_BR`
   - Remove tokens com comprimento < `MIN_TOKEN_LENGTH` (3)
   - Remove duplicatas mantendo ordem de primeira ocorrência

2. **Buscar histórico no Firestore na MESMA categoria**
   - Query na collection `labels`, filtrando por categoria
   - Ordena por `use_count DESC`, limita a `HISTORY_LIMIT` (10)

3. **Merge com regra de prioridade**
   - **Fase A — Histórico** (prioritário): adiciona labels do histórico (já ordenadas) até atingir `MAX_SUGGESTED_LABELS`
   - **Fase B — Keywords**: se ainda há vagas, percorre keywords extraídas (FIFO) e adiciona as novas
   - Resultado: histórico tem prioridade máxima sobre keywords

4. **Histórico vazio (primeira transação na categoria)**
   - Fase A é pulada; Fase B roda normalmente
   - Se também não houver keywords → array vazio; usuário digita labels manualmente
   - **NUNCA inventa labels**

---

## 5. Fluxo do comando `/nova` (CT-025)

**Decisão:** **Wizard passo-a-passo com atalho de linguagem natural.**

### Sequência do wizard

| Etapa | Pergunta do bot | Validação |
|-------|-----------------|-----------|
| 1 | "Qual o tipo da transação? (despesa / receita)" | Apenas "despesa" ou "receita" |
| 2 | "Qual o valor? Ex: `R$ 50,00` ou `50.00`" | `value > 0`, numérico |
| 3 | "Descreva a transação em poucas palavras:" | Mín 2, máx 500 chars |
| 4 | "Qual a categoria?" + inline keyboard com top categorias + "digitar outra" | Existente OU criar nova (confirma) |
| 5 | "Quer adicionar labels? (separadas por vírgula, ou 'pular')" | Mín 2 chars por label; "pular" omite |

**Regra adicional:** A qualquer momento, o usuário pode enviar descrição livre completa que o DeepSeek tenta extrair (pulando o wizard). O wizard é o fallback determinístico quando o DeepSeek falha.

---

## 6. `/ultimos` com parâmetro inválido (CT-028)

**Decisão:** Regra única e defensiva:

```php
$n = intval($param);
if ($n < 1 || $n > 50) {
    $n = 5; // fallback silencioso
}
$n = min($n, $totalTransactions);
```

| Entrada | Resultado |
|---------|-----------|
| `/ultimos abc` | 5 (fallback) |
| `/ultimos -3` | 5 (fallback) |
| `/ultimos 0` | 5 (fallback) |
| `/ultimos 999999` | 50 (cap) |
| `/ultimos` (sem parâmetro) | 5 (default) |

**Limite máximo:** 50 (acima disso a mensagem fica ilegível; usar a planilha).

---

## 7. Mecanismo de sincronização posterior (CT-033)

**Decisão:** **Comando artisan `transactions:sync-pending` via Cloud Scheduler.**

| Parâmetro | Valor |
|-----------|-------|
| Frequência | A cada **5 minutos** |
| Comando | `php artisan transactions:sync-pending` (via endpoint `/cron/sync-pending` protegido por token) |
| Máximo de tentativas | **3** (contador `sync_attempts` no Firestore) |
| Após 3 falhas | `sync_status = 'failed'` + notifica usuário no Telegram |
| Comando `/sync` manual | Reseta contador e enfileira |

### Campos no Firestore (`transactions/{id}`)
```
sync_status:           "pending" | "synced" | "failed"
sync_attempts:         integer
sync_last_attempt_at:  timestamp | null
sync_error_message:    string | null
```

### Comportamento
1. Query: `sync_status='pending'` AND `sync_attempts < 3`, ordenado por `created_at ASC`, limit 20
2. Para cada: tenta append na Sheets
3. Sucesso → `sync_status='synced'`, `sync_attempts += 1`
4. Falha → `sync_attempts += 1`, registra erro
5. `sync_attempts >= 3` → `sync_status='failed'` + notifica usuário

---

## 8. Feedback de processamento longo de imagem (CT-038)

**Decisão:** **Editar a mesma mensagem em 4 etapas.**

```
[1] "📸 Processando imagem... (1/4: lendo imagem)"
[1] "📸 Processando imagem... (2/4: analisando com IA)"     ← edita
[1] "📸 Processando imagem... (3/4: extraindo dados)"        ← edita
[1] "📸 Processando imagem... (4/4: formatando resultado)"   ← edita
[1] Resultado final + inline keyboard                         ← edita
```

Usa `editMessageText` na MESMA mensagem (mantém `message_id`). Máximo 4 edições — bem abaixo de qualquer rate limit.

**Fallback:** Se demorar > 10s, envia segunda mensagem "Ainda processando... ⏳" e a deleta ao terminar.

---

## 9. Limite de caracteres da descrição (CT-040)

**Decisão:** **Máximo 500 caracteres. Se exceder: trunca com "..." e notifica.**

| Camada | Limite | Comportamento |
|--------|--------|---------------|
| Validação (input) | 500 chars | Trunca em 497 + "..." |
| Firestore | 500 | `string|max:500` |
| Google Sheets | 50.000 | Sem truncamento adicional |

**Notificação:** Se houve truncamento → *"Sua descrição foi resumida para 500 caracteres."*

**Justificativa:** 500 chars (~80-100 palavras) é suficiente para descrever uma transação pessoal. Truncar com aviso é melhor que rejeitar ou aceitar tudo.

---

## 10. Emojis e caracteres especiais (CT-041)

**Decisão:** **Permitir emojis livremente em todas as camadas.**

| Camada | Suporte |
|--------|---------|
| Telegram | ✅ UTF-8 nativo |
| Firestore | ✅ UTF-8 nativo |
| Google Sheets API | ✅ Aceita via UTF-8 (`stringValue`) |
| DeepSeek/Gemini | ✅ UTF-8 |

**Sanitização:** Nenhuma. O usuário pode usar 🍕 para "pizza", 🚕 para "transporte", etc.

**Única restrição:** Labels com comprimento < 2 caracteres (após trim) são rejeitadas por validação de label, não por sanitização de emoji.

---

## Resumo das decisões

| # | Item | Decisão |
|---|------|---------|
| 1 | Imagem não-fiscal | Admite falha + abre canal para texto |
| 2 | Valor negativo/zero | Rejeita, exige > 0 |
| 3 | Data futura | Alerta e pede confirmação |
| 4 | Labels | Histórico prioritário sobre keywords; máx 5 |
| 5 | `/nova` | Wizard passo-a-passo (Tipo→Valor→Descrição→Categoria→Labels) |
| 6 | `/ultimos` inválido | Fallback silencioso para 5; máx 50 |
| 7 | Sync pendente | Cron 5min, 3 retries, notifica em falha |
| 8 | Progresso imagem | Edita mesma mensagem em 4 etapas |
| 9 | Descrição longa | Máx 500 chars, trunca com "..." |
| 10 | Emojis | Permitidos em todas as camadas |
