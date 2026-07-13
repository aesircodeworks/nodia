<?php

namespace App\Support\Archive\Enums;

/**
 * archive_segments.source: which table a manifest row's segment archived
 * rows from (stage-12 plan, Data model "archive_segments"). OutboxEvents
 * is not written by anything yet (task breakdown item 10, the outbox
 * archiver, out of this task's own scope); the case exists now so both
 * archivers share one registry and one manifest table from the start,
 * rather than task 10 widening this enum later.
 */
enum ArchiveSegmentSource: string
{
    case OutboxEvents = 'outbox_events';
    case ActivityLog = 'activity_log';
}
