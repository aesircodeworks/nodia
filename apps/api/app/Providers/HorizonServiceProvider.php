<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Horizon's dashboard is local-only. The parent authorization callback
     * already admits app()->environment('local'); this gate is the
     * non-local path and stays closed. Not part of the /v1 API surface.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?object $user = null): bool {
            return false;
        });
    }
}
