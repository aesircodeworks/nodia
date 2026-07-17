<?php

use App\Identity\Actions\SeedTemplateRoles;
use App\Identity\Models\Role;
use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the five global template roles once, the same data-migration
 * pattern the tenants sentinel row and the Passport clients use (task-01
 * decision journal): tracked like every other schema change rather than a
 * re-runnable Database\Seeders class. Under FORCE ROW LEVEL SECURITY the
 * owner's bare INSERT matches no policy on a NULL tenant_id row, so this
 * runs through nodia_platform's roles_platform_write, the only write path
 * a template row can ever satisfy. SeedTemplateRoles::handle() is
 * idempotent (updateOrCreate keyed on name), which its own unit test
 * proves by calling it a second time after this migration's insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('set local role '.Rls::PLATFORM_ROLE);

            app(SeedTemplateRoles::class)->handle();

            DB::statement('reset role');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('set local role '.Rls::PLATFORM_ROLE);

            Role::query()->whereNull('tenant_id')->whereIn('name', array_keys(SeedTemplateRoles::templates()))->delete();

            DB::statement('reset role');
        });
    }
};
