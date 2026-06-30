# Changelog

Todas as mudanças notáveis neste projeto serão documentadas aqui.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased]

### Changed
- Renomeação do cabeçalho da coluna G da planilha de "ID Firestore" → "ID Transação" (o ID agora é inteiro do MariaDB, não UUID do Firestore)
- Reativação do target `migrate` do Makefile (app agora usa MariaDB; executa `docker compose exec app php artisan migrate --force`)
- Remoção da verificação de `google/cloud-firestore` do gate de viabilidade (`bin/check-viability.sh`)
- Higienização de comentários/docblocks em código ativo e documentação: referências ao Firestore como tecnologia atual substituídas por "banco de dados" ou "MariaDB"; referências históricas preservadas
- `StartHandler` agora reseta sessão para IDLE em qualquer estado (resolve GAP-01)
- `CancelarHandler` detecta IDLE e responde "Nada para cancelar" (resolve GAP-02)
- `HelpHandler` marca todos os 7 comandos do M9 como ativos (resolve GAP-03)
- `ConversationRouter::pickNextAwaitingField` agora aceita `$session` opcional (retrocompatível)
- `ConversationRouter::presentConfirmation` limpa campos `_wizard_*` ao chegar na confirmação
- `TransactionData::withField` aceita `labels` (necessário para o wizard)

### Added
- M9 — Comandos auxiliares: `/nova` (wizard 6 etapas), `/ultimos`, `/categorias`, `/sync`
- Comando Artisan `transactions:sync-pending` para sincronização manual/automática
- Rota `GET /cron/sync-pending` autenticada via `X-Cron-Token` para execução via Cloud Scheduler
- Middleware `App\Http\Middleware\VerifyCronToken` (timing-safe via `hash_equals`)
- Campo `notified_at` no banco de dados (MariaDB) para garantir notificação única de falha
- 9 testes de smoke (`#[Group('smoke')]`) cobrindo happy path de todos os handlers/commands/routes novos
- ~80 novos testes PHPUnit (521 totais, 0 falhas) — M9 adiciona cobertura para CT-023 a CT-061

### Fixed
- Bump `dunglas/frankenphp` de `1.4` → `1.12.4`. Resolve mismatch com `laravel/octane:^2.x` (≥ 1.5.0); evita o download de binário de 165MB que o Octane fazia em runtime como fallback.
- Race condition `/sync` × cron: lock atômico via `WalletStore::markSyncStarted` (campo `processing`)
- Reset de `sync_attempts` em `/sync` dá "mais 3 chances" ao usuário (Decisão Portão 2 #7)

### Security
- Endpoint `/cron/sync-pending` exige `X-Cron-Token` (32 bytes hex) — comparação timing-safe
- Resposta 401 genérica (`{"status":"error"}`) — não revela se o token está faltando ou inválido
- Rota adicionada à exclusion list CSRF em `bootstrap/app.php`

### Removed
- `database/migrations/0001_01_01_000002_create_jobs_table.php` (criava `jobs`, `job_batches`, `failed_jobs` — todas dead, queue nunca foi despachada)
- `config/queue.php` (sem queue, sem config)
- `php artisan queue:listen` do script `dev` em `composer.json` (com ajuste dos `--names` para 3 serviços: `server,logs,vite`)

## [0.8.0] - 2026-XX-XX

### Added
- M8 — Sugestão heurística de labels e categoria
- `App\Actions\SuggestCategory` e `App\Actions\SuggestLabels` (sem LLM — keywords + histórico)

## [0.7.0] - 2026-XX-XX

### Added
- M7 — Máquina de estados + Conversation Router
- `App\Enums\ConversationState` (IDLE, AWAITING_DATA, AWAITING_CONFIRMATION, AWAITING_EDITION)
- `App\Conversation\ConversationRouter` com dispatch centralizado
- `App\Conversation\StateMachine` (transições válidas)
- Confirmação inline com botões (Confirmar / Editar / Cancelar)

## [0.6.0] - 2026-XX-XX

### Added
- M5 — Persistência no Firestore (transactions, categories, labels, sessions)
- M6 — Sincronização com Google Sheets (`App\Actions\SyncSheet`)

## [0.0.0] - 2026-XX-XX

### Added
- M0–M4 — Skeleton do bot, webhook Telegram, extração DeepSeek (texto) e Gemini (imagem)
