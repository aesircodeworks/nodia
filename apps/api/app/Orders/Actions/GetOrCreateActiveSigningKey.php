<?php

namespace App\Orders\Actions;

use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Orders\Support\DerivedTicketSigningKeyProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Race-safe get-or-create of an event's active signing key (stage-09
 * plan, Data model "event_signing_keys": "Seeding"). The first key ever
 * created for an event is never freshly random: it is seeded with the
 * exact HKDF derivation DerivedTicketSigningKeyProvider used in Stage 7,
 * so QR payloads rendered before this stage's provider swap keep
 * verifying against the stored version 1 key. Every key from version 2
 * onward is created only by RotateSigningKey with random material.
 *
 * Race safety mirrors StartSubmerchantOnboarding: the insert is
 * attempted first, and the partial unique index on (event_id) where
 * status = 'active' is the concurrency guard, not a read-then-write
 * check. A losing concurrent seed catches the constraint violation and
 * returns the winner's row instead.
 */
final class GetOrCreateActiveSigningKey
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DerivedTicketSigningKeyProvider $derivedKeys,
    ) {}

    public function __invoke(string $eventId): EventSigningKey
    {
        $active = $this->findActive($eventId);

        if ($active !== null) {
            return $active;
        }

        try {
            return DB::transaction(fn (): EventSigningKey => EventSigningKey::query()->create([
                'tenant_id' => $this->tenantContext->tenantId(),
                'event_id' => $eventId,
                'key_version' => 1,
                'secret' => $this->derivedKeys->keyForEvent($eventId),
                'status' => SigningKeyStatus::Active,
                'activated_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->findActive($eventId) ?? throw new RuntimeException(
                'event_signing_keys: active key seed lost the race but no active key exists to return.',
            );
        }
    }

    private function findActive(string $eventId): ?EventSigningKey
    {
        return EventSigningKey::query()
            ->where('event_id', $eventId)
            ->where('status', SigningKeyStatus::Active)
            ->first();
    }
}
