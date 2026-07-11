<?php

namespace App\Inventory\Enums;

/**
 * event_seats.status (stage-06 plan, Data model "event_seats"; system-
 * design 6.2). available is the state materialization seeds every seat
 * in; held and sold are reached only through the hold-claim and commit
 * conditional UPDATEs; blocked is admin-only, toggled independently of
 * holds. Status columns are strings backed by a PHP enum, the enum the
 * authoritative list of states (data-conventions).
 */
enum EventSeatStatus: string
{
    case Available = 'available';
    case Held = 'held';
    case Sold = 'sold';
    case Blocked = 'blocked';
}
