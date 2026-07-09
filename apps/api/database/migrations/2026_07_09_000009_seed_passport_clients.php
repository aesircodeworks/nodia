<?php

use Illuminate\Database\Migrations\Migration;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * The two provider-bound password-grant clients the stage-03 plan asks
 * for: one for the `users` provider (staff, `/v1/auth/staff/token`), one
 * for the `customers` provider (`/v1/auth/customer/token`). Both are
 * public clients (no secret): the wire contract never exposes OAuth
 * client mechanics to callers (StaffTokenRequestData and
 * CustomerTokenRequestData carry only credentials), so the controllers
 * built in later slices look each client up by provider plus grant type
 * at request time instead of holding a shared secret. A data migration
 * rather than a seeder so it runs exactly once, tracked the same way as
 * every schema change (mirrors the tenants sentinel row).
 */
return new class extends Migration
{
    public function up(): void
    {
        $clients = app(ClientRepository::class);

        $clients->createPasswordGrantClient(name: 'Staff', provider: 'users', confidential: false);
        $clients->createPasswordGrantClient(name: 'Customer', provider: 'customers', confidential: false);
    }

    public function down(): void
    {
        Client::query()
            ->whereIn('provider', ['users', 'customers'])
            ->get()
            ->filter(fn (Client $client): bool => $client->hasGrantType('password'))
            ->each(fn (Client $client) => $client->delete());
    }
};
