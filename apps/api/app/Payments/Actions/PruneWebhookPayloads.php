<?php

namespace App\Payments\Actions;

use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The webhook payload pruner (stage-12 plan, Slice 3, task breakdown
 * item 7; system-design 14.3). Every gateway_webhook_events row carries
 * the sentinel platform tenant (stage-08a migration docblock), so
 * pruning runs entirely under that one tenant's own transaction, no
 * cross-tenant scan needed, mirroring
 * App\Payments\Actions\IngestGatewayWebhook's own asTenant(sentinel)
 * posture rather than App\Inventory\Actions\ReleaseExpiredHolds'
 * asPlatform-then-per-tenant shape.
 *
 * The whole effect is one conditional UPDATE guarded by
 * whereNull('payload_pruned_at'), never read-then-write
 * (data-conventions): rows already pruned are excluded from the match,
 * so a rerun affects zero of them, and the row together with its
 * unique (gateway, gateway_event_id) is left untouched, which is what
 * keeps webhook idempotence alive past the retention window.
 */
final readonly class PruneWebhookPayloads
{
    public function __construct(
        private TenantTransaction $transactions,
    ) {}

    public function __invoke(): int
    {
        $days = config()->integer('retention.webhook_payload_days');

        if ($days <= 0) {
            throw new RuntimeException(
                "retention.webhook_payload_days must be a positive number of days to run the webhook payload pruner, got {$days}.",
            );
        }

        $cutoff = Date::now()->subDays($days);

        return $this->transactions->asTenant(
            config()->string('tenancy.platform_tenant_id'),
            fn (): int => DB::table('gateway_webhook_events')
                ->whereNull('payload_pruned_at')
                ->where('received_at', '<=', $cutoff)
                ->update([
                    'payload' => null,
                    'payload_pruned_at' => Date::now(),
                    'updated_at' => Date::now(),
                ]),
        );
    }
}
