<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('bill_no', 30)->unique(); // TAX-000001 / NON-000001
            $table->enum('bill_type', ['tax', 'non_tax']);
            $table->unsignedBigInteger('customer_id'); // chart_of_accounts

            $table->date('from_date');
            $table->date('to_date');
            $table->date('bill_date');

            // Trip Plan portion (what tax is calculated on) vs everything else on the jobs
            $table->decimal('trip_plan_subtotal', 14, 2)->default(0);
            $table->decimal('other_charges_subtotal', 14, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0); // receivable from customer

            $table->unsignedBigInteger('voucher_id')->nullable(); // auto-posted Dr Customer / Cr Revenue
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('customer_id')->references('id')->on('chart_of_accounts')->onDelete('restrict');
            $table->foreign('voucher_id')->references('id')->on('vouchers')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['customer_id', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
