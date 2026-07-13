<?php

namespace App\Inventory\Actions;

use App\EventCatalog\Actions\ResolveOnSalePoliciesForEvents;
use App\Inventory\Support\OnSaleQueue;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Date;

/**
 * The gatekeeper's own tick (stage-10 plan, task breakdown item 8;
 * Data model "Redis structures"): iterates onsale:active and admits
 * entrants into checkout at each flagged event's own configured rate
 * (events.on_sale_policy.admission_rate_per_minute, falling back to
 * config/onsale.php's platform default), via App\Console\Commands\
 * GatekeeperCommand every tick of the scheduler (bootstrap/app.php).
 *
 * Discovery is Redis-only (OnSaleQueue::activeMembers(), never a
 * database read), then flagged-event configuration for every discovered
 * event is read in one batched, cross-tenant platform-role query
 * (App\EventCatalog\Actions\ResolveOnSalePoliciesForEvents, run under
 * TenantTransaction::asPlatform() exactly as App\Support\Outbox\Jobs\
 * ProcessOutboxDelivery reads its own envelope before any tenant context
 * exists), so one tick never opens one transaction per tenant just to
 * read policy. The admission itself is pure Redis (OnSaleQueue::admit(),
 * a single atomic Lua script per event) with no database transaction of
 * its own, matching stage-10's Domain events section: "there is no
 * database transaction on the queue path, so there is legitimately
 * nothing to record."
 */
final readonly class RunGatekeeperTick
{
    public function __construct(
        private TenantTransaction $transactions,
        private ResolveOnSalePoliciesForEvents $resolvePolicies,
    ) {}

    public function __invoke(): int
    {
        $members = OnSaleQueue::activeMembers();

        if ($members === []) {
            return 0;
        }

        $eventIds = array_values(array_unique(array_map(
            static fn (array $member): string => $member['event_id'],
            $members,
        )));

        $policies = $this->transactions->asPlatform(
            fn () => ($this->resolvePolicies)($eventIds),
        );

        $now = Date::now();
        $tokenTtlSeconds = config()->integer('onsale.admission_token.ttl_seconds');
        $defaultRate = config()->integer('onsale.admission_rate_per_minute_default');

        $admitted = 0;

        foreach ($members as $member) {
            $policy = $policies[$member['event_id']] ?? null;

            // An event no longer known, or flipped off high_demand since
            // its last entrant joined (stage-10 plan Risks: policy
            // changes are not retrofitted onto an already-queued
            // event), is skipped rather than admitted from; its
            // onsale:active membership is left in place as a harmless,
            // never-authoritative Redis artifact (system-design 9.2).
            if ($policy === null || ! $policy->highDemand) {
                continue;
            }

            $rate = $policy->admissionRatePerMinute ?? $defaultRate;

            $admitted += count(OnSaleQueue::admit($member['tenant_id'], $member['event_id'], $rate, $now, $tokenTtlSeconds));

            OnSaleQueue::trimAdmitted($member['tenant_id'], $member['event_id'], $now);
        }

        return $admitted;
    }
}
