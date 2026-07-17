<?php

namespace App\Identity\Actions;

use App\Identity\Events\CustomerAnonymized;
use App\Identity\Exceptions\CustomerAlreadyAnonymizedException;
use App\Identity\Models\Customer;
use App\Identity\Support\AnonymizationPlaceholder;
use App\Support\Outbox\OutboxRecorder;
use Illuminate\Support\Facades\Date;

/**
 * Erasure's synchronous core (stage-12 plan, Data model "No column change
 * for erasure"; Endpoints POST /v1/customers/{customer}/data-subject-
 * requests). Overwrites name and email with a deterministic, non-
 * reversible, per-tenant-unique placeholder, nulls password, and stamps
 * anonymized_at, all in one conditional UPDATE guarded on anonymized_at
 * is null and checked by affected-row count, never read-then-write
 * (CLAUDE.md): a repeat erasure or a lost concurrency race both surface as
 * zero affected rows, mapped to customer_already_anonymized, rather than a
 * second write. Revokes every outstanding access and refresh token the
 * customer holds through the Stage 3 server-side revocation primitive
 * (RevokeAllUserTokens already covers both, since a live access token's
 * own refresh token is revoked alongside it) and records exactly one
 * CustomerAnonymized event in the same transaction, only when the
 * conditional UPDATE's affected-row count is 1.
 */
final readonly class AnonymizeCustomer
{
    public function __construct(
        private RevokeAllUserTokens $revokeTokens,
        private OutboxRecorder $outbox,
    ) {}

    public function __invoke(Customer $customer, string $dataSubjectRequestId): void
    {
        $placeholder = AnonymizationPlaceholder::forCustomer($customer->id);

        $affected = Customer::query()
            ->whereKey($customer->id)
            ->whereNull('anonymized_at')
            ->update([
                'name' => $placeholder->name,
                'email' => $placeholder->email,
                'password' => null,
                'anonymized_at' => Date::now(),
            ]);

        if ($affected !== 1) {
            throw CustomerAlreadyAnonymizedException::forId($customer->id);
        }

        ($this->revokeTokens)($customer->id);

        $this->outbox->record(CustomerAnonymized::forCustomer($customer->tenant_id, $customer->id, $dataSubjectRequestId));
    }
}
