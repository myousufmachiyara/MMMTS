<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery Challan (DC) — proof that a container was received/delivered by
     * OUR vehicle. This is just a printable document against a single Direct
     * job, not a standalone accounting record (no voucher, no receivable/
     * payable), so it lives directly on the job rather than as its own module.
     *
     * dc_no being set is what marks a job as "DC issued"; the rest are
     * snapshot fields — pre-filled from the job/vehicle/route when the DC is
     * first created but independently editable afterwards without touching
     * the job's own operational fields.
     */
    public function up(): void
    {
        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->string('dc_no', 30)->nullable()->unique()->after('job_type');
            $table->date('dc_date')->nullable()->after('dc_no');
            $table->string('dc_clearing_agent', 255)->nullable()->after('dc_date');
            $table->string('dc_unit', 100)->nullable()->after('dc_clearing_agent');
            $table->string('dc_bl_no', 100)->nullable()->after('dc_unit');
            $table->string('dc_container_no', 100)->nullable()->after('dc_bl_no');
            $table->string('dc_quantity', 100)->nullable()->after('dc_container_no');
            $table->text('dc_item_description')->nullable()->after('dc_quantity');
            $table->string('dc_truck_no', 100)->nullable()->after('dc_item_description');
        });
    }

    public function down(): void
    {
        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'dc_no', 'dc_date', 'dc_clearing_agent', 'dc_unit', 'dc_bl_no',
                'dc_container_no', 'dc_quantity', 'dc_item_description', 'dc_truck_no',
            ]);
        });
    }
};
