<?php

namespace App\Identity\Actions;

use App\Identity\Data\CustomerData;
use App\Identity\Data\RegisterCustomerData;
use App\Identity\Exceptions\CustomerEmailTakenException;
use App\Identity\Models\Customer;
use App\Support\Tenancy\TenantContext;
use App\Tenancy\Actions\ResolveTenantDefaultLocale;
use Illuminate\Database\UniqueConstraintViolationException;
use Spatie\LaravelData\Optional;

/**
 * POST /v1/customers (stage-03 plan, task breakdown item 13): guest
 * creation (no password) and full registration (password present) are
 * the same Action, distinguished only by whether the caller supplied a
 * password (system-design 5.2, ADR 007). The whole storefront request
 * already runs inside one transaction
 * (App\Tenancy\Http\Middleware\ResolveTenantFromHost wraps the entire
 * handler in TenantTransaction::asTenant()), so this Action needs no
 * transaction of its own, mirroring App\Identity\Actions\InviteUser's own
 * precedent; the Stage 4 outbox attachment point is marked below.
 */
final class RegisterCustomer
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ResolveTenantDefaultLocale $defaultLocale,
    ) {}

    public function __invoke(RegisterCustomerData $data): CustomerData
    {
        $tenantId = $this->tenantContext->tenantId();

        $password = $data->password instanceof Optional ? null : $data->password;

        $locale = $data->locale instanceof Optional || $data->locale === null
            ? ($this->defaultLocale)($tenantId)
            : $data->locale;

        try {
            $customer = Customer::create([
                'tenant_id' => $tenantId,
                'email' => $data->email,
                'name' => $data->name,
                'password' => $password,
                'locale' => $locale,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'customers_tenant_id_email_unique')) {
                throw CustomerEmailTakenException::for($data->email);
            }

            throw $e;
        }

        // Stage 4 attachment point: Outbox::record(CustomerRegistered::class, ...)
        // belongs here, inside the same transaction as the insert above
        // (stage-03 plan, Domain events).

        return CustomerData::fromModel($customer);
    }
}
