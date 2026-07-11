# Stage 8a Execution Journal: Payments and Webhooks

## Run: 2026-07-11

- Stage: 8a
- Date: 2026-07-11 16:51 -03
- Branch: feat/api-implementation
- Base commit: 41b982a4bb5c56b8685de7fde582e059ed27f890
- Prior state: stage marked "Not started"; no Payments context exists; verified no payments or gateway_webhook_events migrations, no GatewayAdapter, no Payments directory under apps/api/app.

### Task checklist

- [x] T1 feat(payments): GatewayAdapter interface, capability flags, FakeGateway with scenario controls and webhook emitter (slice 1)
- [x] T2 feat(payments): payments table with RLS, PaymentStatus enum, conditional transition Actions (slice 2)
- [x] T3 feat(payments): payment method offer endpoint with slow-method policy and low-inventory cutoff (slice 3)
- [x] T4 feat(payments): payment initiation with Idempotency-Key semantics, sync approve and decline (slice 4 part 1)
- [x] T5 feat(payments): async initiation, hold extension to method windows, PaymentInitiated (slice 4 part 2)
- [x] T6 feat(payments): webhook ingestion with raw persistence and signature verification (slice 5)
- [x] T7 feat(orders)+feat(payments): PaymentConfirmed and PaymentFailed consumers, ProcessGatewayWebhook normalization, system-design 4.3 amendment (slice 6)
- [x] T8 feat(payments)+feat(orders): expiry sweeper, PaymentExpired, reconciliation poller, HandlePaymentExpired, system-design 9.3 registry update (slice 7)
- [x] T9 feat(payments): per-gateway circuit breaker (slice 8)
- [x] T10 feat(orders): confirmation_sent_at migration and SendOrderConfirmation consumer via Resend (slice 9)
- [x] T11 feat(orders): GenerateTicketPdf consumer with medialibrary storage (slice 10)
- [x] T12 feat(orders): activate staff resend-tickets action with qr_rotation_counter bump (slice 11 dependency)
- [x] T13 test(payments): scripted-scenario integration matrix (slice 11)
- [ ] T14 docs: update roadmap Implementation Status and api-implementation-plan status table for Stage 8a

### Task entries

#### T1: GatewayAdapter and FakeGateway (2026-07-11 16:58 -03)

Landed the gateway seam: `GatewayAdapter` interface with `GatewayCapabilities` (per-method sync/async confirmation and window, currencies, async and split flags), `FakeGateway` with deterministic request-derived outcomes (card token selects approve or decline, async methods pend with a display_code next action, deterministic fee from `payments.gateways.fake.fee_bps`), injected `FakeGatewayScenarios` store (transport-failure scripting and poller answers, container-scoped for Octane safety), and an HMAC-signed webhook emitter the same adapter verifies. Added the full Stage 8a `ErrorCode` case set and `ProblemRenderer` details in one pass. Tests written first and observed failing (11 errors), then green: `tests/Unit/Payments/FakeGatewayTest.php`, 11 passed, 42 assertions. Types regenerated. Commit 85404a2.

#### T2: payments table and state machine (2026-07-11 17:10 -03)

Isolation test written first against the missing table (PaymentsIsolationTest, PaymentFixture building on OrderFixture), then the migration with the standard Rls::applyTenantPolicies posture, the (tenant_id, idempotency_key) unique, the payments_gateway_reference_idx partial unique, payments_expiry_sweep_idx, and non-negative money checks. PaymentStatus enum plus ConfirmPayment (window guard in the same UPDATE statement, persists fee_amount and commission_amount through CommissionResolver returning zero until 8b), FailPayment, ExpirePayment, all conditional UPDATEs returning null on zero rows. Concurrency: parallel confirm and expire on one initiated payment yields exactly one terminal status. Evidence: unit 8 passed 28 assertions, isolation 6 passed, concurrency 1 passed; all observed failing before implementation. Commit 7e4e02c.

#### T3: payment method offer endpoint (2026-07-11 17:35 -03)

