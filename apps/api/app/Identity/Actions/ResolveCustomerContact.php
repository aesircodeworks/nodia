<?php

namespace App\Identity\Actions;

use App\Identity\Data\CustomerContactData;
use App\Identity\Models\Customer;

/**
 * The read-only seam Orders calls to address buyer email (stage-08a
 * plan, Slice 9): contact facts only, composed through an Identity
 * Action, never a join (section 3.1 boundary rule), mirroring
 * ResolveCustomerSummary.
 */
final class ResolveCustomerContact
{
    public function __invoke(string $customerId): ?CustomerContactData
    {
        $customer = Customer::query()->find($customerId);

        return $customer === null ? null : new CustomerContactData(
            $customer->email,
            $customer->name,
            $customer->locale,
        );
    }
}
