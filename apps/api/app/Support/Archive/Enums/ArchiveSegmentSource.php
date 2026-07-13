<?php

namespace App\Support\Archive\Enums;

/**
 * archive_segments.source: which table a manifest row's segment archived
 * rows from (stage-12 plan, Data model "archive_segments"). OutboxEvents
 * is written by App\Support\Archive\Actions\ArchiveOutboxEvents (task
 * breakdown item 10); the case shipped ahead of that action (task
 * breakdown item 9) so both archivers share one registry and one
 * manifest table from the start.
 */
enum ArchiveSegmentSource: string
{
    case OutboxEvents = 'outbox_events';
    case ActivityLog = 'activity_log';
}
