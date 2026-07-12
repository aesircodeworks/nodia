<?php

namespace App\Orders\Enums;

/**
 * Every typed result VerifyCheckInQr can return (stage-09 plan, Task 8:
 * "Orders read Actions for CheckIn"). Values line up with the stable
 * problem-document codes POST /v1/check-ins maps them to (Endpoints,
 * "POST /v1/check-ins"), except Valid (there is no error to code) and
 * TicketStatusUnknown, which is defensive: TicketStatus currently has no
 * fourth case, but the mapping is exhaustive over the raw stored value
 * rather than the enum cast so an unrecognized future status fails
 * closed instead of throwing.
 */
enum QrVerificationOutcome: string
{
    case Valid = 'valid';
    case SignatureInvalid = 'qr_signature_invalid';
    case KeyRevoked = 'qr_key_revoked';
    case RotationStale = 'ticket_rotation_stale';
    case TicketNotFound = 'ticket_not_found';
    case TicketCanceled = 'ticket_canceled';
    case TicketRefunded = 'ticket_refunded';
    case TicketStatusUnknown = 'ticket_status_unknown';
}
