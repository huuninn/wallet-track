# 01 — Análise de Negócio

> **⚠️ NOTA DE MIGRAÇÃO:** Este documento descreve a arquitetura original com Google Firestore como camada de persistência. A persistência foi **migrada para MariaDB**. As referências ao Firestore neste documento são **históricas** e refletem o estado na época da escrita. O componente `FirestoreService` foi substituído por `WalletStore` (Eloquent/MariaDB). As coleções `transactions`, `categories`, `labels` e `sessions` do Firestore correspondem agora às tabelas homônimas no MariaDB.

> **Fase 1 do pipeline de especificação.** Aprovada pelo usuário com as decisões consolidadas abaixo.

---

## 1. Reformulação do Problema

### Interpretação técnica

Trata-se de um **assistente de registro de despesas/custos e receitas** operado via Telegram. O usuário envia informações de uma transação (texto estruturado, linguagem natural ou foto de nota fiscal) para um bot. O sistema:

1. **Extrai e interpreta** os dados usando IA (DeepSeek para texto, Gemini para imagens/notas fiscais)
2. **Valida** campos obrigatórios (nome do custo, valor, categoria)
3. **Sugere labels** com base em histórico e palavras-chave
4. **Confirma** com o usuário se os dados estão corretos antes de gravar
5. **Persiste** os dados em uma planilha Google Sheets (destino principal) e no Firestore (fonte de verdade local)

### Domínio de negócio

**Controle financeiro pessoal** — registro de despesas e receitas com categorização e etiquetagem para posterior consulta/análise em planilha.

### Entidades de negócio

| Entidade | Descrição |
|----------|-----------|
| **Transação** | Um registro financeiro: despesa ou receita (nome, valor, data, categoria, labels, origem) |
| **Categoria** | Classificação do gasto (ex.: Alimentação, Transporte, Moradia, Salário) |
| **Label (Etiqueta)** | Tag adicional flexível (ex.: `#recorrente`, `#projeto-x`, `#cartao`) |
| **Usuário** | Quem interage com o bot via Telegram (chat ID). Uso pessoal (1 usuário). |
| **Planilha Google Sheets** | Destino de visualização (arquivo no Google Drive) |
| **Sessão de confirmação** | Estado temporário enquanto o usuário revisa os dados antes da gravação |

---

## 2. Premissas (confirmadas pelo usuário)

| # | Premissa | Decisão |
|---|----------|---------|
| P1 | Uso **pessoal** (1 usuário, 1 Telegram chat ID) | ✅ Confirmado |
| P2 | Planilha destino: **Google Sheets** (não Excel Online) | ✅ Confirmado — usuário tem Google Cloud configurado |
| P3 | Estrutura da planilha **definida pelo sistema** (não existia previamente) | ✅ Confirmado |
| P4 | Autenticação Google via **Service Account** (Sheets + Firestore) | ✅ Confirmado |
| P5 | Usuário possui **API Key do DeepSeek** | ✅ Confirmado |
| P6 | OCR de imagens via **Gemini** (Google AI Studio) — DeepSeek não tem visão | ✅ Confirmado (revisão v2) |
| P7 | Interface em **português do Brasil** | ✅ Confirmado |
| P8 | Deploy no **Google Cloud Run** | ✅ Confirmado |
| P9 | Stack: **Laravel 13.x + FrankenPHP + Octane** | ✅ Confirmado (revisão v2) |
| P10 | Persistência local: **Firestore** (não SQLite) | ✅ Confirmado |
| P11 | Intertação: **linguagem natural + comandos** | ✅ Confirmado |
| P12 | Escopo: **despesas E receitas** | ✅ Confirmado |
| P13 | Categorias: **lista inicial + adicionar novas** via bot | ✅ Confirmado |
| P14 | Labels: sugestão por **histórico + palavras-chave** | ✅ Confirmado |
| P15 | Data: **permite data passada + extrai da nota fiscal** | ✅ Confirmado |

---

