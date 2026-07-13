<?php

namespace App\Support\Testing;

/**
 * Every registered /v1 route, mapped to either the Isolation suite test
 * class that proves cross-tenant access to its underlying table is denied
 * under RLS, or an explicit exemption with a reason (stage-12 plan, Slice
 * 6, task breakdown item 14; system-design 14.1, 14.2). A route reads or
 * writes a table with its own RLS policy, so Postgres enforces isolation
 * on every query the route issues regardless of how it reaches the table;
 * this registry is the traceability record tying each route to the test
 * that proves the mechanism for its table, not a re-proof of RLS itself
 * (tests/Isolation/RlsBootstrapTest and friends already own that).
 *
 * tests/Feature/Support/RouteCoverageCompletenessTest.php enumerates every
 * registered /v1 route and requires each to appear in exactly one of
 * covered() or exempt(); a route landing in neither fails the build, which
 * is the whole point: a new tenant-scoped table shipped without its
 * isolation test, or a new route wired to an existing table without
 * updating this map, is caught here before merge.
 */
final class RouteIsolationCoverage
{
    /**
     * @return array<string, string>
     */
    public static function covered(): array
    {
        return [
            // Tenancy: platform-scoped tenant, domain, and branding media
            // CRUD (system-design 4.3's cross-tenant platform role), plus
            // the anonymous edge resolver path.
            'POST /v1/tenants' => 'TenantsIsolationTest',
            'GET /v1/tenants' => 'TenantsIsolationTest',
            'GET /v1/tenants/{tenant}' => 'TenantsIsolationTest',
            'PATCH /v1/tenants/{tenant}' => 'TenantsIsolationTest',
            'POST /v1/tenants/{tenant}/media' => 'TenantMediaEndpointsIsolationTest',
            'POST /v1/tenants/{tenant}/domains' => 'TenantDomainsIsolationTest',
            'GET /v1/tenants/{tenant}/domains' => 'TenantDomainsIsolationTest',
            'PATCH /v1/tenant-domains/{tenant_domain}' => 'PlatformDomainEndpointsIsolationTest',
            'DELETE /v1/tenant-domains/{tenant_domain}' => 'PlatformDomainEndpointsIsolationTest',
            'GET /v1/internal/domain-verification' => 'DomainResolverIsolationTest',

            // Identity: roles, memberships, customers, data subject requests.
            'GET /v1/roles' => 'RolesIsolationTest',
            'POST /v1/roles' => 'RolesIsolationTest',
            'GET /v1/roles/{role}' => 'RoleEndpointsIsolationTest',
            'PATCH /v1/roles/{role}' => 'RoleEndpointsIsolationTest',
            'DELETE /v1/roles/{role}' => 'RoleEndpointsIsolationTest',
            'GET /v1/memberships' => 'MembershipsIsolationTest',
            'POST /v1/memberships' => 'MembershipsIsolationTest',
            'PATCH /v1/memberships/{membership}' => 'MembershipsIsolationTest',
            'DELETE /v1/memberships/{membership}' => 'MembershipsIsolationTest',
            'GET /v1/customers' => 'CustomersIsolationTest',
            'POST /v1/customers' => 'CustomersIsolationTest',
            'POST /v1/auth/customer/claim' => 'CustomersIsolationTest',
            'POST /v1/auth/customer/claim/confirm' => 'CustomersIsolationTest',
            'POST /v1/customers/{customer}/data-subject-requests' => 'DataSubjectRequestEndpointIsolationTest',
            'GET /v1/data-subject-requests' => 'DataSubjectRequestEndpointIsolationTest',
            'GET /v1/data-subject-requests/{data_subject_request}' => 'DataSubjectRequestEndpointIsolationTest',

            // EventCatalog: venues, seat maps, events, ticket types, media,
            // signing keys.
            'GET /v1/events' => 'EventsIsolationTest',
            'POST /v1/events' => 'EventsIsolationTest',
            'GET /v1/events/{event}' => 'EventsIsolationTest',
            'PATCH /v1/events/{event}' => 'EventsIsolationTest',
            'POST /v1/events/{event}/publish' => 'EventsIsolationTest',
            'POST /v1/events/{event}/cancel' => 'EventsIsolationTest',
            'GET /v1/venues' => 'VenuesIsolationTest',
            'POST /v1/venues' => 'VenuesIsolationTest',
            'GET /v1/venues/{venue}' => 'VenuesIsolationTest',
            'PATCH /v1/venues/{venue}' => 'VenuesIsolationTest',
            'GET /v1/venues/{venue}/seat-maps' => 'SeatMapsIsolationTest',
            'POST /v1/venues/{venue}/seat-maps' => 'SeatMapsIsolationTest',
            'GET /v1/seat-maps/{seat_map}' => 'SeatMapEndpointsIsolationTest',
            'PUT /v1/seat-maps/{seat_map}' => 'SeatMapEndpointsIsolationTest',
            'DELETE /v1/seat-maps/{seat_map}' => 'SeatMapEndpointsIsolationTest',
            'GET /v1/events/{event}/ticket-types' => 'TicketTypesIsolationTest',
            'POST /v1/events/{event}/ticket-types' => 'TicketTypesIsolationTest',
            'GET /v1/ticket-types/{ticket_type}' => 'TicketTypesIsolationTest',
            'PATCH /v1/ticket-types/{ticket_type}' => 'TicketTypesIsolationTest',
            'GET /v1/ticket-types/{ticket_type}/inventory' => 'TicketTypeInventoryIsolationTest',
            'GET /v1/events/{event}/seats' => 'EventSeatsIsolationTest',
            'PATCH /v1/events/{event}/seats' => 'EventSeatsIsolationTest',
            'GET /v1/events/{event}/media' => 'EventMediaEndpointsIsolationTest',
            'POST /v1/events/{event}/media' => 'EventMediaEndpointsIsolationTest',
            'DELETE /v1/media/{media}' => 'MediaIsolationTest',
            'GET /v1/events/{event}/signing-keys' => 'EventSigningKeysIsolationTest',
            'POST /v1/events/{event}/signing-keys' => 'EventSigningKeysIsolationTest',

            // CheckIn: manifest, assignments, scans.
            'GET /v1/events/{event}/check-in-manifest' => 'CheckInManifestEndpointIsolationTest',
            'GET /v1/events/{event}/check-in-assignments' => 'CheckInAssignmentsIsolationTest',
            'POST /v1/events/{event}/check-in-assignments' => 'CheckInAssignmentsIsolationTest',
            'DELETE /v1/check-in-assignments/{assignment}' => 'CheckInAssignmentsIsolationTest',
            'POST /v1/check-ins' => 'RecordScanIsolationTest',
            'POST /v1/check-in-batches' => 'CheckInsIsolationTest',

            // Storefront: browsing, holds, orders, payments, promo check.
            'GET /v1/storefront/events' => 'StorefrontEventsIsolationTest',
            'GET /v1/storefront/events/{event}' => 'StorefrontEventsIsolationTest',
            'GET /v1/storefront/events/{event}/availability' => 'StorefrontEventsIsolationTest',
            'GET /v1/storefront/events/{event}/seats' => 'EventSeatsIsolationTest',
            'POST /v1/storefront/holds' => 'HoldsIsolationTest',
            'GET /v1/storefront/holds/{hold}' => 'HoldsIsolationTest',
            'DELETE /v1/storefront/holds/{hold}' => 'HoldsIsolationTest',
            'POST /v1/storefront/orders' => 'OrdersIsolationTest',
            'GET /v1/storefront/orders/{order}' => 'OrdersIsolationTest',
            'GET /v1/storefront/orders/{order}/tickets' => 'TicketsIsolationTest',
            'POST /v1/storefront/orders/{order}/cancel' => 'OrdersIsolationTest',
            'GET /v1/storefront/orders/{order}/payment-methods' => 'PaymentsIsolationTest',
            'POST /v1/storefront/orders/{order}/payments' => 'PaymentsIsolationTest',
            'GET /v1/storefront/payments/{payment}' => 'PaymentsIsolationTest',
            'POST /v1/storefront/promo-codes/check' => 'PromoCodesIsolationTest',

            // Staff-facing orders, refunds, payouts, ledger, reports, exports.
            'GET /v1/orders' => 'OrdersIsolationTest',
            'GET /v1/orders/{order}' => 'OrdersIsolationTest',
            'POST /v1/orders/{order}/resend-tickets' => 'OrdersIsolationTest',
            'GET /v1/promo-codes' => 'PromoCodesIsolationTest',
            'POST /v1/promo-codes' => 'PromoCodesIsolationTest',
            'GET /v1/promo-codes/{promo_code}' => 'PromoCodesIsolationTest',
            'PATCH /v1/promo-codes/{promo_code}' => 'PromoCodesIsolationTest',
            'POST /v1/payments/{payment}/refunds' => 'RefundsIsolationTest',
            'GET /v1/refunds' => 'RefundReadEndpointsIsolationTest',
            'GET /v1/refunds/{refund}' => 'RefundReadEndpointsIsolationTest',
            'GET /v1/submerchant-accounts' => 'SubmerchantAccountsIsolationTest',
            'POST /v1/submerchant-accounts' => 'SubmerchantAccountsIsolationTest',
            'GET /v1/submerchant-accounts/{submerchant_account}' => 'SubmerchantAccountsIsolationTest',
            'POST /v1/submerchant-accounts/{submerchant_account}/refresh' => 'SubmerchantAccountsIsolationTest',
            'GET /v1/ledger-entries' => 'LedgerReadEndpointsIsolationTest',
            'GET /v1/ledger-balances' => 'LedgerReadEndpointsIsolationTest',
            'GET /v1/payouts' => 'PayoutsIsolationTest',
            'GET /v1/payouts/{payout}' => 'PayoutsIsolationTest',
            'GET /v1/reports/daily-sales' => 'DailySalesEndpointIsolationTest',
            'GET /v1/reports/event-finance' => 'EventFinanceEndpointIsolationTest',
            'GET /v1/reports/attendance' => 'EventAttendanceEndpointIsolationTest',
            'GET /v1/exports' => 'ExportEndpointIsolationTest',
            'POST /v1/exports' => 'ExportEndpointIsolationTest',
            'GET /v1/exports/{export}' => 'ExportEndpointIsolationTest',
            'GET /v1/exports/{export}/download' => 'ExportEndpointIsolationTest',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function exempt(): array
    {
        return [
            'GET /v1/health' => 'health check, runs before tenant resolution and returns no tenant-scoped data',
            'POST /v1/webhooks/{gateway}' => 'webhook ingestion: the gateway authenticates by signature, not staff bearer or X-Tenant-Id, before any tenant is resolved (system-design 14.1)',
            'GET /v1/capabilities' => 'static read of the Capability registry, not tenant-scoped data',
            'GET /v1/me' => "returns the caller's own identity and membership summary from users/memberships filtered by the caller's own user_id, not a tenant-scoped resource lookup by an externally supplied id",
            'POST /v1/auth/staff/token' => 'self-service staff authentication; users carries no tenant_id (App\Support\Database\UnscopedTables)',
            'POST /v1/auth/staff/refresh' => 'self-service staff token refresh; oauth_refresh_tokens is an unscoped table (App\Support\Database\UnscopedTables)',
            'POST /v1/auth/staff/logout' => "self-service staff logout; revokes the caller's own oauth tokens (unscoped tables)",
            'POST /v1/auth/staff/invitation/accept' => "self-service staff invitation acceptance; acts on the invited user's own row by invitation token, not a tenant-scoped id lookup",
            'POST /v1/auth/staff/password/reset' => 'self-service password reset request; users carries no tenant_id',
            'POST /v1/auth/staff/password/reset/confirm' => 'self-service password reset confirmation; same reasoning as the request step',
            'POST /v1/auth/mfa/enrollment' => "self-service MFA enrollment on the caller's own users row",
            'POST /v1/auth/mfa/enrollment/confirm' => "self-service MFA confirmation on the caller's own users row",
            'POST /v1/auth/mfa/disable' => "self-service MFA disable on the caller's own users row",
            'POST /v1/auth/customer/token' => 'self-service customer token issuance; oauth_access_tokens/oauth_refresh_tokens are unscoped tables, and cross-tenant bearer misuse is guarded by EnforceCustomerTenantClaim (tests/Unit/EnforceCustomerTenantClaimTest.php), not the Isolation suite',
            'POST /v1/auth/customer/refresh' => 'self-service customer token refresh; same reasoning as token issuance',
            'POST /v1/auth/customer/logout' => "self-service customer logout; revokes the caller's own oauth tokens (unscoped tables)",
            'POST /v1/storefront/events/{event}/queue-entries' => 'waiting-room admission state lives in Redis keyed by tenant_id (App\Inventory\Support\OnSaleQueue), not a Postgres RLS-scoped table; isolation is proven by tests/Unit/Inventory/OnSaleQueueTest.php instead',
            'GET /v1/storefront/queue-entries/{entry}' => 'same reasoning: Redis-backed waiting-room state, not a Postgres RLS-scoped table',
        ];
    }
}
