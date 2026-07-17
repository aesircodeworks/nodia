#!/bin/sh
# Creates the dedicated database the Isolation and Concurrency test suites
# connect to, so local test runs never touch the dev database. PostgreSQL only
# executes this on first cluster initialization (empty data volume); stacks
# created before this script existed need `make fresh` or a manual
# `createdb -U nodia nodia_test` inside the postgres container.
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
	CREATE DATABASE nodia_test OWNER "$POSTGRES_USER";
EOSQL
