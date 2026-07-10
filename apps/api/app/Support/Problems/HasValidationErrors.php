<?php

namespace App\Support\Problems;

/**
 * Implemented by a HasErrorCode domain exception that, unlike the plain
 * interface, needs the errors map extension member alongside its own
 * stable code (stage-05b plan, Endpoints: "catalog.seat_map_duplicate_seats
 * ... listing the offending positions in the errors map"). Distinct from
 * Illuminate\Validation\ValidationException, which ProblemRenderer always
 * renders as the generic request.validation_failed: this is for a
 * domain-level invariant that still needs a stable code of its own.
 */
interface HasValidationErrors
{
    /**
     * @return array<string, list<string>>
     */
    public function errors(): array;
}
