# 05 — Revisão v2 (Laravel 13 + Gemini OCR)

> **⚠️ NOTA DE REMOÇÃO GCP (jun/2026):** A infraestrutura GCP (Cloud Run, Cloud Build, Cloud Scheduler, Artifact Registry, Secret Manager) foi removida. O deploy passou a ser via Docker container (VPS planejada). Referências a Cloud Run neste documento são históricas.

**Data:** 15 Jun 2026
**Versão original:** v1 (Laravel 12 + Google Cloud Vision)
**Versão revisada:** v2 (Laravel 13 + Gemini via AI Studio)

---

## ⚠️ Correção sobre Laravel

O usuário solicitou Laravel 14, mas **Laravel 14 ainda não existe**. A versão mais recente estável é **Laravel 13.x**, lançada em 17 de Março de 2026 (conforme [laravel.com/docs/13.x/releases](https://laravel.com/docs/13.x/releases)). O ciclo de releases é anual; o Laravel 14 sairia apenas em ~março/2027.

**Decisão:** Usar **Laravel 13.x** (confirmado pelo usuário). PHP 8.5 está dentro do range suportado (8.3–8.5).

| Versão | PHP | Release | Bug Fixes | Security Fixes |
|--------|-----|---------|-----------|----------------|
| 12 | 8.2–8.5 | 24 Fev 2025 | Ago 2026 | Fev 2027 |
| **13** | **8.3–8.5** | **17 Mar 2026** | **Q3 2027** | **Mar 2028** |

O upgrade de Laravel 12 → 13 é classificado como *"relatively minor upgrade"* pela documentação oficial.

---

## A. Stack Atualizada

| Componente | v1 (anterior) | v2 (atual) | Nota |
|------------|---------------|------------|------|
| PHP | 8.4 | 8.5 | Atualizado para 8.5 |
| Laravel | 12.x | **13.x** | Latest stable |
| FrankenPHP + Octane | — | — | Mantido |
| Telegram SDK | nutgram ^4.0 | nutgram ^4.0 | Standalone, sem acoplamento ao Laravel |
| IA Texto | DeepSeek v4-flash | DeepSeek v4-flash | Mantido |
| IA Visão | **Google Cloud Vision** (removido) | **Gemini 2.5 Flash** | NOVO |
| Cliente OCR | google/cloud-vision | **google-gemini-php/client ^2.7** | NOVO |
| Google Sheets | google/apiclient ^2.x | google/apiclient ^2.x | Mantido |
| Firestore | google/cloud-firestore ^1.x | google/cloud-firestore ^1.x | Mantido |

### Composer
```bash
composer require google-gemini-php/client:^2.7
composer remove google/cloud-vision    # REMOVER
```

**Fontes:**
- Laravel 13: https://laravel.com/docs/13.x/releases
- google-gemini-php/client: https://github.com/google-gemini-php/client (v2.7.4, 409★, 1.4M+ installs)
- Gemini models: https://ai.google.dev/gemini-api/docs/models

---

## B. Modelo Gemini Recomendado: `gemini-2.5-flash`

**Justificativa:**
1. **Multimodal nativo** — aceita imagem (base64) + texto no mesmo request
2. **Structured Output** — `responseMimeType: application/json` + `responseSchema` garantem JSON com campos exatos
3. **Custo-benefício** — Flash é significativamente mais barato que Pro para OCR
4. **Latência** — < 2s para OCR de nota fiscal típica
5. **PT-BR** — suporte nativo com excelente acurácia

**Fallback:** `gemini-2.0-flash` (GA estável) se a 2.5-preview apresentar instabilidade.

### Configuração
```php
// config/services.php
'gemini' => [
    'api_key'  => env('GEMINI_API_KEY'),
    'base_url' => 'https://generativelanguage.googleapis.com/v1beta/',
    'model'    => env('GEMINI_MODEL', 'gemini-2.5-flash'),
],
```

**Autenticação:** API Key simples (gerada em [aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey)). **NÃO** é Service Account. Independente da Service Account usada para Sheets + Firestore.

---

## C. Fluxo de OCR Simplificado

### Antes (v1): 2 chamadas encadeadas
```
Foto → Google Cloud Vision (OCR → texto bruto) → DeepSeek (parse JSON)
```
Problemas: 2 chamadas (latência dobrada), erro de OCR propagado, 2 custos.

### Agora (v2): 1 chamada única
```
Foto → Gemini multimodal (imagem → JSON estruturado, uma chamada)
```
Vantagens: menos latência, menos pontos de falha, Gemini lê imagem diretamente, structured output garantido, custo menor.

---

## D. Prompt do Gemini para OCR (multimodal + responseSchema)

O Gemini recebe a imagem como `inline_data` (base64) + prompt de sistema, e retorna JSON conforme `responseSchema`:

| Campo | Tipo | Regra |
|-------|------|-------|
| `description` | string | Nome do estabelecimento + itens. Máx 200 chars. |
| `amount` | number | **Valor TOTAL** da nota. Decimal (ex: `156.90`). |
| `type` | string | `expense` por padrão; `income` se nota de venda do usuário. |
| `category` | string | Inferir do contexto; `"outros"` se ambíguo. |
| `labels` | array<string> | Estabelecimento, tipo de compra, frequência. |
| `date` | string | ISO `YYYY-MM-DD`; `null` se ilegível. |
| `observations` | string | CNPJ, forma de pagamento; ou `null`. |

Configuração: `temperature: 0.1` (precisão), `topP: 0.95`, `maxOutputTokens: 1024`.

---

## E. Secrets Atualizados

| Secret | v1 | v2 |
|--------|----|----|
| `DEEPSEEK_API_KEY` | ✅ | ✅ Mantido |
| `GEMINI_API_KEY` | ❌ | ✅ **NOVO** |
| `GEMINI_MODEL` | ❌ | ✅ **NOVO** (`gemini-2.5-flash`) |
| `GOOGLE_CLOUD_VISION_CREDENTIALS` | ✅ | ❌ **REMOVIDO** |
| Service Account (Sheets + Firestore) | ✅ | ✅ Mantido |

### Escopo da Service Account (reduzido)
```
- Google Sheets API (leitura/escrita)
- Cloud Firestore API (leitura/escrita)
```
**REMOVIDO:** permissão `vision.googleapis.com`.

### .env (delta)
```env
# NOVO
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash

# REMOVIDO
# GOOGLE_CLOUD_VISION_CREDENTIALS=   ← NÃO EXISTE MAIS
```

---

## F. Impacto no Plano de Testes

| CT | Mudança | Novo comportamento esperado |
|----|---------|----------------------------|
| CT-007 | Interno: Gemini em vez de Vision+DeepSeek | Mesmo resultado; tempo **menor** (1 chamada vs 2) |
| CT-008 | Interno: Gemini multimodal | Performance **melhor** por entender contexto visual + textual. Campos ilegíveis → `null`. |
| CT-009 | Interno: Gemini | Identifica "não é nota fiscal" com mais precisão. |
| CT-010 | Interno: Gemini | Mesma regra: pega valor TOTAL. |

**Do ponto de vista do usuário: NADA muda.** Comportamento externo idêntico.

---

## G. Compatibilidade Laravel 13

### Breaking changes relevantes (12 → 13)

| Mudança | Impacto no wallet-track |
|---------|------------------------|
| PHP mínimo 8.3 | Nenhum (usa 8.5) |
| Novos atributos PHP (`#[Middleware]`, etc.) | Opcional |
| Queue routing (`Queue::route()`) | Opcional |
| Estrutura `bootstrap/app.php` | Compatível |

**Conclusão:** Upgrade de **baixíssimo impacto**. Classificado como "relatively minor upgrade".

### Compatibilidade dos pacotes

| Pacote | Compatível? |
|--------|-------------|
| nutgram/nutgram ^4.0 | ✅ (standalone) |
| openai-php/client | ✅ (HTTP genérico) |
| google/apiclient ^2.x | ✅ |
| google/cloud-firestore ^1.x | ✅ |
| google-gemini-php/client ^2.7 | ✅ (PHP 8.1+) |
| laravel/octane | ✅ (first-party) |

---

## Resumo das mudanças

| O quê | De | Para |
|-------|----|------|
| Framework | Laravel 12 | **Laravel 13.x** |
| OCR backend | Google Vision + DeepSeek (2 chamadas) | **Gemini 2.5 Flash** (1 chamada) |
| Pacote de OCR | google/cloud-vision | **google-gemini-php/client ^2.7** |
| Nº de chamadas OCR | 2 | **1** |
| Escopo Service Account | Vision + Sheets + Firestore | **Sheets + Firestore** |
| Novo secret | — | `GEMINI_API_KEY` |
| PHP mínimo | 8.2 | **8.3** (Laravel 13; já temos 8.5) |
