<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passport's published migration, adjusted so user_id matches the uuid
 * primary key on `users` (stage-03 plan, data-conventions Tenancy
 * exception). No tenant_id and no RLS: this is authentication
 * infrastructure keyed to identities, not tenant-scoped domain data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->uuid('user_id')->index();
            $table->foreignUuid('client_id');
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });

        Rls::grantUnscoped('oauth_auth_codes');
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_auth_codes');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
