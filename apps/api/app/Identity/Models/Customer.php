<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * Attendee identity, tenant-scoped, unique per tenant by email, never
 * holding roles or capabilities (ADR 007, system-design 5.2). Wired now so
 * the `customers` guard and provider (config/auth.php) and the seeded
 * password-grant client have a model to bind to; the `customers` table,
 * columns, and RLS policy ship in a later Stage 3 task, at which point
 * this class gains its fillable attributes and casts.
 */
class Customer extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasUuids, Notifiable;

    protected $table = 'customers';
}
