<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tax now lives on the Invoice (taxable/non-taxable + % applied to the
     * combined trip-plan charges of the invoiced bills), not on the Bill.
     * The Bill becomes a plain internal aggregation of a customer's jobs —
     * no TAX/NON-TAX split, no tax_percent/tax_amount, no prefix distinction
     * in bill_no going forward (BILL-000001).
     */
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['bill_type', 'tax_percent', 'tax_amount']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->enum('bill_type', ['tax', 'non_tax'])->default('non_tax')->after('bill_no');
            $table->decimal('tax_percent', 5, 2)->nullable()->after('other_charges_subtotal');
            $table->decimal('tax_amount', 14, 2)->default(0)->after('tax_percent');
        });
    }
};
