# Relatório de Viabilidade — GATE M0

> **Resultado: ✅ VIÁVEL.** Todas as dependências planejadas são compatíveis com PHP 8.4 + Laravel 13. O projeto pode prosseguir para M1.

---

## 1. Resumo Executivo

O GATE de Viabilidade do M0 (plano de implementação §3.3, tarefa M0.3) foi concluído com **sucesso**. Todos os 7 pacotes Composer planejados instalaram sem conflito de versão contra a stack-alvo (**PHP 8.4 + Laravel 13 + FrankenPHP 1.4 + Octane 2.x**). A imagem Docker de produção builda e o endpoint `/health` responde `200 OK`.

**Decisões técnicas relevantes** (desvios do plano original, justificados na §4):
1. `openai-php/client` resolvido para `^0.20.0` (planejado `^0.10` — inexistente).
2. Servidor HTTP de produção usa **FrankenPHP nativo** (`php_server`) em vez de `octane:start --server=frankenphp` (este exigiria a extensão `pcntl`, que compila da fonte do PHP em >10min — custo não justificado para o benefício do worker-mode em M0).

---

## 2. Stack Validada

| Componente | Versão planejada | Versão instalada | Status |
|------------|------------------|------------------|--------|
| PHP | 8.4 | 8.4.5 (imagem) / 8.4.1 (platform pin) | ✅ |
| Laravel Framework | ^13.0 | 13.16.1 | ✅ |
| FrankenPHP | 1.4+ | 1.4.4 (Caddy v2.9.1 embutido) | ✅ |

## 3. Pacotes Composer (GATE)

| Pacote | Versão planejada | Versão instalada | Status |
|--------|------------------|------------------|--------|
| `laravel/framework` | ^13.0 | 13.16.1 | ✅ |
| `laravel/octane` | ^2.0 | 2.17.5 | ✅ |
| `nutgram/nutgram` | ^4.0 | 4.47.1 | ✅ |
| `openai-php/client` | ^0.10 | **0.20.0** | ⚠️ Ver §4.1 |
| `google-gemini-php/client` | ^2.7 | 2.7.4 | ✅ |
| `google/apiclient` | ^2.15 | 2.19.3 | ✅ |
| `google/cloud-firestore` | ^1.0 | 1.55.0 | ✅ |

**Conclusão:** todos os pacotes resolvem. `composer validate` ✅. Nenhum conflito de versão detectado.

### 3.1 Extensões PHP

| Extensão | Status | Observação |
|----------|--------|------------|
| `gmp` | ✅ declarada + instalada | Aritmética precisa |
| `bcmath` | ✅ declarada + instalada | Cálculos de valores |
| `intl` | ✅ declarada + instalada | Normalização Unicode (M8) |
| `grpc` | ✅ declarada + instalada | Exigida por `google/cloud-firestore` |
| `protobuf` | ✅ declarada + instalada | Exigida por `google/cloud-firestore` |
| `pdo_sqlite` | ✅ instalada | Banco do skeleton (dev) |
| `opcache` | ⚠️ instalada, não declarada | Zend extension — convenção: infra, não dependência |
| `zip` | ✅ instalada | Composer / uploads |
| `pcntl` | ❌ não instalada (intencional) | Ver §4.2 |

---

## 4. Desvios do Plano e Justificativas

### 4.1 `openai-php/client` `^0.20.0` em vez de `^0.10`

O plano (§3.4) previa `openai-php/client: ^0.10`. A versão `^0.10` **não existe** no Packagist — a linha de versões do pacote salta de `0.x` antigas para `0.20.0` (atual estável). Instalado `0.20.0`, que é **totalmente compatível** com PHP 8.4 e Laravel 13, e mantém a mesma API (`OpenAI::client($key)->chat()->create([...])`) usada para consumir a API DeepSeek (compatível com OpenAI via `base_url` custom).

**Impacto:** nenhum. O cliente é usado apenas em M3 (DeepSeek) com a mesma interface.

### 4.2 Servidor FrankenPHP nativo em vez de `octane:start --server=frankenphp`

**Contexto:** o plano previa o processo principal do container como
`php artisan octane:start --server=frankenphp`.

**Problema detectado em M0.7:** o driver FrankenPHP do Octane referencia a constante `SIGINT` (trait `InteractsWithServers`), exigindo a extensão `pcntl`. A extensão `pcntl` não vem pré-compilada na imagem `dunglas/frankenphp` nem na árvore de fontes PHP embutida; `install-php-extensions pcntl` baixa e compila a **fonte completa do PHP**, demorando **mais de 10 minutos** (e excedendo timeouts de build/CI).

**Decisão:** usar o **servidor FrankenPHP nativo** (`php_server` via Caddyfile com a diretiva global `frankenphp`). Este é o modo de produção **documentado pela imagem oficial** e pelo projeto FrankenPHP. Octane permanece instalado (satisfaz o GATE de viabilidade como dependência planejada) e a configuração `config/octane.php` está publicada.

