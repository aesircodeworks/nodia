<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Inventory migration (stage-10 plan, Data model
 * "purchase_counters": "the quantity actually counted for each item is
 * persisted on the hold item as counted_quantity ... zero for items
 * whose ticket type had no limit at hold time, written in the same
 * CreateHold transaction as the increment"). Non-null with a DEFAULT of
 * 0: every hold_items row that predates this column, and every future
 * item whose ticket type carries no max_per_customer, reads back the
 * "never counted" state with no backfill Action involved (mirrors
 * events.on_sale_policy's own fast-default posture from this same
 * stage). Release and expiry (App\Inventory\Actions\Concerns\
 * ReleasesHoldInventory) decrement exactly this recorded amount,
 * ignoring the ticket type's current max_per_customer, so a limit added
 * or cleared between hold creation and release can neither strand
 * counted quantity nor decrement quantity the hold never contributed.
 * The CHECK is defense in depth behind the same posture ticket_types'
 * own max_per_customer CHECK takes; a value above the item's own
 * quantity would be a broken invariant, never written by
 * App\Inventory\Actions\CreateHold by construction, so no CHECK ties the
 * two columns together (that condition can only be proven false, never
 * exempted like a nullable column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hold_items', function (Blueprint $table): void {
            $table->integer('counted_quantity')->default(0)->after('quantity');
        });

        DB::statement('alter table hold_items add constraint hold_items_counted_quantity_non_negative check (counted_quantity >= 0)');
    }

    public function down(): void
    {
        Schema::table('hold_items', function (Blueprint $table): void {
            $table->dropColumn('counted_quantity');
        });
    }
};
