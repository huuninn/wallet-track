# ============================================================================
# Wallet Track — Makefile
# ----------------------------------------------------------------------------
# Índice fino: cada alvo delega para docker compose ou scripts/*.sh.
# Self-documentado: `make` (ou `make help`) lista os alvos disponíveis.
# ============================================================================

.DEFAULT_GOAL := help

# Cores (desativadas se stdout não for TTY).
ifdef COLOR
GREEN  := \033[32m
CYAN   := \033[36m
YELLOW := \033[33m
RESET  := \033[0m
endif

.PHONY: help
help:  ## Mostra esta ajuda
	@awk 'BEGIN {FS = ":.*## "} \
		/^[a-zA-Z_-]+:.*?## / \
		{printf "  $(CYAN)%-15s$(RESET) %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# ----------------------------------------------------------------------------
# Docker
# ----------------------------------------------------------------------------
.PHONY: up down fresh build rebuild restart logs ps

up:  ## Sobe os containers
	docker compose up -d

down:  ## Para os containers (preserva volumes)
	docker compose down

fresh:  ## Para e remove volumes (limpa vendor, storage, node_modules)
	docker compose down -v

build:  ## Constrói as imagens (usa cache)
	docker compose build base
	docker compose build

rebuild:  ## Reconstrói do zero (sem cache)
	docker compose build base --no-cache
	docker compose build --no-cache

restart:  ## Reinicia containers (recicla OPcache e workers PHP — pega mudanças de config/.env/bootstrap)
	docker compose restart

logs:  ## Acompanha os logs (Ctrl+C para sair)
	docker compose logs -f

ps:  ## Lista containers em execução
	docker compose ps

# ----------------------------------------------------------------------------
# Acesso ao container da app
# ----------------------------------------------------------------------------
.PHONY: shell bash artisan composer

shell:  ## Abre shell bash no container da app
	docker compose exec app bash

bash: shell  ## Alias de `make shell`

artisan:  ## Roda artisan (uso: make artisan cmd="migrate --seed")
	@test -n "$(cmd)" || { echo "Uso: make artisan cmd=\"COMANDO\""; exit 1; }
	docker compose exec app php artisan $(cmd)

composer:  ## Roda composer (uso: make composer cmd="require vendor/pkg")
	@test -n "$(cmd)" || { echo "Uso: make composer cmd=\"COMANDO\""; exit 1; }
	docker compose exec app composer $(cmd)

# ----------------------------------------------------------------------------
# Qualidade de código
# ----------------------------------------------------------------------------
.PHONY: test pint

test:  ## Roda a suíte de testes (phpunit)
	docker compose exec app php artisan test

pint:  ## Roda o linter Pint
	docker compose exec app ./vendor/bin/pint

# ----------------------------------------------------------------------------
# Setup e manutenção
# ----------------------------------------------------------------------------
.PHONY: setup key migrate tinker

setup: build up  ## Build + up (uso: primeira vez)
	@echo "$(GREEN)✔$(RESET) App em $(CYAN)http://localhost:8000$(RESET)"

key:  ## Regenera a APP_KEY
	docker compose exec app php artisan key:generate

migrate:  ## Roda as migrations do banco de dados (MariaDB)
	docker compose exec app php artisan migrate --force

tinker:  ## Abre o Tinker (REPL do Laravel)
	docker compose exec app php artisan tinker

# ----------------------------------------------------------------------------
# Túnel público (Telegram webhook)
# ----------------------------------------------------------------------------
.PHONY: tunnel-up tunnel-down tunnel-status

tunnel-up:  ## Sobe ngrok, atualiza .env, registra webhook no Telegram
	@./scripts/tunnel-up.sh

tunnel-down:  ## Encerra ngrok e remove o webhook do Telegram
	@pgrep -f "ngrok http 8000" | xargs -r kill 2>/dev/null || true
	docker compose exec -T app php artisan telegram:delete-webhook 2>/dev/null || true
	@echo "$(GREEN)✔$(RESET) Túnel encerrado e webhook removido."

tunnel-status:  ## Mostra a URL pública atual do ngrok
	@curl -s http://localhost:4040/api/tunnels 2>/dev/null \
		| jq -r '.tunnels[] | select(.proto=="https") | .public_url' \
		2>/dev/null \
		|| echo "(ngrok não está rodando)"

# ----------------------------------------------------------------------------
# Frontend (Vite dev server com HMR)
# ----------------------------------------------------------------------------
.PHONY: vite-up vite-down

vite-up:  ## Sobe o Vite dev server (http://localhost:5173)
	docker compose --profile frontend up -d vite
	@echo "$(GREEN)✔$(RESET) Vite em $(CYAN)http://localhost:5173$(RESET)"

vite-down:  ## Para o Vite dev server
	docker compose --profile frontend stop vite