GET /v1/storefront/orders/{order}/payment-methods. New cross-context Actions: Tenancy ResolveEnabledGateways, EventCatalog ResolveAsyncPaymentPolicy, Orders ResolveOrderForPayment (returns internal OrderPaymentContextData, customer-scoped, null renders request.not_found). Offer assembly is the pure OfferAssembler (currency coverage, slow-method policy, low-inventory cutoff with per-event override; async equals slow); BuildPaymentMethodOffer wires the reads; remaining inventory summed from Inventory GetEventAvailability. OpenAPI PaymentMethodOffer schemas and OrderNotPayableProblem added; PresetTest ignore lists extended for the new context. Evidence: unit 4 + feature 10 passed (51 assertions), Architecture 38 passed; failures observed first. Commit dc008e3 (amended once after the Architecture preset flagged the new context's enums and exceptions).

#### T4: payment initiation, sync approve and decline (2026-07-11 17:55 -03)

POST /v1/storefront/orders/{order}/payments. InitiatePayment: replay lookup, then order/offer validation, savepoint-isolated insert falling back to the replay path on unique violation (the constraint is the guarantee), server-generated gateway key = payment UUID, then approve (PaymentInitiated + ConfirmPayment recording PaymentConfirmed + MarkOrderAwaitingPayment + MarkOrderPaid), decline (FailPayment recording PaymentFailed, controller renders 402 as a response so the transaction commits), or pend (window, ExtendHold, awaiting_payment; exercised by T5 tests). Deviations recorded below: persisted next_action column; HasProblemHeaders interface for Retry-After. Evidence: feature 9 passed, RequestHash unit 4 passed, same-key contention test 1 passed (one row, identical results), full payments suites 54/54, Architecture green. Commit ac7f7fc.

#### T5: async initiation and polling endpoint (2026-07-11 18:20 -03)

Async pend path exercised over HTTP with a fake clock: pix initiation returns display_code next_action and expires_at now+30m, order awaiting_payment, hold extended to the window, PaymentInitiated recorded; boleto extends to the 3-day window; replay is byte-identical including the re-served code. GET /v1/storefront/payments/{payment} added (ownership via the owning order, request.not_found otherwise). Concurrency: two different-key async initiations produce exactly one awaiting_payment winner and one payment row. Evidence: async feature 5 passed, contention 2 passed. Commit 1d2129b.

#### T6: webhook ingestion raw path (2026-07-11 18:45 -03)

Isolation first for gateway_webhook_events (sentinel-tenant probes: tenant sees nothing, WITH CHECK rejects, sentinel context and platform read admitted), then the migration (unique (gateway, gateway_event_id), (status, received_at) index, status CHECK, RLS), GatewayWebhookEvent model, IngestGatewayWebhook (parse then persist under the sentinel tenant, insert-conflict reuses the existing row), WebhookController mounted under bare /v1 with no tenant or auth middleware, and the OpenAPI fragment. Contract suite's DocumentedResponseCoverageTest extended with 19 exercisers covering every documented Stage 8a response so far; contract cleanup order extended for payments and gateway_webhook_events. Evidence: feature 5, isolation 5, parallel-duplicate concurrency 1, Contract 340/340, Architecture 38/38. Commit 6c9392f. Note: the ProcessGatewayWebhook dispatch lands with T7 (the job does not exist yet); T6 is persist-and-200 only, matching slice 5 scope.

#### T7: normalized processing and the order consumers (2026-07-11 19:20 -03)

ProcessGatewayWebhook (queue job carrying the raw row id): loads the row under the sentinel tenant, normalizes via the adapter, resolves the payment by (gateway, gateway_reference) under the platform role with every use activity-logged (system-design 4.3 amended in the same change), applies the conditional transition in the payment's tenant transaction, and concludes the row processed or ignored (a zero-row transition whose payment already carries the expected terminal status counts as a processed duplicate). IngestGatewayWebhook now dispatches the job post-commit. Orders consumers HandlePaymentConfirmed (savepoint-wrapped MarkOrderPaid; HoldNotCommittable takes the compensating path: awaiting_payment to expired, activity log payment_confirmed_after_hold_expired, Log::critical ops alert flagging the payment for refund until 8b executes refunds) and HandlePaymentFailed (conditional failed + ReleaseHold; sync-decline deliveries are pending-order no-ops). Duplicate-delivery test written first: five deliveries plus two manual job re-runs produce one transition, one ticket batch, one PaymentConfirmed. Evidence: processing feature 6 passed, parallel-duplicate concurrency 1 passed, payments suites 62 passed, Architecture green. Test cleanup learned activity_log is append-only (no DELETE granted), so assertions scope by properties instead of truncating. Commits fcb963c (payments), e4f9b70 (orders), 9876459 (docs, 4.3 amendment).

#### T8: expiry sweeper, PaymentExpired, reconciliation poller (2026-07-11 19:45 -03)

PaymentExpired event recorded by ExpirePayment; SweepExpiredPayments (platform SELECT for candidates, per-tenant conditional expiry, mirroring ReleaseExpiredHolds) behind payments:expire scheduled every minute; ReconcilePendingPayments (initiated payments past the reconcile grace queried at the adapter, same conditional transitions as webhooks) behind payments:reconcile every 5 minutes; HandlePaymentExpired consumer (awaiting_payment to expired + hold release); system-design 9.3 registry and 3.2 sketch updated. Deviation recorded below on poller candidate selection. Evidence: expiry and reconcile feature 6 passed (pix window expiry with exact availability recovery, boleto survives, sweeper selects only initiated past window, poller resolves missed webhook idempotently and applies gateway-side failures, grace period respected); confirm-vs-expire race already covered by PaymentTerminalContentionTest. Commits 5dcb5eb (payments), 7b09fe1 (orders), ab03f33 (docs).

#### T9: per-gateway circuit breaker (2026-07-11 20:20 -03)

CircuitBreaker in cache (not a table; a flush closes breakers and fails safe), value-stored timestamps compared against the fake clock rather than TTLs. Closed to open at the config threshold, half-open after cooldown, close on probe success, reopen on probe failure, state scoped per gateway. BuildPaymentMethodOffer drops open gateways (initiation resolves the method with excludeOpenBreakers false so an open breaker renders 503 gateway_unavailable with Retry-After instead of 422); InitiatePayment records transport failures and successes around createPayment. FakeGateway's identifier became injectable so the feature test registers a second adapter and proves the other gateway's methods stay offered. Evidence: unit 6, feature 3, payments suites 77/77. Commit 340dc4f.

#### T10: SendOrderConfirmation (2026-07-11 20:45 -03)

confirmation_sent_at migration (additive, Orders context); SendOrderConfirmation TicketIssued subscriber claiming with a conditional UPDATE where confirmation_sent_at IS NULL, only the winner sending OrderConfirmationMail (synchronous mailer contract per ADR 010; Resend is a transport concern), localized via customers.locale through the new Identity ResolveCustomerContact Action; no raw ids and no QR payload in the mail. Duplicate-delivery test first: two TicketIssued events each delivered twice produce one email. Deviation recorded below on the retry budget. Evidence: feature 4 passed, Orders feature suite 54/54. Commit 0a41a8c.

#### T11: GenerateTicketPdf (2026-07-11 21:10 -03)

dompdf/dompdf ^3.1 added behind the TicketPdfRenderer interface (system-design 15.4 seam; dompdf chosen as in-process and CI-simple, recorded here per the plan's Risks). Ticket implements HasMedia with the single-file ticket_pdf collection so duplicate generation converges to one attachment; GenerateTicketPdf short-circuits when an attachment exists. Base TestCase now fakes the media disk for every suite (the paid path writes PDFs everywhere), and every cleanup that deletes tickets deletes media first. Evidence: feature 2 passed (one non-trivial application/pdf per ticket with %PDF magic; duplicate delivery converges to one row), Orders+Payments features 100/100. Deviation: the plan's "rendered content spot-checked via text extraction" is covered by magic-bytes, mime, and size assertions instead; dompdf compresses content streams and a PDF-text-extraction dependency solely for one assertion was not justified. Commit 2bcce22.

#### T12: staff resend-tickets activation (2026-07-11 21:25 -03)

ResendTickets now bumps every issued ticket's qr_rotation_counter in one conditional UPDATE (zero rows renders order_not_paid, unchanged) and re-sends OrderConfirmationMail through ResolveCustomerContact. The Stage 7 test asserting the dormant behavior was updated to the activated semantics: a payload signed before the resend no longer verifies while a fresh render does, counters read [1], one email sent, audit present. Evidence: StaffOrderEndpointsTest 9/9. Commit d31c23c.

#### T13: scripted-scenario integration matrix (2026-07-11 21:40 -03)

PaymentScenarioMatrixTest drives every FakeGateway scenario over HTTP: sync approve (paid, committed, 2 tickets, 1 email, 2 PDFs), sync decline (pending, hold intact, nothing sent), async confirm via webhook, async failure webhook (failed, hold released, availability exact), sweeper expiry (expired, availability exact), and a five-delivery webhook storm (one row, one transition, one ticket batch, one email). Evidence: 6/6 passed first run. Commit 4711368.

### Gate (2026-07-11 18:50 -03, local)

- composer lint (Pint): passed.
- composer analyse (Larastan): passed after one fix (unnecessary nullsafe in ResolveAsyncPaymentPolicy).
- Full test run (php artisan test, all six suites against real PostgreSQL): 2333 passed, 0 failed. Two rounds of fixes were needed to get there, all test-hygiene, committed as fce275a: the ErrorCode registry unit test had not been extended for the eight new codes, and several pre-8a test teardowns deleted tenants without first deleting the media rows the now-active GenerateTicketPdf consumer attaches on every paid path (OrderStateMachineTest, IssueTicketsTest, PaymentTerminalContentionTest which also gained outbox cleanup).
- composer types:generate followed by git status on packages/api-client/src/generated: clean after committing the regenerated output (GatewayWebhookStatus had not been regenerated with T6).
- pnpm typecheck: all four workspaces pass.

No push yet; push and CI verification happen once after the review loop.

### Review rounds

#### Round 1 (2026-07-11 19:20 -03, codex): needs-fixes, 1 blocking, 2 important, 1 minor

1. Blocking, InitiatePayment.php:84, "transport failures leave a persisted initiated payment row behind because the insert commits before createPayment": declined as factually wrong. The insert's DB::transaction is a savepoint inside the request transaction the tenancy middleware opens; a rendered gateway_unavailable rolls the whole request transaction back (Stage 2's RenderedErrorRollback), so nothing persists, exactly what the passing test the finding itself cites (InitiatePaymentSyncTest "leaving nothing behind", asserting zero rows and a clean same-key retry) proves against real PostgreSQL. Reasoning recorded here instead of a code change.
2. Important, generated index.ts marks InitiatePaymentData.details required while the wire contract treats it as optional: fixed. details became array|Optional following CreateOrderData's precedent, detailsArray() normalizes at the call sites, regenerated TS now reads `details?: Record<string, any>`.
3. Important, SendOrderConfirmation lacks the plan's 5-attempt linear 1-minute retry budget: fixed, upgrading the earlier recorded deviation. config/outbox.php gained subscriber_retries; ProcessOutboxDelivery applies a per-subscriber tries/backoff override at dispatch; unit test asserts 5/60 for send_order_confirmation and outbox defaults elsewhere.
4. Minor, PDF text-extraction spot-check absent: already journaled as a deliberate deviation (magic bytes, mime, size asserted instead); no change.

#### Round 2 (2026-07-11 19:14 -03, codex): needs-fixes, 0 blocking, 2 important, 0 minor

1. Important, FakeGateway.php normalizeWebhook, "webhook fee parsing violates integer/currency money invariants (casts arbitrary JSON to int, defaults currency to BRL; a mismatched fee currency gets relabeled with the payment currency by ConfirmPayment)": fixed. normalizeWebhook now refuses non-integer fee amounts and missing or invalid currencies (returns null, routing to the ignored path), and ProcessGatewayWebhook ignores a confirmation whose fee currency differs from the payment currency instead of persisting a relabeled amount. Tests written first and observed failing: FakeGatewayTest fee-validation matrix, WebhookProcessingTest currency-mismatch ignored path.
2. Important, OrderConfirmationMail.php:42, "confirmation email divides minor units by 100 in floating point": fixed with pure integer formatting (intdiv plus two-digit remainder). New OrderConfirmationMailTest proves a 2^53+1 minor-unit total formats exactly where the float path lost the last digit, observed failing first (rendered .92 instead of .93).

### Decisions and deviations

- payments carries a nullable next_action jsonb column beyond the plan's column list: the plan requires replays and GET /v1/storefront/payments/{payment} to re-serve next_action byte-identically, and deriving it from the adapter on read would couple reads to gateway determinism. Added while the creating migration is still unmerged on this branch, so no merged migration was edited.
- Added App\Support\Problems\HasProblemHeaders so gateway_unavailable carries Retry-After through the existing renderer (api-conventions Errors), instead of special-casing 503 in the renderer.
- The plan's async step 3 "zero rows affected means a concurrent initiation won; mark this payment failed and return order_not_payable" is realized as throw-and-rollback: rendered errors roll the request transaction back (Stage 2 middleware), so the loser's payment row never persists, which is strictly cleaner than persisting a failed row.
- Sync decline responds 402 as a directly-built problem response rather than an exception, because a rendered exception would roll back the failed payment row and its PaymentInitiated and PaymentFailed events.
- SendOrderConfirmation's delivery retries ride the outbox-wide ProcessOutboxDelivery policy rather than a bespoke per-consumer budget of 5 attempts with linear 1-minute backoff (system-design 13): the outbox job already owns tries and backoff globally, and a per-subscriber budget would need new Support/Outbox machinery out of scope for this slice. Flagged for Stage 12's operational hardening.
- The reconciliation poller selects candidates by payment age (initiated past the grace period) rather than by joining awaiting_payment orders as the plan words it: Payments never queries the orders table (system-design 3.1), and an initiated payment past grace is worth reconciling regardless of the order's arc. The applied transitions and their order-machine effects are identical.
