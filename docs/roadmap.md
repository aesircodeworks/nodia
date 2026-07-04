# Nodia Implementation Roadmap

This roadmap breaks implementation into vertical slices that can be built, tested, and demoed end to end. It prioritizes the true MVP: one organizer can publish a general-admission event, one buyer can purchase a ticket through one payment gateway, and door staff can validate that ticket.

The system design remains the architectural source of truth. This document is sequencing guidance, not a commitment to build every eventual capability before launch.

## MVP Scope

The MVP should prove the platform's hardest product and correctness assumptions with the smallest useful surface area.

Included:

- Single Laravel API, storefront, admin portal, and check-in PWA in the monorepo
- Multi-tenant foundation with one platform domain plus tenant subdomains or configured custom domains
- Staff authentication, tenant membership, basic capability checks, and MFA for platform-scope staff and financially privileged roles
- Customer guest checkout
- General-admission events only
- One currency and one payment gateway adapter
- Tenant sub-merchant onboarding and the selected gateway's minimum viable payout flow
- Inventory holds, order state machine, webhook confirmation, ticket issuance
- Double-entry ledger entries for paid orders and refunds
- Basic refunds if required by the selected gateway and launch market
- Basic email confirmation with QR ticket
- Online-first check-in with a small offline buffer
- Minimal organizer reporting: orders, tickets sold, gross sales, check-ins
- Customer PII anonymization and export for GDPR and LGPD requests
- Production deployment path with backups, logs, errors, and smoke tests

Deferred:

- Reserved seating
- Multiple gateways and payment-method routing
- Marketplace payout automation beyond the selected gateway's minimum viable flow
- High-demand waiting room and advanced bot mitigation
- Full offline-first check-in reconciliation across many devices
- Promo codes
- Multi-language storefronts beyond the default locale
- Advanced reporting, exports, product analytics, and read-model rebuild tooling
- Native mobile check-in app
- Dedicated broker or extracted services

## Phase 0: Project Foundation

Goal: establish a runnable skeleton and development loop.

Vertical slice:

- Create monorepo structure: `apps/api`, `apps/storefront`, `apps/admin`, `apps/checkin`, `packages/api-client`, `packages/ui`, `infra`.
- Bootstrap Laravel API, Next.js storefront, Next.js admin, and static React check-in app.
- Add local Docker Compose for PostgreSQL, Redis, object storage, and the API.
- Add CI for linting, static analysis, unit tests, and app builds.
- Define shared API, data, event, and UI conventions as enforceable defaults.

Exit criteria:

- A developer can start the stack locally and see all apps call a health endpoint.
- CI runs on every change.
- The API has a versioned `/v1/health` endpoint and structured JSON logs.

## Phase 1: Tenant and Identity Slice

Goal: an organizer can sign in, select a tenant, and access tenant-scoped API data.

Vertical slice:

- Implement tenants, domains, users, memberships, roles, and basic capabilities.
- Add Passport authentication for staff.
- Add MFA enrollment, enforced for platform-scope staff and for roles with payout or refund capabilities.
- Resolve tenant context for admin requests through `X-Tenant-Id`.
- Add PostgreSQL RLS policies and request middleware that sets `app.tenant_id`.
- Build minimal admin shell: login, tenant switcher, current user, current tenant.
- Add activity logging for staff login and tenant-scoped mutations.
- Implement outbox event recording in `app/Support/Outbox` so every phase writes domain events in the producing transaction; queue delivery arrives in Phase 4.

Exit criteria:

- A staff user can sign into admin and view only their tenant.
- RLS isolation tests prove cross-tenant reads and writes fail.
- Architecture tests prevent cross-context model imports.

## Phase 2: Publishable General-Admission Event

Goal: an organizer can create and publish a simple event that appears on the storefront.

Vertical slice:

- Implement event catalog for venues, events, and general-admission ticket types.
- Add tenant branding basics: name, logo, primary color, default locale.
- Build admin event creation and publish flow, recording catalog domain events to the outbox.
- Build storefront event page resolved from host/domain and event slug.
- Use PostgreSQL search or simple indexed lookup for published event discovery.
- Store event images through spatie/laravel-medialibrary.

Exit criteria:

- An organizer can create a venue, event, and ticket type, then publish it.
- A buyer can open the tenant storefront and view the event with accurate price and availability.
- Draft events are not visible publicly.

## Phase 3: Inventory Hold and Checkout Slice

Goal: a buyer can reserve inventory and create an unpaid order without overselling.

Vertical slice:

- Implement `ticket_type_inventory`, holds, hold items, and expiry sweeper, recording hold lifecycle events to the outbox.
- Add atomic conditional updates for hold creation, extension, release, and commit.
- Implement guest customer creation.
- Build storefront checkout start flow with hold countdown.
- Implement order creation from a valid hold.
- Add concurrency tests against real PostgreSQL.

