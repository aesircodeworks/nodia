<?php

namespace App\Support\Outbox;

/**
 * Marker for an OutboxSubscriber whose live delivery must serialize
 * against a reporting:rebuild pass on the same projection rather than
 * interleave with it (stage-11 plan, task 13; Risks "Deferral behavior
 * under the advisory lock"). ProcessOutboxDelivery takes ProjectionLock
 * shared around the handler's effect; when the rebuild command already
 * holds it exclusive, the shared attempt fails and the job releases
 * with backoff instead of failing, mirroring OrderedOutboxSubscriber's
 * own deferral posture (stage-04 plan ordered-helper risk) so a
 * stranded deferral still ages into the sweeper's reconciliation sweep.
 */
interface ProjectionLockedSubscriber
{
    /**
     * The ProjectionLock key; matches this subscriber's own registered
     * SubscriberRegistry name, and the reporting:rebuild {projection}
     * argument that names it.
     */
    public function projectionLockKey(): string;
}
