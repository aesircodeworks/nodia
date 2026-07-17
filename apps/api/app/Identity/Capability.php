<?php

namespace App\Identity;

/**
 * The authoritative capability registry (system-design 5.3, stage-03 plan
 * Data model): a flat set of named permissions stored per role and
 * evaluated through Gates and Policies as capability plus tenant context,
 * never role names. Grows additively; later stages add cases, never
 * repurpose or remove one, since a role's persisted capabilities jsonb
 * column stores these values at rest.
 */
enum Capability: string
{
    case RolesManage = 'roles.manage';
    case MembershipsManage = 'memberships.manage';
    case TenantsManage = 'tenants.manage';
    case EventsView = 'events.view';
    case EventsManage = 'events.manage';
    case EventsPublish = 'events.publish';
    case OrdersView = 'orders.view';
    case OrdersRefund = 'orders.refund';
    case PayoutsView = 'payouts.view';
    case PayoutsManage = 'payouts.manage';
    case LedgerView = 'ledger.view';
    case CheckinScan = 'checkin.scan';
    case SeatMapsManage = 'seat_maps.manage';
    case EventsManageSeating = 'events.manage_seating';
    case OrdersResendTickets = 'orders.resend_tickets';
    case PromoCodesManage = 'promo_codes.manage';
    case CustomersView = 'customers.view';
    case CheckinManage = 'checkin.manage';
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';
    case CustomersErase = 'customers.erase';
    case CustomersExport = 'customers.export';

    /**
     * MFA is mandatory for platform-scope staff and tenant roles that
     * include payout or refund permissions (system-design 5.1, 5.4). The
     * mechanism enforcing this arrives in a later Stage 3 task; the flag
     * lives on the registry now so the two never drift apart.
     *
     * reports.view and reports.export extend this set on the ledger.view
     * precedent, not the orders.view one: `report_event_finance`
     * (stage-11 plan) mirrors the ledger's own per-event gross, gateway
     * fee, platform commission, and tenant net totals (system-design 7.3),
     * the same class of settlement data as ledger.view, not the
     * individual-order money orders.view exposes without being
     * privileged. reports.export is at least as sensitive: its
     * ledger_entries source exports those same ledger rows as a
     * downloadable file, and its orders and tickets sources carry
     * customer PII.
     */
    public function isFinanciallyPrivileged(): bool
    {
        return match ($this) {
            self::OrdersRefund, self::PayoutsView, self::PayoutsManage, self::LedgerView, self::ReportsView, self::ReportsExport => true,
            default => false,
        };
    }
}