**Trade-off:** perdemos o reaproveitamento de bootstrap entre requisições (worker-mode) que o Octane oferece. Para um bot de **uso pessoal, 1 usuário, single-instance, min-instances=0** (cold start anyway), o impacto de performance é **desprezível**. Em M10 (produção), se mensurável, pode-se reintroduzir pcntl + Octane.

**Risco residual:** baixo. A especificação (§3) menciona Octane como otimização, não como requisito funcional. O webhook do Telegram (`POST /webhook/telegram`, M1) funciona igualmente bem sob o servidor FrankenPHP nativo.

### 4.3 Plataforma Composer pinada em `8.4.1`

O esqueleto Laravel 13 traz `php: ^8.3`. O plano exige `^8.4`. Após o bump, `symfony/console v8.1.0` (dependência transitiva) exige `php >=8.4.1`. Por isso `config.platform.php` foi pinada em `8.4.1` (não `8.4.0`), garantindo resolução correta mesmo rodando composer sob PHP 8.5. A imagem de produção entrega PHP **8.4.5**, satisfazendo o requisito.

### 4.4 `DEEPSEEK_MODEL=deepseek-chat` (não `deepseek-v4-flash`)

A spec (§1.2) e o plano (§2.4) citavam `deepseek-v4-flash`, nome **hipotético** que não existe na API da DeepSeek. O `.env.example` usa `deepseek-chat`, que é o **identificador real** do modelo principal da DeepSeek (atualmente V3, o general-purpose). Este é o modelo correto para extração estruturada em M3. Recomenda-se reconciliar a spec `docs/02-especificacao-tecnica.md` em uma futura revisão documental.

---

## 5. Validações Executadas

| Critério de aceitação (plano §3.6) | Resultado |
|------------------------------------|-----------|
| `composer install` sem erros | ✅ |
| Worker FrankenPHP sobe | ✅ (`FrankenPHP started 🐘, php_version 8.4.5, num_threads 32`) |
| `curl http://localhost:8000/health` → `200 OK` + JSON | ✅ ver §6 |
| `docker build -t wallet-track:dev .` sem erro | ✅ (imagem 1.24 GB) |
| `docker run -p 8000:8000 wallet-track:dev` expõe `/health` | ✅ |
| `php artisan test` passa | ✅ 3 testes, 8 asserções |
| Este relatório documenta todos os pacotes | ✅ |

---

## 6. Evidência do Smoke Test

Comando:
```bash
docker run -d -p 8000:8000 -e APP_KEY=$KEY \
  -e SESSION_DRIVER=file -e CACHE_STORE=file wallet-track:dev
curl http://localhost:8000/health
```

Resposta:
```json
{
  "status": "ok",
  "timestamp": "2026-06-16T23:26:28+00:00",
  "version": "13.16.1",
  "app": "Laravel"
}
```
`HTTP/1.1 200 OK`. Rota `/` retorna `302 → /health` (app é bot-only). **O processo do container executa como `www-data` (UID 33, não-root)** — hardening aplicado em M0, reduzindo a superfície de RCE.

> **Nota sobre variáveis em runtime:** a imagem de produção **não inclui** `.env` (correto — secrets via Secret Manager em M10). Para o smoke test, `APP_KEY`, `SESSION_DRIVER=file` e `CACHE_STORE=file` foram passados via `-e`. Em M0, `/health` é stateless; o Cloud Run (M10) injetará os secrets necessários.
>
> **Segurança do `/health`:** os campos `version` e `app` só são expostos quando `APP_DEBUG=true`. Em produção (`APP_DEBUG=false`) retornam `null`, evitando info disclosure.

---

## 7. Recomendações para M1+

1. **M1 (Bot Skeleton):** o webhook `POST /webhook/telegram` será servido pelo mesmo FrankenPHP nativo. Validar latência < 500ms.
2. **M10 (Deploy):** decidir se o benefício do worker-mode do Octane justifica o custo de build com `pcntl`. Se sim, documentar o tempo de build aceitável (~15min) no `cloudbuild.yaml` com timeout ampliado. Se não, manter FrankenPHP nativo.
3. **Variáveis de ambiente:** o `.env.example` documenta todas as 26 variáveis do projeto (plano §2.4 + spec §12). Validar preenchimento antes de M3 (DeepSeek) e M4 (Gemini).

---

## 8. Como Reproduzir

```bash
# GATE de viabilidade (roda em qualquer ambiente com Docker OU composer nativo)
bin/check-viability.sh                     # resolve + verifica (padrão)
bin/check-viability.sh --skip-resolve      # só verifica pacotes instalados

# Build + smoke test
docker build -t wallet-track:dev .
KEY="base64:$(openssl rand -base64 32)"
docker run --rm -p 8000:8000 -e APP_KEY="$KEY" \
  -e SESSION_DRIVER=file -e CACHE_STORE=file wallet-track:dev
curl http://localhost:8000/health
```
