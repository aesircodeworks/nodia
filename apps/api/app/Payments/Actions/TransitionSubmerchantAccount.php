<?php

namespace App\Payments\Actions;

use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Models\SubmerchantAccount;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The full SubmerchantStatus transition matrix (stage-08c plan, Data
 * model "submerchant_accounts"), applied as a conditional UPDATE
 * checked by affected-row count, never read-then-write, matching
 * ConfirmPayment/FailPayment's precedent. Driven by both the webhook
 * path (ProcessGatewayWebhook) and the manual refresh endpoint, so a
 * webhook and a refresh racing the same account converge on exactly one
 * winner.
 *
 * The legal sources per target encode both the forward chain (pending
 * to under_review/action_required/active/rejected, under_review and
 * action_required to each other and onward, active/disabled toggling,
 * rejected's retry back to pending) and the out-of-order forward-skip
 * rule: an event that jumps straight to a later legal target from an
 * earlier legal source (e.g. active arriving while still pending,
 * skipping under_review) is accepted because pending is already a
 * listed source for active. Anything not listed here (rejected or
 * disabled attempting any other transition, a stale event repeating an
 * already-superseded intermediate state) affects zero rows.
 */
final class TransitionSubmerchantAccount
{
    /**
     * @var array<string, list<SubmerchantStatus>>
     */
    private const array SOURCES = [
        SubmerchantStatus::UnderReview->value => [SubmerchantStatus::Pending, SubmerchantStatus::ActionRequired],
        SubmerchantStatus::ActionRequired->value => [SubmerchantStatus::Pending, SubmerchantStatus::UnderReview],
        SubmerchantStatus::Active->value => [SubmerchantStatus::Pending, SubmerchantStatus::UnderReview, SubmerchantStatus::ActionRequired, SubmerchantStatus::Disabled],
        SubmerchantStatus::Rejected->value => [SubmerchantStatus::Pending, SubmerchantStatus::UnderReview, SubmerchantStatus::ActionRequired],
        SubmerchantStatus::Disabled->value => [SubmerchantStatus::Active],
        SubmerchantStatus::Pending->value => [SubmerchantStatus::Rejected],
    ];

    /**
     * @param  list<string>  $requirements
     */
    public function __invoke(string $accountId, SubmerchantStatus $target, array $requirements = []): ?SubmerchantAccount
    {
        $sources = self::SOURCES[$target->value] ?? [];

        if ($sources === []) {
            return null;
        }

        $updates = [
            'status' => $target->value,
            'requirements' => json_encode(array_values($requirements)),
            'updated_at' => Date::now(),
        ];

        if ($target === SubmerchantStatus::Active) {
            $updates['activated_at'] = Date::now();
        }

        $affected = DB::table('submerchant_accounts')
            ->where('id', $accountId)
            ->whereIn('status', array_map(fn (SubmerchantStatus $status): string => $status->value, $sources))
            ->update($updates);

        if ($affected !== 1) {
            return null;
        }

        return SubmerchantAccount::query()->findOrFail($accountId);
    }
}
