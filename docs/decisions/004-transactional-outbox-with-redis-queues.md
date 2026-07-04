# Transactional Outbox with Redis Queue Delivery

## Context and Problem Statement

Side effects of state changes (ticket emails, ledger projection, search indexing) must never be lost and never fire for a rolled-back transaction. Queue dispatch alone cannot guarantee either.

## Considered Options

* Transactional outbox in PostgreSQL, delivered via Redis (Horizon) queues, reconciled by a sweeper
* Direct queue dispatch after commit, no durable event log
* Dedicated event broker (Kafka) from the start

## Decision Outcome

Chosen option: "Transactional outbox with Redis delivery", because writing events in the same transaction as the state change makes them exactly as durable as the data, the retained outbox doubles as a replay log for projections, and the sweeper covers every fast-path loss mode. Kafka adds operational weight the current scale does not justify; the outbox contract is the seam if that changes.
