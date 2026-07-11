# Stage 8a Execution Journal: Payments and Webhooks

## Run: 2026-07-11

- Stage: 8a
- Date: 2026-07-11 16:51 -03
- Branch: feat/api-implementation
- Base commit: 41b982a4bb5c56b8685de7fde582e059ed27f890
- Prior state: stage marked "Not started"; no Payments context exists; verified no payments or gateway_webhook_events migrations, no GatewayAdapter, no Payments directory under apps/api/app.

### Task checklist

- [ ] T1 feat(payments): GatewayAdapter interface, capability flags, FakeGateway with scenario controls and webhook emitter (slice 1)
- [ ] T2 feat(payments): payments table with RLS, PaymentStatus enum, conditional transition Actions (slice 2)
- [ ] T3 feat(payments): payment method offer endpoint with slow-method policy and low-inventory cutoff (slice 3)
- [ ] T4 feat(payments): payment initiation with Idempotency-Key semantics, sync approve and decline (slice 4 part 1)
- [ ] T5 feat(payments): async initiation, hold extension to method windows, PaymentInitiated (slice 4 part 2)
- [ ] T6 feat(payments): webhook ingestion with raw persistence and signature verification (slice 5)
- [ ] T7 feat(orders)+feat(payments): PaymentConfirmed and PaymentFailed consumers, ProcessGatewayWebhook normalization, system-design 4.3 amendment (slice 6)
- [ ] T8 feat(payments)+feat(orders): expiry sweeper, PaymentExpired, reconciliation poller, HandlePaymentExpired, system-design 9.3 registry update (slice 7)
- [ ] T9 feat(payments): per-gateway circuit breaker (slice 8)
- [ ] T10 feat(orders): confirmation_sent_at migration and SendOrderConfirmation consumer via Resend (slice 9)
- [ ] T11 feat(orders): GenerateTicketPdf consumer with medialibrary storage (slice 10)
- [ ] T12 feat(orders): activate staff resend-tickets action with qr_rotation_counter bump (slice 11 dependency)
- [ ] T13 test(payments): scripted-scenario integration matrix (slice 11)
- [ ] T14 docs: update roadmap Implementation Status and api-implementation-plan status table for Stage 8a

### Review rounds

### Decisions and deviations
