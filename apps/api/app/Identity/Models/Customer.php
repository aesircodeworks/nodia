<?php

namespace App\Identity\Models;

use Database\Factories\Identity\Models\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * Attendee identity, tenant-scoped, unique per tenant by email, never
 * holding roles or capabilities (ADR 007, system-design 5.2). `password`
 * is nullable for guest checkout; `anonymized_at` is unused until Stage
 * 12's erasure flow.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string $name
 * @property string|null $password
 * @property string|null $locale
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $anonymized_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['tenant_id', 'email', 'name', 'password', 'locale'])]
#[Hidden(['password'])]
class Customer extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $table = 'customers';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }
}
