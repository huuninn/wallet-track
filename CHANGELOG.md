# Changelog

Todas as mudanças notáveis neste projeto serão documentadas aqui.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased]

### Added
- M9 — Comandos auxiliares: `/nova` (wizard 6 etapas), `/ultimos`, `/categorias`, `/sync`
- Comando Artisan `transactions:sync-pending` para sincronização manual/automática
- Rota `GET /cron/sync-pending` autenticada via `X-Cron-Token` para execução via Cloud Scheduler
- Middleware `App\Http\Middleware\VerifyCronToken` (timing-safe via `hash_equals`)
- Campo `notified_at` no Firestore para garantir notificação única de falha
- 9 testes de smoke (`#[Group('smoke')]`) cobrindo happy path de todos os handlers/commands/routes novos
- ~80 novos testes PHPUnit (521 totais, 0 falhas) — M9 adiciona cobertura para CT-023 a CT-061

### Changed
- `StartHandler` agora reseta sessão para IDLE em qualquer estado (resolve GAP-01)
- `CancelarHandler` detecta IDLE e responde "Nada para cancelar" (resolve GAP-02)
- `HelpHandler` marca todos os 7 comandos do M9 como ativos (resolve GAP-03)
- `ConversationRouter::pickNextAwaitingField` agora aceita `$session` opcional (retrocompatível)
- `ConversationRouter::presentConfirmation` limpa campos `_wizard_*` ao chegar na confirmação
- `TransactionData::withField` aceita `labels` (necessário para o wizard)

### Fixed
- Race condition `/sync` × cron: lock atômico via `FirestoreService::markSyncStarted` (campo `processing`)
- Reset de `sync_attempts` em `/sync` dá "mais 3 chances" ao usuário (Decisão Portão 2 #7)

### Security
- Endpoint `/cron/sync-pending` exige `X-Cron-Token` (32 bytes hex) — comparação timing-safe
- Resposta 401 genérica (`{"status":"error"}`) — não revela se o token está faltando ou inválido
- Rota adicionada à exclusion list CSRF em `bootstrap/app.php`

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
