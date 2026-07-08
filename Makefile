# Root task runner (research.md R4). Wraps the Compose stack in
# infra/compose/docker-compose.yml and the repo-wide setup steps documented
# in specs/001-project-foundation/quickstart.md.

.PHONY: setup up down fresh

APP_ENV_FILES := apps/api/.env apps/storefront/.env apps/admin/.env apps/checkin/.env

COMPOSE := docker compose -f infra/compose/docker-compose.yml --env-file $(CURDIR)/.env

# Copy each .env.example to .env where missing; never overwrites an existing .env.
.env: .env.example
	cp $< $@

apps/api/.env: apps/api/.env.example
	cp $< $@

apps/storefront/.env: apps/storefront/.env.example
	cp $< $@

apps/admin/.env: apps/admin/.env.example
	cp $< $@

apps/checkin/.env: apps/checkin/.env.example
	cp $< $@

## Copy every .env.example to .env where missing, then install Composer and pnpm dependencies.
setup: .env $(APP_ENV_FILES)
	composer install --working-dir=apps/api
	pnpm install

## Start postgres, redis, minio, and the api container.
up: .env
	$(COMPOSE) up -d

## Stop the stack, keeping its volumes.
down: .env
	$(COMPOSE) down

## Recreate the stack from scratch: drop volumes, rebuild the api image, and start clean.
fresh: .env
	$(COMPOSE) down --volumes
	$(COMPOSE) up -d --build
