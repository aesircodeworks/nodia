<?php

namespace App\Orders\Data;

use App\Orders\Models\EventSigningKey;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The staff signing-key wire shape (stage-09 plan, Endpoints "GET/POST
 * /v1/events/{event}/signing-keys"). secret crosses the wire only here,
 * TLS everywhere assumed (system-design 14.1). secret is base64-encoded:
 * the stored value is opaque binary (HKDF or random_bytes output, never
 * guaranteed valid UTF-8), so it cannot be embedded as a raw JSON string.
 */
#[MapName(SnakeCaseMapper::class)]
class SigningKeyData extends Data
{
    public function __construct(
        public string $id,
        public int $keyVersion,
        public string $secret,
        public string $status,
        public string $activatedAt,
        public ?string $retiredAt,
    ) {}

    public static function fromModel(EventSigningKey $key): self
    {
        $format = fn (?DateTimeInterface $timestamp): ?string => $timestamp === null
            ? null
            : CarbonImmutable::instance($timestamp)->utc()->format('Y-m-d\TH:i:s\Z');

        return new self(
            $key->id,
            $key->key_version,
            base64_encode($key->secret),
            $key->status->value,
            $format($key->activated_at),
            $format($key->retired_at),
        );
    }
}
