<?php

namespace App\Support\Problems;

/**
 * A HasErrorCode exception that carries extra RFC 9457 extension members
 * beyond the base problem shape, e.g. the existing account id on
 * submerchant_already_onboarded (stage-08c plan, Endpoints: "response
 * includes the existing account id in the problem document").
 */
interface HasProblemExtensions
{
    /**
     * @return array<string, mixed>
     */
    public function problemExtensions(): array;
}