## 3. Funcionalidades Identificadas

### F1 — Recebimento de custos via texto (Telegram)
O usuário envia mensagem de texto descrevendo a transação (linguagem natural ou comandos). O sistema usa DeepSeek para extrair os campos relevantes (descrição, valor, tipo, categoria, data).

### F2 — Extração de dados de imagens (OCR de notas fiscais)
O usuário envia foto de nota fiscal/comprovante. O Gemini (multimodal) extrai: nome do estabelecimento, valor total, data. Retorna dados para o usuário validar.

### F3 — Validação de campos obrigatórios
Verifica: descrição (não vazia), valor (numérico > 0), tipo (despesa/receita), categoria (válida ou nova). Se faltar algo, o bot pergunta especificamente.

### F4 — Sugestão automática de labels
Heurística em código PHP (sem LLM): histórico de labels usadas na mesma categoria + palavras-chave da descrição. Sugere até 5 labels.

### F5 — Fluxo de confirmação antes da gravação
Bot exibe resumo formatado com inline keyboard: `[✅ Confirmar] [✏️ Editar] [❌ Cancelar]`.

### F6 — Gravação na planilha Google Sheets
Via Sheets API + Service Account: adiciona nova linha com os dados confirmados.

### F7 — Persistência local (Firestore)
Toda transação é registrada no Firestore para auditoria, base de sugestão de labels e recuperação de falhas.

### F8 — Comandos auxiliares
`/start`, `/help`, `/nova` (wizard), `/cancelar`, `/ultimos [n]`, `/categorias`, `/sync`.

---

## 4. Escopo NÃO Coberto (Out of Scope)

- ❌ Multi-usuário / multi-tenant (cada um com sua planilha)
- ❌ Criação automática da planilha (usuário cria e compartilha manualmente)
- ❌ Edição/remoção de registros já gravados via bot
- ❌ Integração com meios de pagamento (cartão, PIX, bancos)
- ❌ Dashboard web ou relatórios gráficos
- ❌ Conversão de moeda estrangeira
- ❌ Rateio de custos entre categorias
- ❌ Suporte a múltiplos idiomas (apenas PT-BR)
- ❌ Exportação para outros formatos (PDF, CSV)

---

## 5. Riscos e Incertezas de Negócio

| Risco | Impacto | Prob. | Mitigação |
|-------|---------|-------|-----------|
| **Limites de API do Telegram** (30 msg/s) | Médio | Baixa (uso pessoal) | Fila; confirmar recebimento |
| **Custos do DeepSeek + Gemini** (cobrança por token/chamada) | Médio | Média | Cache; heurística de labels sem LLM; monitorar uso |
| **Latência OCR via Gemini** (5–30s por imagem) | Médio | Alta | Feedback de progresso em 4 etapas; processamento pós-resposta 200 |
| **Qualidade da extração** (IA alucina dados) | Alto | Média | **Fluxo de confirmação obrigatório**; validação de campos críticos |
| **Acesso à planilha Google Sheets** (permissões) | Alto | Baixa | Service Account com sharing explícito; health check |
| **Segurança de tokens/keys** em Cloud Run | Alto | Baixa | Secret Manager; variáveis de ambiente; chat_id whitelist |
| **Cold start Cloud Run** (min-instances=0) | Baixo | Média | Aceitar ~2s de cold start para uso pessoal |

---

## 6. Viabilidade

**✅ Viável**, com as seguintes condicionantes:

1. **DeepSeek** para texto é maduro e barato. Para imagem, **Gemini 2.5 Flash** é multimodal e suporta structured output (JSON garantido).
2. **Service Account** do Google Cloud simplifica autenticação (sem OAuth refresh token).
3. **Cloud Run + FrankenPHP + Octane** é stack moderna e adequada; o maior risco está no `app()->terminating()` para processamento deferred, com fallback documentado.
4. **Implementação em milestones** para reduzir risco (ver [Plano de Implementação](./06-plano-implementacao.md)).
