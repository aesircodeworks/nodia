# UI Conventions

Rules the storefront, admin portal, and check-in PWA share. Per-screen UX belongs in each feature's spec; this document defines what every screen must agree on. Frontend architecture rationale lives in ADRs [015](decisions/015-separate-checkin-pwa.md) and [016](decisions/016-nextjs-monorepo-frontends.md).

## Theming and White-Labeling

- All styling flows through the design tokens and components in `packages/ui`. Apps never hardcode colors, fonts, spacing, or radii; per-tenant branding works by swapping token values resolved from tenant configuration (system-design.md section 16.3).
- Components in `packages/ui` must render correctly under any tenant token set; a component that only looks right with default tokens is a bug.
- Platform branding (the admin portal chrome) is itself a token set, not an exception.

## Internationalization

- Every user-facing string goes through the i18n layer; no literals in components. Keys are dot-separated, lowercase, named by feature and purpose: `checkout.hold_expired.title`, not by English content.
- Locale negotiation order on the storefront: URL, authenticated customer preference, `Accept-Language`, tenant default locale (system-design.md section 12).
- Translated event content arrives from the API already resolved per the negotiated locale; the frontend does not merge locale JSON.

## Formatting and Display

- Event dates and times display in the event's timezone with the timezone made visible; the buyer's local time may be shown additionally, never as a silent replacement.
- Money formats with the locale's number conventions and an explicit currency indicator, always derived from the API's `{amount, currency}` pairs. Amounts are never computed client-side from other amounts; totals come from the API.
- IDs are never shown to end users as identifiers of record; human-facing references (order confirmation codes) come from the API.

## Screen States and Accessibility

- Every screen implements loading, error, and empty states; error states surface the API error's message and a retry path where the operation is retryable.
- Accessibility baseline is WCAG 2.1 AA: semantic markup, full keyboard operability, visible focus, labels on all inputs, and contrast maintained under every tenant token set (contrast is checked in `packages/ui`, not per app).
- Countdown-driven flows (hold expiry, waiting room position) must announce updates to assistive technology, not only visually.

## Check-in PWA

- The check-in app consumes the manifest and sync endpoints exactly as documented in the API contract; no PWA-specific parameters or server behavior, since a future native app shares the same contract (ADR [015](decisions/015-separate-checkin-pwa.md)).
- All door-critical function (scan, validate, duplicate detection) works offline from the synced manifest; connectivity only improves freshness, never gates scanning (system-design.md section 11).
