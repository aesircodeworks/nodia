<?php

namespace App\Identity\Actions;

use App\Identity\Capability;
use App\Identity\Models\Role;

/**
 * The five global template roles system-design 5.3 names, maintained by
 * the platform and readable in every tenant context under the roles
 * table's template-aware RLS policy (stage-03 plan, Data model and Task
 * breakdown item 4). Callers run this under the nodia_platform database
 * role: roles_platform_write is the only write path a NULL tenant_id row
 * can ever satisfy. Keyed on (tenant_id, name) via updateOrCreate, so
 * repeated invocation is idempotent by construction, proven directly by a
 * unit test rather than by any state this class tracks itself.
 */
final class SeedTemplateRoles
{
    /**
     * @return list<Role>
     */
    public function handle(): array
    {
        $roles = [];

        foreach (self::templates() as $name => $capabilities) {
            $roles[] = Role::query()->updateOrCreate(
                ['tenant_id' => null, 'name' => $name],
                ['capabilities' => array_map(fn (Capability $capability): string => $capability->value, $capabilities)],
            );
        }

        return $roles;
    }

    /**
     * @return array<string, list<Capability>>
     */
    public static function templates(): array
    {
        return [
            'Owner' => [
                Capability::RolesManage,
                Capability::MembershipsManage,
                Capability::EventsView,
                Capability::EventsManage,
                Capability::EventsPublish,
                Capability::OrdersView,
                Capability::OrdersRefund,
                Capability::PayoutsView,
                Capability::CheckinScan,
                Capability::SeatMapsManage,
                Capability::EventsManageSeating,
            ],
            'Event Manager' => [
                Capability::EventsView,
                Capability::EventsManage,
                Capability::EventsPublish,
                Capability::OrdersView,
                Capability::CheckinScan,
                Capability::SeatMapsManage,
                Capability::EventsManageSeating,
            ],
            'Box Office' => [
                Capability::EventsView,
                Capability::OrdersView,
                Capability::CheckinScan,
            ],
            'Finance' => [
                Capability::EventsView,
                Capability::OrdersView,
                Capability::OrdersRefund,
                Capability::PayoutsView,
            ],
            'Check-in Agent' => [
                Capability::EventsView,
                Capability::CheckinScan,
            ],
        ];
    }
}
