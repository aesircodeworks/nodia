<?php

namespace App\Providers;

use App\Support\Correlation\CorrelationId;
use App\Support\Outbox\EventTypeRegistry;
use App\Support\Queue\UuidFailedJobProvider;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
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

        $this->app->extend('queue.failer', function ($failer, $app) {
            if (! $failer instanceof DatabaseUuidFailedJobProvider) {
                return $failer;
            }

            $config = $app['config']['queue.failed'];

            return new UuidFailedJobProvider(
                $app['db'],
                $config['database'] ?? null,
                $config['table'],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);
    }
}
