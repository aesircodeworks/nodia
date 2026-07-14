# Root task runner (research.md R4). Wraps the Compose stack in
# infra/compose/docker-compose.yml and the repo-wide setup steps documented
# in specs/001-project-foundation/quickstart.md.

.PHONY: setup up down fresh migrate

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
	@test -f apps/api/storage/oauth-private.key || php apps/api/artisan passport:keys

## Start postgres, redis, minio, and the api container, then apply migrations.
#
# --build, not a bare `up -d`: the api image bakes the source in at build time
# (no bind mount), so without it Compose happily starts the last image that
# built and serves stale code while the working tree moves on. --wait blocks
# until the healthchecks pass, so the migrate step below has something to talk to.
#
# The migrate step is what stops the stack from running with no schema. Compose
# creates an empty database and nothing else populates it, so every `up` applies
# whatever migrations are outstanding; `migrate` is idempotent, and on an
# already-current database it is a no-op.
up: .env
	$(COMPOSE) up -d --build --wait
	$(MAKE) migrate

## Apply outstanding migrations to the running stack's database.
migrate: .env
	$(COMPOSE) exec -T api php artisan migrate --force

## Stop the stack, keeping its volumes.
down: .env
	$(COMPOSE) down

## Recreate the stack from scratch: drop volumes, rebuild the api image, and start clean.
fresh: .env
	$(COMPOSE) down --volumes
	$(COMPOSE) up -d --build --wait
	$(MAKE) migrate
