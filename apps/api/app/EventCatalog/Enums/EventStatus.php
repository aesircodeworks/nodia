<?php

namespace App\EventCatalog\Enums;

/**
 * events.status (stage-05a plan, Data model): draft is the creation
 * state every event starts in (the events table's own DEFAULT), published
 * makes it visible on the storefront (PublishEvent, task breakdown item
 * 9), canceled is the terminal state (CancelEvent, task breakdown item
 * 9). Status columns are strings backed by a PHP enum, the enum the
 * authoritative list of states (data-conventions).
 */
enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Canceled = 'canceled';
}
