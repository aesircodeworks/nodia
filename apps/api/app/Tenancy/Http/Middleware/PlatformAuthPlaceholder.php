<?php

namespace App\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pass-through placeholder behind the auth.platform alias until Stage 3
 * rebinds it to Passport plus capability policies. It denies everything
 * outside the testing and local environments, so deploying the platform
 * admin surface prematurely returns 401 instead of exposing tenant CRUD.
 */
class PlatformAuthPlaceholder
{
    public function __construct(private readonly Application $app) {}

    /**
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->app->environment('testing', 'local')) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
