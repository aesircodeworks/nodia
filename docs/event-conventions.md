# Domain Event Conventions

Rules for domain events crossing context boundaries through the transactional outbox. Mechanism rationale lives in [system-design.md](system-design.md) section 9 and ADR [004](decisions/004-transactional-outbox-with-redis-queues.md).

## Naming and Registry

- Event names are past-tense statements of fact named from the owning context's perspective: `TicketIssued`, `PaymentConfirmed`, `HoldExpired`. Never imperative or present tense.
- The catalog in system-design.md section 9.3 is the registry. Adding an event type updates that list in the same change.
- Event classes live in the owning context's `Events/` directory; only the owning context records them.

## Envelope

Every outbox row carries:

- `id`: event ID, UUIDv7
- `sequence`: monotonic global sequence number
- `type`: the event name
- `tenant_id`: non-null (sentinel platform tenant for platform-scope events)
- `aggregate_type` and `aggregate_id`: the entity the event is about
- `correlation_id`: propagated from the originating request
- `occurred_at`: UTC timestamp
- `payload`: the event data

Events are recorded in the same database transaction as the state change they describe, without exception.

## Payloads

- Payloads are laravel-data objects, `snake_case` keys, following the wire formats in [api-conventions.md](api-conventions.md) (UUID strings, UTC timestamps, money as amount plus currency).
- Payloads carry identifiers and the facts of the event, not full entity snapshots; consumers needing more load it through the owning context's Actions.
- Evolution is additive-only: new optional fields are allowed, existing fields never change meaning or type. A breaking change is a new event type (`TicketIssuedV2`), not a version field, because retained outbox rows are replayed and every consumer must handle every historical shape.

## Delivery and Consumption

- Queue jobs carry only the event ID; consumers load the envelope and payload from the outbox row.
- Consumers are idempotent by event ID and record progress in `outbox_deliveries`; duplicate delivery is normal, not an error.
- No ordering guarantee exists across the queue. Consumers requiring order (ledger projection) order per aggregate by outbox `sequence`, deferring events whose predecessors are unprocessed (system-design.md section 9.2).
- Consumers never write to another context's tables in response to an event; they call that context's Actions or update their own projections.
- Outbox rows are never deleted by delivery; retention and archival follow the schedule in system-design.md section 9.1.
