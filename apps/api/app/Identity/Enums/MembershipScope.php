<?php

namespace App\Identity\Enums;

/**
 * memberships.scope (stage-03 plan, Data model): tenant is an ordinary
 * membership scoped to one tenant, platform is a platform administrator
 * whose membership row is pinned to the sentinel platform tenant rather
 * than NULL (data-conventions Tenancy). Status columns are strings backed
 * by a PHP enum, the enum the authoritative list of states
 * (data-conventions).
 */
enum MembershipScope: string
{
    case Tenant = 'tenant';
    case Platform = 'platform';
}
