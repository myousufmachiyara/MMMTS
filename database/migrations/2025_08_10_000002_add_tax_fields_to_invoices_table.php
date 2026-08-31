<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tax now lives here rather than on the Bill: taxable/non-taxable + a
     * (default 18%, editable) percentage applied to the combined trip-plan
     * charges of the bills this invoice aggregates. total_containers is the
     * count of job/container entries across those bills (1 job = 1 container,
     * per the legacy sheet convention).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('is_taxable')->default(false)->after('customer_id');
            $table->decimal('tax_percent', 5, 2)->nullable()->after('is_taxable');
            $table->decimal('trip_plan_subtotal', 14, 2)->default(0)->after('tax_percent');
            $table->decimal('tax_amount', 14, 2)->default(0)->after('trip_plan_subtotal');
            $table->unsignedInteger('total_containers')->default(0)->after('to_date');
            $table->unsignedBigInteger('voucher_id')->nullable()->after('total_amount'); // tax journal: Dr Customer / Cr Tax Payable

            $table->foreign('voucher_id')->references('id')->on('vouchers')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['voucher_id']);
            $table->dropColumn(['is_taxable', 'tax_percent', 'trip_plan_subtotal', 'tax_amount', 'total_containers', 'voucher_id']);
        });
    }
};
