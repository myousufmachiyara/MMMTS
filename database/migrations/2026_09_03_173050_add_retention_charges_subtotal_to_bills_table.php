<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item 10: Bill create/print gets its own Retention Charges column,
     * broken out of "other_charges_subtotal" for visibility. Existing bills
     * are left at 0 here (their retention portion is still included in
     * other_charges_subtotal, just not split out) — recomputing history
     * accurately isn't possible from the aggregate columns already stored,
     * so this only takes effect for bills created going forward.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('bills', 'retention_charges_subtotal')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->decimal('retention_charges_subtotal', 12, 2)->default(0)->after('other_charges_subtotal');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bills', 'retention_charges_subtotal')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropColumn('retention_charges_subtotal');
            });
        }
    }
};