<?php

namespace App\Payments\Actions;

use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The payment expiry sweeper (stage-08a plan, Slice 7; scheduled every
 * minute). Conversion-time validation is the backstop: the confirm
 * transition's window guard means a lagging sweeper never lets an
 * expired-window payment through. Candidate discovery is a cross-tenant
 * platform SELECT, mirroring App\Inventory\Actions\ReleaseExpiredHolds;
 * each expiry then runs inside its own tenant transaction through
 * ExpirePayment's conditional UPDATE, recording PaymentExpired, so a
 * webhook racing the sweeper resolves to exactly one terminal status.
 */
final readonly class SweepExpiredPayments
{
    public function __construct(
        private TenantTransaction $transactions,
        private ExpirePayment $expirePayment,
    ) {}

    public function __invoke(): int
    {
        $expired = 0;

        foreach ($this->findCandidates() as $candidate) {
            $applied = $this->transactions->asTenant(
                $candidate->tenant_id,
                fn () => ($this->expirePayment)($candidate->id),
            );

            if ($applied !== null) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * @return Collection<int, Payment>
     */
    private function findCandidates(): Collection
    {
        $now = Date::now();

        return $this->transactions->asPlatform(
            fn () => Payment::query()
                ->select('id', 'tenant_id')
                ->where('status', PaymentStatus::Initiated->value)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->get(),
        );
    }
}
