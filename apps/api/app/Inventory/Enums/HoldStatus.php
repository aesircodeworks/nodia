<?php

namespace App\Inventory\Enums;

/**
 * holds.status (stage-06 plan, Data model "holds"). active is the
 * creation state every hold starts in; released, expired, and committed
 * are the three terminal states, each reached only through a conditional
 * UPDATE guarded by `status = 'active'` (App\Inventory\Actions). Status
 * columns are strings backed by a PHP enum, the enum the authoritative
 * list of states (data-conventions).
 */
enum HoldStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Expired = 'expired';
    case Committed = 'committed';
}
