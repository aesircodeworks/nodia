<?php

namespace App\Identity\Events;

use App\Identity\Models\Membership;
use App\Support\Outbox\DomainEvent;

/**
 * Envelope per event-conventions and stage-04 Domain events: aggregate is
 * the membership, envelope tenant_id is the membership's tenant (sentinel
 * platform tenant for platform-scope memberships).
 */
final readonly class UserInvited implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public UserInvitedPayload $payload,
    ) {
        $this->aggregateType = 'membership';
    }

    public function type(): string
    {
        return 'UserInvited';
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

    public function payload(): UserInvitedPayload
    {
        return $this->payload;
    }

    public static function fromMembership(Membership $membership, string $invitedByUserId): self
    {
        return new self(
            $membership->tenant_id,
            $membership->id,
            new UserInvitedPayload(
                $membership->user_id,
                $membership->id,
                $membership->tenant_id,
                $membership->role_id,
                $invitedByUserId,
            ),
        );
    }
}
