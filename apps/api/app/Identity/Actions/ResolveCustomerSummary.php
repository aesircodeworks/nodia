<?php

namespace App\Identity\Actions;

use App\Identity\Data\CustomerSummaryData;
use App\Identity\Models\Customer;

/**
 * The read-only seam Orders calls to compose a customer summary onto
 * the staff order detail (stage-07 plan, Endpoints "GET
 * /v1/orders/{order}": composed through an Identity Action, never a
 * join).
 */
final class ResolveCustomerSummary
{
    public function __invoke(string $customerId): ?CustomerSummaryData
    {
        $customer = Customer::query()->find($customerId);

        return $customer === null ? null : CustomerSummaryData::fromModel($customer);
    }
}
