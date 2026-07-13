<?php

namespace App\Identity\Enums;

/**
 * data_subject_requests.status (stage-12 plan, Data model
 * "data_subject_requests"), mirroring App\Reporting\Enums\ExportStatus's
 * own shape exactly: pending is the only starting state; processing is
 * the sole non-terminal in-flight state (claimed by exactly one worker);
 * completed and failed are terminal. Every transition is a conditional
 * UPDATE on App\Identity\Models\DataSubjectRequest guarded on the current
 * status and checked by affected-row count, never read-then-write
 * (data-conventions, stage-12 plan Data model).
 */
enum DataSubjectRequestStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
