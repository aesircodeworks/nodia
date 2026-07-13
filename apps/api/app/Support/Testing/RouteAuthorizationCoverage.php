<?php

namespace App\Support\Testing;

/**
 * Every mutating (POST/PUT/PATCH/DELETE) /v1 route, mapped to either the
 * Capability that gates it or an explicit exemption with a reason
 * (stage-12 plan, Slice 6, task breakdown item 14; system-design 5.3).
 * Read-only routes carry no authorization-completeness requirement here:
 * capability leakage on a read is a different (and separately tested)
 * concern from write authorization.
 *
 * Most entries name a single App\Identity\Capability value, matching the
 * App\Http\Middleware\RequireCapability::class.':'.value parameter every
 * route file attaches statically. A few controllers resolve the
 * applicable capability dynamically at runtime instead of through static
 * middleware (the target capability depends on request state the route
 * itself does not carry, e.g. which data subject request type, or which
 * event a check-in scan's QR payload names); those entries list every
 * capability the dynamic check can choose between, pipe-separated, and
 * are called out in their own comment so the distinction stays visible.
 *
 * tests/Feature/Support/RouteCoverageCompletenessTest.php enumerates every
 * registered mutating /v1 route and requires each to appear in exactly one
 * of covered() or exempt(); a route landing in neither fails the build, so
 * a new mutating route wired without a capability decision does not merge
 * silently. tests/Feature/Identity/AuthorizationMatrixTest.php cross-checks
 * every capability named here against the live Capability registry and
 * relies on its own generic per-capability matrix (built from
 * Capability::cases() directly) to prove deny-for-lacking-role: that proof
 * already covers every capability CapabilityGate can evaluate, including
 * every one named in covered() below, since RequireCapability and every
 * dynamic in-controller check both delegate to that same gate.
 */
