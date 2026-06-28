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

# ----------------------------------------------------------------------------
# Variáveis DRY para ambiente dev isolado
# ----------------------------------------------------------------------------
# COMPOSE_DEV_FLAGS = -f docker-compose.yml -f docker-compose.dev.yml
# COMPOSE_PROJECT_NAME=wallet-track-dev isola network/volumes/containers de prod.
# Uso: $(COMPOSE_DEV) expande para o compose com override dev.
# ----------------------------------------------------------------------------
COMPOSE_DEV_FLAGS = -f docker-compose.yml -f docker-compose.dev.yml
COMPOSE_DEV = COMPOSE_PROJECT_NAME=wallet-track-dev docker compose $(COMPOSE_DEV_FLAGS)

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
# Dev Isolado
# ----------------------------------------------------------------------------
# Ambiente dev isolado (porta 8001, containers -dev, volumes *_dev).
# Compartilha a mesma imagem wallet-track:dev de prod. Pode rodar junto.
#
# Docker — containers dev
# ----------------------------------------------------------------------------
.PHONY: up-dev down-dev fresh-dev build-dev rebuild-dev restart-dev logs-dev ps-dev

up-dev:  ## Sobe os containers do dev isolado
	$(COMPOSE_DEV) up -d

down-dev:  ## Para os containers dev (preserva volumes)
	$(COMPOSE_DEV) down

fresh-dev:  ## Para e remove volumes dev (limpa vendor_dev, storage_dev, *_data_dev)
	$(COMPOSE_DEV) down -v

build-dev:  ## Constrói imagens para dev (reusa base)
	$(COMPOSE_DEV) build base
	$(COMPOSE_DEV) build

rebuild-dev:  ## Reconstrói dev do zero (sem cache)
	$(COMPOSE_DEV) build base --no-cache
	$(COMPOSE_DEV) build --no-cache

restart-dev:  ## Reinicia containers dev
	$(COMPOSE_DEV) restart

logs-dev:  ## Acompanha logs dev (Ctrl+C para sair)
	$(COMPOSE_DEV) logs -f

ps-dev:  ## Lista containers dev em execução
	$(COMPOSE_DEV) ps

# --- Acesso ao container dev ---
.PHONY: shell-dev bash-dev artisan-dev composer-dev

shell-dev:  ## Abre shell bash no container app dev
	$(COMPOSE_DEV) exec app bash

bash-dev: shell-dev  ## Alias de `make shell-dev`

artisan-dev:  ## Roda artisan no dev (uso: make artisan-dev cmd="migrate --seed")
	@test -n "$(cmd)" || { echo "Uso: make artisan-dev cmd=\"COMANDO\""; exit 1; }
	$(COMPOSE_DEV) exec app php artisan $(cmd)

composer-dev:  ## Roda composer no dev (uso: make composer-dev cmd="require vendor/pkg")
	@test -n "$(cmd)" || { echo "Uso: make composer-dev cmd=\"COMANDO\""; exit 1; }
	$(COMPOSE_DEV) exec app composer $(cmd)

# --- Setup / Qualidade ---
.PHONY: setup-dev key-dev migrate-dev tinker-dev test-dev pint-dev

setup-dev:  ## Build + up + composer install + migrate (uso: primeira vez)
	@$(MAKE) build-dev
	@$(MAKE) up-dev
	@$(MAKE) composer-dev cmd="install"
	@$(MAKE) migrate-dev
	@echo "$(GREEN)✔$(RESET) App dev em $(CYAN)http://localhost:8001$(RESET)"

key-dev:  ## Regenera a APP_KEY no dev
	$(COMPOSE_DEV) exec app php artisan key:generate

migrate-dev:  ## Roda migrations do dev
	$(COMPOSE_DEV) exec app php artisan migrate --force

tinker-dev:  ## Abre o Tinker no dev
	$(COMPOSE_DEV) exec app php artisan tinker

test-dev:  ## Roda testes no dev
	$(COMPOSE_DEV) exec app php artisan test

pint-dev:  ## Roda Pint no dev
	$(COMPOSE_DEV) exec app ./vendor/bin/pint

# --- Túnel dev (ngrok) ---
.PHONY: tunnel-up-dev tunnel-down-dev

tunnel-up-dev:  ## Sobe ngrok para dev, atualiza .env.dev, registra webhook do bot dev
	@./scripts/tunnel-up-dev.sh

tunnel-down-dev:  ## Encerra ngrok dev e remove webhook do bot dev
	@pgrep -f "ngrok http 8001" | xargs -r kill 2>/dev/null || true
	$(COMPOSE_DEV) exec -T app php artisan telegram:delete-webhook 2>/dev/null || true
	@echo "$(GREEN)✔$(RESET) Túnel dev encerrado e webhook removido."

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
