<?php

namespace App\Payments\Gateways;

use App\Payments\Enums\SubmerchantStatus;

/**
 * Data-driven mapping from one gateway's raw sub-merchant status
 * vocabulary to the platform's normalized SubmerchantStatus (stage-08d
 * plan, Slice 4). Each adapter owns its own instance, keyed by whatever
 * strings that gateway's webhooks and status queries actually send;
 * normalization is never a match/switch statement hardcoded per
 * gateway, so a second adapter with an entirely different vocabulary
 * plugs in by constructing a different map, not by changing code here
 * or in the callers of resolve().
 *
 * A raw status absent from the map resolves to SubmerchantStatus::NeedsReview
 * rather than throwing or being dropped: an unrecognized status is
 * exactly as likely to be a new gateway status the map has not been
 * updated for yet as it is to be a malformed payload, and quarantining
 * it keeps the account visible (and correctable) instead of stuck
 * silently on its prior status or crashing the webhook job.
 */
final class SubmerchantStatusMap
{
    /**
     * @param  array<string, SubmerchantStatus>  $map
     */
    public function __construct(private readonly array $map) {}

    public function resolve(string $rawStatus): SubmerchantStatus
    {
        return $this->map[$rawStatus] ?? SubmerchantStatus::NeedsReview;
    }

    /**
     * The identity mapping used when a gateway's own wire vocabulary is
     * already the platform enum's values (FakeGateway's default).
     */
    public static function identity(): self
    {
        return new self(array_combine(
            array_map(fn (SubmerchantStatus $status): string => $status->value, SubmerchantStatus::cases()),
            SubmerchantStatus::cases(),
        ));
    }
}
