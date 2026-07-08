# Root task runner (research.md R4). Wraps the Compose stack in
# infra/compose/docker-compose.yml and the repo-wide setup steps documented
# in specs/001-project-foundation/quickstart.md.

.PHONY: setup up down fresh

APP_ENV_FILES := apps/api/.env apps/storefront/.env apps/admin/.env apps/checkin/.env

COMPOSE := docker compose -f infra/compose/docker-compose.yml --env-file $(CURDIR)/.env

# Copy each .env.example to .env where missing; never overwrites an existing .env.
# The example is an order-only prerequisite (after the |) so a newer .env.example
# (e.g. re-stamped by a git pull) does not trigger a rebuild that would clobber a
# developer's customized .env; the recipe runs only when .env itself is missing.
.env: | .env.example
	cp $| $@

apps/api/.env: | apps/api/.env.example
	cp $| $@

apps/storefront/.env: | apps/storefront/.env.example
	cp $| $@

apps/admin/.env: | apps/admin/.env.example
	cp $| $@

apps/checkin/.env: | apps/checkin/.env.example
	cp $| $@

## Copy every .env.example to .env where missing, then install Composer and pnpm dependencies.
setup: .env $(APP_ENV_FILES)
	composer install --working-dir=apps/api
	pnpm install
	@grep -q '^APP_KEY=base64:' .env || sed -i.bak "s|^APP_KEY=.*|APP_KEY=base64:$$(openssl rand -base64 32)|" .env && rm -f .env.bak
	@grep -q '^APP_KEY=base64:' apps/api/.env || php apps/api/artisan key:generate

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
