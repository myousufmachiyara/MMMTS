<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item 1 — creating a Delivery Challan now ALSO auto-creates a linked
     * "pending" Direct job (job_no only, status='incomplete') at the same
     * time, before anyone has picked a vehicle for it. That pairing needs
     * to be tracked from the moment the DC is created — well before any
     * daily_job_vehicles row (and therefore delivery_challan_id link) even
     * exists — so this adds a direct, nullable daily_job_id column on
     * delivery_challans itself.
     *
     * This is a SEPARATE link from DailyJobVehicle::delivery_challan_id
     * (item 7's existing per-vehicle DC link):
     *   - daily_job_id (this column)  = "which job's header this DC belongs
     *     to" — set the moment the DC is created, always present for every
     *     DC created after this change.
     *   - delivery_challan_id (on daily_job_vehicles) = "which specific
     *     vehicle-row this DC has actually been assigned to" — set only
     *     once someone picks a vehicle for it. Kept in sync going forward
     *     by DailyJobController::syncDeliveryChallanLinks().
     *
     * A DC created before this migration (or one whose auto-created job was
     * later deleted) simply has daily_job_id = null — nothing breaks, it's
     * just treated as not yet attached to any job.
     */
    public function up(): void
    {
        if (Schema::hasColumn('delivery_challans', 'daily_job_id')) {
            return;
        }

        Schema::table('delivery_challans', function (Blueprint $table) {
            $table->unsignedBigInteger('daily_job_id')->nullable()->after('id');
            $table->foreign('daily_job_id')->references('id')->on('daily_jobs')->onDelete('set null');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('delivery_challans', 'daily_job_id')) {
            return;
        }

        Schema::table('delivery_challans', function (Blueprint $table) {
            $table->dropForeign(['daily_job_id']);
            $table->dropColumn('daily_job_id');
        });
    }
};