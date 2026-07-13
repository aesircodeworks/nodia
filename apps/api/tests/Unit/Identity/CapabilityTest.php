<?php

use App\Identity\Capability;

/*
 * Locks the registry down (stage-03 plan, Data model): later stages
 * extend the enum, never repurpose an existing entry, so a change to this
 * test signals exactly that kind of accidental rename or removal.
 * seat_maps.manage is stage-05b's addition (Task breakdown item 1).
 * events.manage_seating is stage-06's addition (Task breakdown item 10a).
 * orders.resend_tickets, promo_codes.manage, and customers.view are
 * stage-07's additions (Endpoints, "New capabilities registered in the
 * Stage 3 RBAC capability set"). payouts.manage is stage-08c's addition
 * (Endpoints, capabilities paragraph). checkin.manage is stage-09's
 * addition (Task breakdown item 1): bypasses the per-event
 * check_in_assignments requirement and manages assignments and signing
 * keys. reports.view and reports.export are stage-11's addition (Task
 * breakdown item 2, system-design 5.3): both marked financially
 * privileged, extending the ledger.view precedent (system-design 7.3
 * describes report_event_finance as mirroring the ledger's own per-event
 * gross/fee/commission/net totals) rather than the orders.view precedent
 * (an unprivileged read of individual order money); reports.export is at
 * least as sensitive since its ledger_entries source exports those same
 * ledger rows as a downloadable file, alongside customer PII in the
 * orders and tickets sources. customers.erase and customers.export are
 * stage-12's addition (Data model "data_subject_requests", Endpoints):
 * gating the erasure and export data subject request flows
 * respectively. Neither is money-shaped, so neither is financially
 * privileged.
 */

it('carries the exact capability registry, no more and no less', function () {
    $values = array_map(fn (Capability $capability): string => $capability->value, Capability::cases());

    expect($values)->toBe([
        'roles.manage',
        'memberships.manage',
        'tenants.manage',
        'events.view',
        'events.manage',
        'events.publish',
        'orders.view',
        'orders.refund',
        'payouts.view',
        'payouts.manage',
        'ledger.view',
        'checkin.scan',
        'seat_maps.manage',
        'events.manage_seating',
        'orders.resend_tickets',
        'promo_codes.manage',
        'customers.view',
        'checkin.manage',
        'reports.view',
        'reports.export',
        'customers.erase',
        'customers.export',
    ]);
});

it('marks exactly orders.refund, payouts.view, payouts.manage, ledger.view, reports.view, and reports.export as financially privileged', function () {
    $privileged = array_map(
        fn (Capability $capability): string => $capability->value,
        array_values(array_filter(Capability::cases(), fn (Capability $capability): bool => $capability->isFinanciallyPrivileged())),
    );

    expect($privileged)->toBe(['orders.refund', 'payouts.view', 'payouts.manage', 'ledger.view', 'reports.view', 'reports.export']);
});

it('marks every other capability as not financially privileged', function (Capability $capability) {
    expect($capability->isFinanciallyPrivileged())->toBeFalse();
})->with([
    Capability::RolesManage,
    Capability::MembershipsManage,
    Capability::TenantsManage,
    Capability::EventsView,
    Capability::EventsManage,
    Capability::EventsPublish,
    Capability::OrdersView,
    Capability::CheckinScan,
    Capability::SeatMapsManage,
    Capability::EventsManageSeating,
    Capability::CheckinManage,
    Capability::CustomersErase,
    Capability::CustomersExport,
]);
