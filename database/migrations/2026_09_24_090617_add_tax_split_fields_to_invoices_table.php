<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Punch-list item 2 (round 3) — "Sale tax 20% paid by customer, 80% by
// company". The FULL sales tax liability (tax_amount, unchanged — still the
// true amount owed to the tax authority, calculated at tax_percent on the
// invoiced grand total) now gets split between who actually pays it:
//   - customer_tax_share_percent — what % of tax_amount is passed on to the
//     customer (defaults to 20.00, but stored per-invoice so a later change
//     to the default never rewrites history).
//   - customer_tax_amount — tax_amount * customer_tax_share_percent / 100.
//     This is what actually gets added to the customer's total_amount now
//     (see InvoiceController::store()) — total_amount is no longer
//     trip_plan_subtotal + tax_amount, it's trip_plan_subtotal +
//     customer_tax_amount.
//   - company_tax_amount — the remainder (tax_amount - customer_tax_amount)
//     that the company absorbs itself rather than billing the customer for
//     it. Stored for transparency/reporting; NOT currently auto-posted to
//     any ledger account (see InvoiceController::store()'s comment — there's
//     no designated expense account for this yet).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('customer_tax_share_percent', 5, 2)->nullable()->after('tax_percent');
            $table->decimal('customer_tax_amount', 14, 2)->default(0)->after('tax_amount');
            $table->decimal('company_tax_amount', 14, 2)->default(0)->after('customer_tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['customer_tax_share_percent', 'customer_tax_amount', 'company_tax_amount']);
        });
    }
};