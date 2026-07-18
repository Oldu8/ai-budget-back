.DEFAULT_GOAL := help
.PHONY: help up down restart build build-nocache ps logs logs-db shell tinker db-shell \
        setup update install wait-for-app migrate migrate-status migrate-rollback fresh seed \
        categories-reset rates-sync rates-backfill \
        test test-filter coverage coverage-html coverage-text lint format analyse check key

DC := docker compose
EXEC := $(DC) exec -T app
EXEC_IT := $(DC) exec app

## ── Help ─────────────────────────────────────────────────────────

help: ## Show this help
	@echo "AI Budget Back — local dev commands (all run inside Docker)"
	@echo ""
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_-]+:.*?## / {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

## ── Containers ───────────────────────────────────────────────────

up: ## Start containers in the background
	$(DC) up -d

down: ## Stop containers
	$(DC) down

restart: down up ## Restart containers

build: ## Rebuild the app image (run after pulling changes to composer.json/composer.lock/package.json/Dockerfile)
	$(DC) build

build-nocache: ## Rebuild the app image from scratch, ignoring the Docker layer cache
	$(DC) build --no-cache

ps: ## Show container status
	$(DC) ps

logs: ## Tail app container logs
	$(DC) logs -f app

logs-db: ## Tail db container logs
	$(DC) logs -f db

shell: ## Open a shell inside the app container
	$(EXEC_IT) bash

tinker: ## Open php artisan tinker inside the app container
	$(EXEC_IT) php artisan tinker

db-shell: ## Open a psql shell on the dev database
	$(DC) exec db psql -U postgres -d ai_budget

## ── Onboarding a teammate's changes ──────────────────────────────

setup: ## Bring up a brand-new environment from scratch (build, start, migrate, seed)
	$(DC) build
	$(DC) up -d
	$(MAKE) wait-for-app
	$(EXEC) php artisan app:seed-initial

update: ## Pulled new commits? Run this: rebuilds the image, recreates the container, applies new migrations (non-destructive)
	$(DC) build
	$(DC) up -d --force-recreate app
	$(MAKE) wait-for-app
	$(EXEC) php artisan migrate --force
	@echo ""
	@echo "Up to date. If something still looks off, try 'make fresh' to reset all data."

# The app container's own start command (docker-compose.override.yml) runs
# migrations before it starts serving, so "responds on /api/health" doubles as
# "finished migrating" — wait for it instead of running migrate concurrently,
# which races the container's own migrate and throws spurious "already exists" errors.
wait-for-app: ## (internal) wait for the app container to finish booting
	@echo "Waiting for the app to finish booting (it runs migrations on its own on start)..."
	@for i in $$(seq 1 60); do \
		curl -sf http://localhost:8080/api/health >/dev/null 2>&1 && exit 0; \
		sleep 1; \
	done; \
	echo "Timed out waiting for the app. Check what's wrong: make logs"; exit 1

install: ## Refresh PHP autoload map after pulling new/moved files (no new composer dependency)
	$(EXEC) composer dump-autoload

## ── Database ─────────────────────────────────────────────────────

migrate: ## Apply pending migrations (safe, additive only)
	$(EXEC) php artisan migrate --force

migrate-status: ## Show which migrations have run
	$(EXEC) php artisan migrate:status

migrate-rollback: ## Roll back the last migration batch
	$(EXEC) php artisan migrate:rollback

fresh: ## ⚠️  DESTRUCTIVE — drop all tables, re-run all migrations, reseed initial data
	@echo "This will DROP ALL TABLES in the dev database and reseed from scratch."
	@read -p "Type 'yes' to continue: " confirm && [ "$$confirm" = "yes" ] || (echo "Aborted."; exit 1)
	$(EXEC) php artisan migrate:fresh --force
	$(EXEC) php artisan app:seed-initial

seed: ## Re-run initial seeders (categories, plans, statuses, visibility questions, admin user)
	# Note: categories:seed prunes categories removed from database/data/categories.php —
	# if real transactions still reference one of them, this fails safely (no rows change,
	# admin/plans/visibility steps just get skipped). Use `make fresh` to force a clean state.
	$(EXEC) php artisan app:seed-initial

categories-reset: ## ⚠️  DESTRUCTIVE — truncate categories (cascades to transaction_items, category settings, recurring expenses), then reseed
	@echo "This will TRUNCATE categories and CASCADE-delete transaction_items, app_user_category_settings, recurring_expenses."
	@read -p "Type 'yes' to continue: " confirm && [ "$$confirm" = "yes" ] || (echo "Aborted."; exit 1)
	$(EXEC) php artisan categories:reset
	$(EXEC) php artisan categories:seed

rates-sync: ## Sync recent exchange rates (ECB + NBU)
	$(EXEC) php artisan rates:sync

rates-backfill: ## Backfill historical exchange rates (ECB + NBU)
	$(EXEC) php artisan rates:backfill

## ── Quality checks ───────────────────────────────────────────────

test: ## Run the test suite
	$(EXEC) php artisan test

test-filter: ## Run tests matching a name, e.g. make test-filter name=ScanControllerTest (regex ok, e.g. name='FooTest|BarTest')
	$(EXEC) php artisan test --filter "$(name)"

coverage: ## Run tests with a per-file coverage summary (text, uses PCOV)
	$(EXEC) php artisan test --coverage --min=0

coverage-html: ## Generate an HTML coverage report into storage/coverage/html (open storage/coverage/html/index.html)
	$(EXEC) php artisan test --coverage-html storage/coverage/html --min=0
	@echo "Report: storage/coverage/html/index.html"

coverage-text: ## Plain-text per-class coverage with uncovered line ranges, no colors/HTML — for reading/parsing (storage/coverage/coverage.txt)
	@mkdir -p storage/coverage
	$(EXEC) php artisan test --coverage --min=0 > storage/coverage/coverage.txt
	@echo "Report: storage/coverage/coverage.txt"

lint: ## Check code style (Pint, no changes made)
	$(EXEC) ./vendor/bin/pint --test

format: ## Fix code style (Pint)
	$(EXEC) ./vendor/bin/pint

analyse: ## Run static analysis (PHPStan / Larastan)
	$(EXEC) ./vendor/bin/phpstan analyse --memory-limit=512M

check: test lint analyse ## Run test + lint + analyse — the full pre-commit gate

key: ## Generate a new APP_KEY (only if .env is missing one)
	$(EXEC) php artisan key:generate
