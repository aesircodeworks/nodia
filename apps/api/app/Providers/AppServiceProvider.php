<?php

namespace App\Providers;

use App\Support\Correlation\CorrelationId;
use App\Support\Outbox\EventTypeRegistry;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CorrelationId::class);
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(EventTypeRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);
    }
}
