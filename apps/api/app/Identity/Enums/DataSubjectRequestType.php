<?php

namespace App\Identity\Enums;

/**
 * data_subject_requests.type (stage-12 plan, Data model
 * "data_subject_requests"): erasure anonymizes the customer in place
 * synchronously; export assembles a downloadable copy of the customer's
 * records asynchronously. The partial unique index on (customer_id, type)
 * scopes the run-once invariant per type, so a customer may hold one open
 * request of each kind at once.
 */
enum DataSubjectRequestType: string
{
    case Erasure = 'erasure';
    case Export = 'export';
}