final class RouteAuthorizationCoverage
{
    /**
     * @return array<string, string>
     */
    public static function covered(): array
    {
        return [
            // Statically gated via RequireCapability middleware.
            'DELETE /v1/check-in-assignments/{assignment}' => 'checkin.manage',
            'DELETE /v1/memberships/{membership}' => 'memberships.manage',
            'DELETE /v1/roles/{role}' => 'roles.manage',
            'DELETE /v1/seat-maps/{seat_map}' => 'seat_maps.manage',
            'DELETE /v1/tenant-domains/{tenant_domain}' => 'tenants.manage',
            'PATCH /v1/events/{event}' => 'events.manage',
            'PATCH /v1/events/{event}/seats' => 'events.manage_seating',
            'PATCH /v1/memberships/{membership}' => 'memberships.manage',
            'PATCH /v1/promo-codes/{promo_code}' => 'promo_codes.manage',
            'PATCH /v1/roles/{role}' => 'roles.manage',
            'PATCH /v1/tenant-domains/{tenant_domain}' => 'tenants.manage',
            'PATCH /v1/tenants/{tenant}' => 'tenants.manage',
            'PATCH /v1/ticket-types/{ticket_type}' => 'events.manage',
            'PATCH /v1/venues/{venue}' => 'events.manage',
            'PUT /v1/seat-maps/{seat_map}' => 'seat_maps.manage',
            'POST /v1/events' => 'events.manage',
            'POST /v1/events/{event}/cancel' => 'events.publish',
            'POST /v1/events/{event}/check-in-assignments' => 'checkin.manage',
            'POST /v1/events/{event}/media' => 'events.manage',
            'POST /v1/events/{event}/publish' => 'events.publish',
            'POST /v1/events/{event}/signing-keys' => 'checkin.manage',
            'POST /v1/events/{event}/ticket-types' => 'events.manage',
            'POST /v1/exports' => 'reports.export',
            'POST /v1/memberships' => 'memberships.manage',
            'POST /v1/orders/{order}/resend-tickets' => 'orders.resend_tickets',
            'POST /v1/payments/{payment}/refunds' => 'orders.refund',
            'POST /v1/promo-codes' => 'promo_codes.manage',
            'POST /v1/roles' => 'roles.manage',
            'POST /v1/submerchant-accounts' => 'payouts.manage',
            'POST /v1/submerchant-accounts/{submerchant_account}/refresh' => 'payouts.manage',
            'POST /v1/tenants' => 'tenants.manage',
            'POST /v1/tenants/{tenant}/domains' => 'tenants.manage',
            'POST /v1/tenants/{tenant}/media' => 'tenants.manage',
            'POST /v1/venues' => 'events.manage',
            'POST /v1/venues/{venue}/seat-maps' => 'seat_maps.manage',

            // Dynamically resolved in-controller (CapabilityGate called
            // directly, not through RequireCapability middleware), each
            // pipe-separated between every capability the check can pick.
            'DELETE /v1/media/{media}' => 'events.manage|tenants.manage', // App\Http\Controllers\MediaController::destroy: the capability follows the media's owning model (HasMediaCapability), Event today and Tenant once tenant branding media ships.
            'POST /v1/check-ins' => 'checkin.scan|checkin.manage', // App\CheckIn\Actions\RecordScan: checkin.scan plus an event assignment, or checkin.manage as a bypass, evaluated once the scan's QR payload names the target event.
            'POST /v1/check-in-batches' => 'checkin.scan|checkin.manage', // App\CheckIn\Actions\ReconcileOfflineScans: same per-scan resolution as POST /v1/check-ins.
            'POST /v1/customers/{customer}/data-subject-requests' => 'customers.erase|customers.export', // App\Identity\Http\Controllers\DataSubjectRequestController::store: the capability follows the request body's own type.
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function exempt(): array
    {
        return [
            'DELETE /v1/storefront/holds/{hold}' => "customer/anonymous self-service: releases the caller's own hold, not staff-capability-gated",
            'POST /v1/auth/customer/claim' => 'self-service customer claim request; unauthenticated by design, gated by email delivery, not a staff capability',
            'POST /v1/auth/customer/claim/confirm' => "self-service: confirms the caller's own claim token",
            'POST /v1/auth/customer/logout' => 'self-service customer logout',
            'POST /v1/auth/customer/refresh' => 'self-service customer token refresh',
            'POST /v1/auth/customer/token' => 'self-service customer token issuance; no identity yet to gate',
            'POST /v1/auth/mfa/disable' => "self-service on the caller's own account",
            'POST /v1/auth/mfa/enrollment' => "self-service on the caller's own account",
            'POST /v1/auth/mfa/enrollment/confirm' => "self-service on the caller's own account",
            'POST /v1/auth/staff/invitation/accept' => "self-service: accepts the caller's own invitation by token",
            'POST /v1/auth/staff/logout' => 'self-service staff logout',
            'POST /v1/auth/staff/password/reset' => 'self-service password reset request',
            'POST /v1/auth/staff/password/reset/confirm' => 'self-service password reset confirmation',
            'POST /v1/auth/staff/refresh' => 'self-service staff token refresh',
            'POST /v1/auth/staff/token' => 'self-service staff token issuance; no identity yet to gate',
            'POST /v1/customers' => 'public self-service registration; unauthenticated by design, no staff capability applies',
            'POST /v1/storefront/events/{event}/queue-entries' => 'customer/anonymous self-service waiting-room admission',
            'POST /v1/storefront/holds' => 'customer/anonymous self-service hold creation',
            'POST /v1/storefront/orders' => "customer self-service order creation from the caller's own hold",
            'POST /v1/storefront/orders/{order}/cancel' => "customer self-service, the caller's own order",
            'POST /v1/storefront/orders/{order}/payments' => "customer self-service, the caller's own order",
            'POST /v1/storefront/promo-codes/check' => 'customer/anonymous self-service promo preview, read-only side effect',
            'POST /v1/webhooks/{gateway}' => 'webhook ingestion; the gateway authenticates by signature, not a staff capability',
        ];
    }
}
