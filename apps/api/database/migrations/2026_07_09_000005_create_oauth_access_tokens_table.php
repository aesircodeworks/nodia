<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passport's published migration, adjusted so user_id matches the uuid
 * primary key shared by `users` and `customers` (stage-03 plan,
 * data-conventions Tenancy exception). No tenant_id and no RLS: this is
 * authentication infrastructure keyed to identities, not tenant-scoped
 * domain data; a customer's tenant travels in the token's JWT claims
 * instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->foreignUuid('client_id');
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });

        Rls::grantUnscoped('oauth_access_tokens');
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_access_tokens');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
