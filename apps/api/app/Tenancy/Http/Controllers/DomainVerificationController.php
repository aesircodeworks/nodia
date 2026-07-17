<?php

namespace App\Tenancy\Http\Controllers;

use App\Tenancy\Actions\ResolveDomain;
use App\Tenancy\Data\DomainVerificationData;
use App\Tenancy\Exceptions\UnknownDomainException;
use Illuminate\Http\Response;

class DomainVerificationController
{
    public function __invoke(DomainVerificationData $data, ResolveDomain $resolveDomain): Response
    {
        if ($resolveDomain->tenantIdFor($data->domain) === null) {
            throw UnknownDomainException::forDomain($data->domain);
        }

        return response()->noContent();
    }
}
