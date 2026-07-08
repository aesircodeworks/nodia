# UUIDv7 Primary Keys

## Context and Problem Statement

Externally visible identifiers must not be enumerable (order counts, ticket IDs leak business data), but random UUIDs fragment B-tree indexes on insert-heavy tables.

## Considered Options

- UUIDv7 everywhere
- UUIDv4 everywhere
- Sequential bigints internally with separate public identifiers

## Decision Outcome

Chosen option: "UUIDv7 everywhere", because time-ordered UUIDs keep index locality close to sequential keys while remaining non-enumerable, and a single identifier scheme avoids maintaining an internal-to-public mapping.
