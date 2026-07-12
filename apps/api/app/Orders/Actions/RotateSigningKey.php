<?php

namespace App\Orders\Actions;

use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rotates an event's signing key (stage-09 plan, Data model
 * "event_signing_keys": "Rotation"; Endpoints, POST signing-keys).
 * Retires the current active key with a conditional UPDATE checked by
 * affected-row count, then inserts the next version as active, in the
 * same transaction. `revokePrevious` marks the outgoing key revoked
 * instead of merely retired, the leak-response path: revoked keys stop
 * verifying, while retired keys keep verifying so devices holding
 * synced keys can validate QR signatures rotated while offline.
 *
 * The current active key is re-read at the top of each attempt rather
 * than trusted from a prior read, and re-tried on a lost race: two
 * concurrent rotations serialize on the retiring UPDATE's row lock (the
 * loser's affected-row count comes back zero once the winner commits),
 * and the loser retries against the winner's now-current active key
 * instead of failing, so parallel rotation requests both succeed with
 * strictly monotonic versions rather than one clobbering the other.
 */
final class RotateSigningKey
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly GetOrCreateActiveSigningKey $getOrCreateActive,
    ) {}

    public function __invoke(string $eventId, bool $revokePrevious = false): EventSigningKey
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $current = ($this->getOrCreateActive)($eventId);

            $next = DB::transaction(function () use ($current, $eventId, $revokePrevious): ?EventSigningKey {
                $retiredStatus = $revokePrevious ? SigningKeyStatus::Revoked : SigningKeyStatus::Retired;
                $timestampColumn = $revokePrevious ? 'revoked_at' : 'retired_at';

                $affected = EventSigningKey::query()
                    ->whereKey($current->id)
                    ->where('status', SigningKeyStatus::Active)
                    ->update([
                        'status' => $retiredStatus,
                        $timestampColumn => now(),
                    ]);

                if ($affected !== 1) {
                    return null;
                }

                return EventSigningKey::query()->create([
                    'tenant_id' => $this->tenantContext->tenantId(),
                    'event_id' => $eventId,
                    'key_version' => $current->key_version + 1,
                    'secret' => bin2hex(random_bytes(32)),
                    'status' => SigningKeyStatus::Active,
                    'activated_at' => now(),
                ]);
            });

            if ($next !== null) {
                return $next;
            }
        }

        throw new RuntimeException(
            'event_signing_keys: rotation could not win the active key after '.self::MAX_ATTEMPTS.' attempts.',
        );
    }
}
