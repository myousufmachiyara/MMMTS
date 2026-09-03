<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery Challan (DC) becomes a standalone entity, creatable BEFORE
     * any job exists: pick a customer + a single port, fill the rest by
     * hand. It is later linked to a daily_job_vehicles row (one DC per
     * vehicle-row) by entering/selecting its DC# — "unlinked" simply means
     * no daily_job_vehicles row references it yet (see DailyJobVehicle's
     * delivery_challan_id, added in a later migration).
     *
     * This is separate from the legacy dc_* columns still sitting on
     * daily_jobs (added 2025_08_12) — those are left untouched for
     * historical jobs created before this change; new jobs never populate
     * them again.
     */
    public function up(): void
    {
        if (Schema::hasTable('delivery_challans')) {
            return;
        }

        Schema::create('delivery_challans', function (Blueprint $table) {
            $table->id();
            $table->string('dc_no', 30)->unique();
            $table->date('dc_date');
            $table->unsignedBigInteger('customer_id'); // chart_of_accounts, account_type = customer
            $table->unsignedBigInteger('port_id')->nullable(); // ports — single port per item 7 (no from/to)
            $table->string('clearing_agent', 255)->nullable();
            $table->string('unit', 100)->nullable();
            $table->string('bl_no', 100)->nullable();
            $table->string('container_no', 100)->nullable();
            $table->string('quantity', 100)->nullable();
            $table->text('item_description')->nullable();
            $table->string('truck_no', 100)->nullable();
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('customer_id')->references('id')->on('chart_of_accounts')->onDelete('restrict');
            $table->foreign('port_id')->references('id')->on('ports')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_challans');
    }
};