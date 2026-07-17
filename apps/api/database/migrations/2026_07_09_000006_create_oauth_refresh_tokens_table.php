<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passport's published migration (stage-03 plan). No user_id column here
 * to adjust; the refresh token's owner is reached through its access
 * token. No tenant_id and no RLS, same rationale as the sibling oauth
 * tables in this migration set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->char('access_token_id', 80)->index();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });

        Rls::grantUnscoped('oauth_refresh_tokens');
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
