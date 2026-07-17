<?php

namespace Tests\Support\Payments;

use App\Payments\Gateways\GatewayAdapter;
use Closure;

/**
 * The per-adapter wiring the webhook negative matrix (stage-12 plan,
 * Slice 6) needs to forge the four rejections every signing scheme must
 * make: a delivery with no signature at all, a body changed after
 * signing, a signature produced by a key that is not the gateway's, and
 * a signature whose timestamp sits outside the freshness window. Each
 * closure returns the raw HTTP body and headers to post, so the matrix
 * drives the real ingestion route rather than the adapter in isolation
 * and can assert nothing reached the database.
 *
 * Every adapter bound in the GatewayRegistry supplies one of these; the
 * matrix's completeness case fails the build when a newly registered
 * gateway does not.
 */
final readonly class WebhookNegativeProbe
{
    /**
     * @param  Closure(GatewayAdapter $adapter): array{body: string, headers: array<string, string>}  $validDelivery  the positive control: what the gateway itself would send
     * @param  Closure(GatewayAdapter $adapter): array{body: string, headers: array<string, string>}  $missingSignature
     * @param  Closure(GatewayAdapter $adapter): array{body: string, headers: array<string, string>}  $tamperedBody  correctly signed headers, body mutated after signing
     * @param  Closure(GatewayAdapter $adapter): array{body: string, headers: array<string, string>}  $wrongKey  the scheme applied faithfully, with a key the gateway never issued
     * @param  Closure(GatewayAdapter $adapter): array{body: string, headers: array<string, string>}  $staleTimestamp  correctly signed with the real key, timestamped outside the tolerance window
     * @param  bool  $acceptsValidDelivery  false only for a skeleton adapter whose contract is to reject every webhook (PendingGatewayAdapter)
     */
    public function __construct(
        public Closure $validDelivery,
        public Closure $missingSignature,
        public Closure $tamperedBody,
        public Closure $wrongKey,
        public Closure $staleTimestamp,
        public bool $acceptsValidDelivery = true,
    ) {}

    /**
     * @return array<string, Closure(GatewayAdapter $adapter): array{body: string, headers: array<string, string>}>
     */
    public function negatives(): array
    {
        return [
            'missing signature' => $this->missingSignature,
            'tampered body' => $this->tamperedBody,
            'wrong key' => $this->wrongKey,
            'stale timestamp' => $this->staleTimestamp,
        ];
    }
}
