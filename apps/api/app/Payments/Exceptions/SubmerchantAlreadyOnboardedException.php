<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use App\Support\Problems\HasProblemExtensions;
use RuntimeException;

/**
 * Raised when the (tenant_id, gateway) unique constraint on
 * submerchant_accounts rejects an onboarding start (stage-08c plan,
 * Endpoints: "response includes the existing account id in the problem
 * document"), whether the loser of a concurrent race or a repeat call
 * against an already-onboarded gateway.
 */
final class SubmerchantAlreadyOnboardedException extends RuntimeException implements HasErrorCode, HasProblemExtensions
{
    public static function forExisting(string $existingId): self
    {
        return new self($existingId, 'A sub-merchant account for this gateway already exists.');
    }

    private function __construct(private readonly string $existingId, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::SubmerchantAlreadyOnboarded;
    }

    /**
     * @return array<string, mixed>
     */
    public function problemExtensions(): array
    {
        return ['existing_id' => $this->existingId];
    }
}
