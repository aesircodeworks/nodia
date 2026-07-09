<?php

use App\Identity\IdentityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;
use App\Tenancy\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
    TenancyServiceProvider::class,
    IdentityServiceProvider::class,
];