Exit criteria:

- Parallel checkout simulations cannot oversell a ticket type.
- Expired holds cannot become orders.
- Availability display recovers correctly when holds expire.

## Phase 4: First Payment and Ticket Issuance

Goal: a buyer can pay for an order and receive a valid ticket.

Vertical slice:

- Select the launch market and payment gateway and record the selection as an ADR before adapter work begins; this decision also fixes whether refunds are in MVP scope.
- Implement one gateway adapter end to end, including idempotency keys and webhook signature verification.
- Implement tenant sub-merchant onboarding through the selected gateway's KYC flow and per-tenant gateway configuration.
- Add raw webhook event persistence with unique gateway event IDs.
- Implement payment initiation, confirmation, failure, and payment expiry.
- Add the scheduled reconciliation poller for `awaiting_payment` orders as a backstop for missed webhooks.
- Add outbox delivery: Redis queue dispatch through Horizon, per-subscriber delivery tracking, and the reconciliation sweeper, driving ticket issuance side effects.
- Project double-entry ledger entries for paid orders from outbox events.
- Generate signed QR ticket payloads and ticket PDFs or simple email-ready ticket pages.
- Send order confirmation email through Resend.
- Build buyer-facing checkout completion and failure screens.

Exit criteria:

- A buyer can complete a real sandbox payment and receive a ticket.
- Duplicate webhook delivery does not duplicate tickets, charges, or emails.
- Failed or expired payments release inventory correctly.
- Every paid order produces balanced ledger entries.

## Phase 5: Basic Check-in

Goal: door staff can validate purchased tickets.

Vertical slice:

- Implement check-in manifest endpoint for one event.
- Build check-in PWA login, event selection, QR scanner, and scan result states.
- Validate QR signatures and ticket status.
- Record successful scans and duplicate scan attempts.
- Add a small IndexedDB queue for temporary offline scan storage.

Exit criteria:

- A check-in staff user can scan a paid ticket once successfully.
- A second scan is flagged as duplicate.
- A short offline period does not lose scans once the device reconnects.

## Phase 6: Organizer Operations

Goal: an organizer can run the event without direct database or support intervention.

Vertical slice:

- Add order list, order detail, ticket resend, and customer lookup in admin.
- Add refund flow if required for launch, extending the ledger projection to refunds.
- Verify the selected gateway's minimum viable payout flow end to end and mirror gateway payouts into `payouts` records.
- Add customer data-subject erasure (anonymize PII in place) and export endpoints for GDPR and LGPD requests.
- Add simple sales and attendance dashboards.
- Add audit log views for financial and access-control actions.
- Add support-safe operational commands for replaying failed outbox jobs and reconciling payments.

Exit criteria:

- Staff can answer common buyer support questions from admin.
- Refunds and ticket resends are audited.
- Finance can see gross sales, refunds, net amount, and ticket counts for an event.

## Phase 7: Production Hardening

Goal: launch the MVP with known operational controls.

Vertical slice:

- Build production container images for all apps.
- Configure the Caddy edge proxy with on-demand TLS for tenant custom domains, gated by a tenant-domain verification endpoint.
- Add database migrations, backups, restore rehearsal, and seed strategy.
- Add Sentry, OpenTelemetry traces, metrics, dashboards, and alerting.
- Add rate limits for checkout and authentication endpoints.
- Add security checks for RLS, authorization, webhook verification, and secret handling.
- Add end-to-end smoke tests for publish, purchase, email, check-in, and refund.
- Run focused load tests on hold creation and payment initiation.

Exit criteria:

- A fresh environment can be deployed from CI artifacts.
- Operators can detect and investigate failed payments, stuck holds, failed jobs, and checkout errors.
- The launch checklist has no unknown critical path items.

## Post-MVP Expansion

After the MVP is live and real usage validates the core model, expand in order of product pressure.

1. Promo codes and purchase limits.
2. Stronger offline-first check-in with cross-device reconciliation.
3. Multi-language storefront content and localized transactional emails.
4. Additional payment methods and gateways.
5. More complete ledger, payout reconciliation, and finance exports.
6. Reserved seating and seat-map management.
7. High-demand waiting room, stronger bot mitigation, and queue analytics.
8. Reporting projections, exports, and dashboard depth.
9. Customer accounts beyond guest checkout.
10. Native mobile check-in app.

## Sequencing Principles

- Keep every phase vertically demoable through API and UI, not just backend-complete.
- Prove tenant isolation, inventory correctness, and payment idempotency before adding breadth.
- Prefer one complete gateway over several partial adapters.
- Prefer general admission until the checkout, payment, and check-in loop is reliable.
- Treat asynchronous jobs as at-least-once delivery; every external side effect needs its own idempotency guard.
- Do not build the waiting room until load tests or launch plans show it is needed.
- Record new architectural choices as ADRs before implementation diverges from the system design.
