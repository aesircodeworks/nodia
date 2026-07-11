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
 * Stage 3 RBAC capability set").
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
        'checkin.scan',
        'seat_maps.manage',
        'events.manage_seating',
        'orders.resend_tickets',
        'promo_codes.manage',
        'customers.view',
    ]);
});

it('marks exactly orders.refund and payouts.view as financially privileged', function () {
    $privileged = array_map(
        fn (Capability $capability): string => $capability->value,
        array_values(array_filter(Capability::cases(), fn (Capability $capability): bool => $capability->isFinanciallyPrivileged())),
    );

    expect($privileged)->toBe(['orders.refund', 'payouts.view']);
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
]);
