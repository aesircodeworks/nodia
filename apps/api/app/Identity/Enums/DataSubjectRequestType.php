<?php

namespace App\Identity\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * data_subject_requests.type (stage-12 plan, Data model
 * "data_subject_requests"): erasure anonymizes the customer in place
 * synchronously; export assembles a downloadable copy of the customer's
 * records asynchronously. The partial unique index on (customer_id, type)
 * scopes the run-once invariant per type, so a customer may hold one open
 * request of each kind at once. Marked #[TypeScript] as of task 3 (stage-12
 * plan): App\Identity\Data\CreateDataSubjectRequestData and
 * DataSubjectRequestData now expose it on the wire.
 */
#[TypeScript]
enum DataSubjectRequestType: string
{
    case Erasure = 'erasure';
    case Export = 'export';
}
