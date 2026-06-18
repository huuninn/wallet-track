# Wallet Track — Documentação do Projeto

Chatbot Telegram de controle financeiro pessoal (despesas + receitas) com extração por IA, validação, confirmação inline e gravação em Google Sheets + Firestore.

> **README principal:** [`../README.md`](../README.md) — visão geral, quick start, comandos do bot, stack.

---

## Índice de Documentos

| # | Documento | Descrição | Status |
|---|-----------|-----------|--------|
| 00 | **[Índice](./00-INDEX.md)** | Este documento | ✅ |
| 01 | **[Análise de Negócio](./01-analise-negocio.md)** | Entendimento do problema, entidades, premissas, funcionalidades, escopo, riscos | ✅ Aprovada |
| 02 | **[Especificação Técnica](./02-especificacao-tecnica.md)** | Arquitetura, stack, modelo de dados, fluxo de conversa, prompts, segurança, deploy | ✅ Aprovada |
| 03 | **[Plano de Testes Manuais](./03-plano-testes-manuais.md)** | 47 casos de teste cobrindo fluxos felizes, edge cases, segurança e resiliência | ✅ |
| 04 | **[Clarificações](./04-clarificacoes.md)** | 10 pontos ambíguos resolvidos com decisões definitivas | ✅ |
| 05 | **[Revisão v2](./05-revisao-v2.md)** | Atualização de stack: Laravel 13 + Gemini OCR (substituindo Google Vision) | ✅ Aprovada |
| 06 | **[Plano de Implementação](./06-plano-implementacao.md)** | 11 milestones (M0–M10) com dependências, riscos e critérios de aceitação | ✅ Aprovado |

---

## Resumo Executivo

### O que é
Assistente de registro de despesas/receitas operado via Telegram. O usuário envia informações de um gasto (texto livre ou foto de nota fiscal) para um bot. O sistema extrai e interpreta os dados, valida, sugere labels, confirma com o usuário e persiste em planilha Google Sheets + Firestore.

### Stack Final
- **PHP 8.4** + **Laravel 13.x** + **FrankenPHP** + **Octane**
- **Deploy**: Google Cloud Run
- **Telegram**: `nutgram/nutgram`
- **IA Texto**: DeepSeek `deepseek-v4-flash` (via `openai-php/client`)
- **IA Imagem/OCR**: Gemini `gemini-2.5-flash` (via Google AI Studio, `google-gemini-php/client`)
- **Planilha**: Google Sheets API (Service Account)
- **Persistência**: Firestore (NoSQL)

### Fluxo Principal
```
Texto/foto → IA extrai JSON → valida campos → sugere labels
         → usuário confirma (inline keyboard) → grava Firestore + Google Sheets
```

### Timeline de Implementação
~27 dev-dias (≈6,5 semanas com 1 desenvolvedor + buffer), divididos em 11 milestones.

```
M0 → M2 → M1 → M3 → M4 → [M5 ∥ M6 ∥ M7 ∥ M8] → M9 → M10
```

---

## Como usar esta documentação

- **Novo no projeto?** Leia 01 → 02 → 06 nesta ordem.
- **Vai implementar?** Siga o [Plano de Implementação](./06-plano-implementacao.md) a partir do M0.
- **Vai testar?** Use o [Plano de Testes](./03-plano-testes-manuais.md) com o checklist de validação.
- **Dúvida sobre comportamento?** Consulte as [Clarificações](./04-clarificacoes.md).
- **Mudanças recentes na arquitetura?** Veja a [Revisão v2](./05-revisao-v2.md).

## Pré-requisitos antes de implementar

Consulte a seção **"Pré-requisitos antes de começar"** no [Plano de Implementação](./06-plano-implementacao.md#6-pré-requisitos-antes-de-começar-m0). Inclui: Telegram Bot Token, Chat ID, DeepSeek API Key, Gemini API Key, Google Cloud Project + Service Account, Firestore, planilha Google Sheets.
