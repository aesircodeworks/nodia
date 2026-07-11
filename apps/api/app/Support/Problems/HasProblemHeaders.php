<?php

namespace App\Support\Problems;

/**
 * A HasErrorCode exception that carries extra response headers, e.g.
 * Retry-After on gateway_unavailable (api-conventions Errors: retry
 * guidance, when applicable, is expressed via Retry-After).
 */
interface HasProblemHeaders
{
    /**
     * @return array<string, string>
     */
    public function problemHeaders(): array;
}
