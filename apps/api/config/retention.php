<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway Webhook Payload Retention
    |--------------------------------------------------------------------------
    |
    | App\Payments\Actions\PruneWebhookPayloads nulls gateway_webhook_
    | events.payload and stamps payload_pruned_at for rows whose
    | received_at is older than this many days (stage-12 plan, Data
    | model "Additive columns"; system-design 14.3). The row and its
    | unique (gateway, gateway_event_id) survive pruning, so webhook
    | idempotence outlives the window. Proposed default from the
    | stage-12 plan's Risks section: 90 days.
    |
    */

    'webhook_payload_days' => (int) env('RETENTION_WEBHOOK_PAYLOAD_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Activity Log Retention
    |--------------------------------------------------------------------------
    |
    | The activity log archive-then-prune command (stage-12 plan, task
    | breakdown item 9) exports activity_log rows older than this many
    | days to an object storage segment, then deletes them only after
    | the upload's checksum verifies (system-design 14.2, 14.3).
    | Proposed default from the stage-12 plan's Risks section: 400 days.
    |
    */

    'activity_log_days' => (int) env('RETENTION_ACTIVITY_LOG_DAYS', 400),

    /*
    |--------------------------------------------------------------------------
    | Outbox Archival Threshold
    |--------------------------------------------------------------------------
    |
    | The outbox archiver (stage-12 plan, task breakdown item 10) writes
    | outbox_events rows older than this many days to a checksummed
    | segment and deletes the source rows only after the upload
    | verifies (system-design 9.1). Proposed default from the stage-12
    | plan's Risks section: 180 days.
    |
    */

    'outbox_archival_days' => (int) env('RETENTION_OUTBOX_ARCHIVAL_DAYS', 180),

    /*
    |--------------------------------------------------------------------------
    | Export Attachment Retention
    |--------------------------------------------------------------------------
    |
    | App\Identity\Actions\PruneDataSubjectExportAttachments deletes the
    | data_subject_export medialibrary attachment for a completed export
    | request whose completed_at is older than this many days (stage-12
    | plan, Data model "data_subject_requests": "Export files ... are
    | themselves subject to a short retention window because they
    | contain PII"). The request row survives as an audit trail; its
    | download_url reads null once the attachment is gone. Proposed
    | default from the stage-12 plan's Risks section: 7 days.
    |
    */

    'export_attachment_days' => (int) env('RETENTION_EXPORT_ATTACHMENT_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Archive Segment Disk
    |--------------------------------------------------------------------------
    |
    | The disk (config/filesystems.php) the archive-then-prune commands
    | (App\Support\Archive\Actions\ArchiveActivityLog, task breakdown
    | item 9; the outbox archiver, task breakdown item 10) upload
    | NDJSON segments to before deleting the source rows. Defaults to
    | the generic S3-compatible 's3' disk (any S3-compatible store;
    | MinIO for development, system-design 15.3), distinct from the
    | medialibrary-managed 'media' disk, which serves public-visibility
    | tenant assets, not archival manifests verified by checksum.
    |
    */

    'archive_disk' => env('RETENTION_ARCHIVE_DISK', 's3'),

];
