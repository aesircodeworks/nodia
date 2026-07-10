<?php

use App\EventCatalog\EventCatalogServiceProvider;
use App\Identity\IdentityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;
use App\Tenancy\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
    TenancyServiceProvider::class,
    IdentityServiceProvider::class,
    EventCatalogServiceProvider::class,
];
