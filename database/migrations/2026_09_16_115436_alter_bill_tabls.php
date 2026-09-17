<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item 8 — which "Our Company" (see our_companies) is billing this
     * customer, chosen when the Bill is created. Drives the Bill print's
     * letterhead (logo/address/contact) instead of the old hard-coded
     * "M M LOGISTICS" block. An Invoice has no column of its own — it
     * simply reads the company off the first of the bills it aggregates
     * (see InvoiceController::print()), since an invoice is just a
     * container for bills that, in practice, all belong to the same
     * company. Nullable so a bill created before this migration (or with
     * no company picked) prints the old hard-coded letterhead as a
     * fallback rather than a blank header.
     */
    public function up(): void
    {
        if (Schema::hasColumn('bills', 'company_id')) {
            return;
        }

        Schema::table('bills', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('customer_id');
            $table->foreign('company_id')->references('id')->on('our_companies')->onDelete('set null');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('bills', 'company_id')) {
            return;
        }

        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};