<?php

namespace App\Orders\Enums;

/**
 * Active signs new renders and verifies; retired verifies only and is
 * still distributed in the manifest; revoked neither verifies nor
 * appears in the manifest. Exactly one Active row exists per event,
 * backstopped by the event_signing_keys_active_event_idx partial unique
 * index (stage-09 plan, Data model "event_signing_keys").
 */
enum SigningKeyStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
    case Revoked = 'revoked';
}
