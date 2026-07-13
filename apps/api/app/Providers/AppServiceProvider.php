<?php

namespace App\Providers;

use App\Support\Correlation\CorrelationId;
use App\Support\Outbox\EventTypeRegistry;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Queue\UuidFailedJobProvider;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
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
        // Production registry starts empty; real consumers register from
        // their owning context providers in later stages. Test fixtures
        // register only in test setup (stage-04 plan Slice 2).
        $this->app->singleton(SubscriberRegistry::class);

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

        // outbox:replay-failed's App\Support\Outbox\OutboxFailedReplay
        // (stage-12 plan, Slice 5, task breakdown item 11) needs the
        // failer as a constructor-injected dependency; the framework
        // only ever binds it under the 'queue.failer' container key
        // above, never against its own interface.
        $this->app->bind(FailedJobProviderInterface::class, fn ($app) => $app['queue.failer']);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Date::use(CarbonImmutable::class);
    }
}
