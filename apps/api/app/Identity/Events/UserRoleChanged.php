<?php

namespace App\Identity\Events;

use App\Identity\Models\Membership;
use App\Support\Outbox\DomainEvent;

/**
 * Envelope per event-conventions and stage-04 Domain events: aggregate is
 * the membership, envelope tenant_id is the membership's tenant (sentinel
 * platform tenant for platform-scope memberships).
 */
final readonly class UserRoleChanged implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public UserRoleChangedPayload $payload,
    ) {
        $this->aggregateType = 'membership';
    }

    public function type(): string
    {
        return 'UserRoleChanged';
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function aggregateType(): string
    {
        return $this->aggregateType;
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function payload(): UserRoleChangedPayload
    {
        return $this->payload;
    }

    public static function fromMembership(
        Membership $membership,
        string $previousRoleId,
        string $newRoleId,
        string $changedByUserId,
    ): self {
        return new self(
            $membership->tenant_id,
            $membership->id,
            new UserRoleChangedPayload(
                $membership->id,
                $membership->user_id,
                $previousRoleId,
                $newRoleId,
                $changedByUserId,
            ),
        );
    }
}
