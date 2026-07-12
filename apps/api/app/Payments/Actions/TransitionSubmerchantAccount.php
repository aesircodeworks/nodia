<?php

namespace App\Payments\Actions;

use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Models\SubmerchantAccount;
use App\Support\Audit\ActivityLogger;
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
 *
 * needs_review is the quarantine target for an unmapped raw gateway
 * status (stage-08d plan, Slice 4): it is reachable from the same
 * non-terminal sources as action_required, never from active, rejected,
 * or disabled, so a stale or malformed webhook can never regress a
 * completed or already-terminal onboarding into review. Once
 * quarantined, a subsequent recognized status resumes the normal
 * forward chain exactly like an action_required detour would.
 */
final class TransitionSubmerchantAccount
{
    /**
     * @var array<string, list<SubmerchantStatus>>
     */
    private const array SOURCES = [
        SubmerchantStatus::UnderReview->value => [SubmerchantStatus::Pending, SubmerchantStatus::ActionRequired, SubmerchantStatus::NeedsReview],
        SubmerchantStatus::ActionRequired->value => [SubmerchantStatus::Pending, SubmerchantStatus::UnderReview, SubmerchantStatus::NeedsReview],
        SubmerchantStatus::Active->value => [SubmerchantStatus::Pending, SubmerchantStatus::UnderReview, SubmerchantStatus::ActionRequired, SubmerchantStatus::Disabled, SubmerchantStatus::NeedsReview],
        SubmerchantStatus::Rejected->value => [SubmerchantStatus::Pending, SubmerchantStatus::UnderReview, SubmerchantStatus::ActionRequired, SubmerchantStatus::NeedsReview],
        SubmerchantStatus::Disabled->value => [SubmerchantStatus::Active],
        SubmerchantStatus::Pending->value => [SubmerchantStatus::Rejected],
        SubmerchantStatus::NeedsReview->value => [SubmerchantStatus::Pending, SubmerchantStatus::UnderReview, SubmerchantStatus::ActionRequired],
    ];

    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /**
     * @param  list<string>  $requirements
     */
    public function __invoke(string $accountId, SubmerchantStatus $target, array $requirements = []): ?SubmerchantAccount
    {
        $sources = self::SOURCES[$target->value];

        $updates = [
            'status' => $target->value,
            'requirements' => json_encode($requirements),
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

        $account = SubmerchantAccount::query()->findOrFail($accountId);

        $this->activityLogger->record(
            description: sprintf('Sub-merchant account %s moved to %s', $accountId, $target->value),
            causer: null,
            event: 'submerchant_state_changed',
            properties: [
                'submerchant_account_id' => $accountId,
                'gateway' => $account->gateway,
                'status' => $target->value,
            ],
        );

        return $account;
    }
}
