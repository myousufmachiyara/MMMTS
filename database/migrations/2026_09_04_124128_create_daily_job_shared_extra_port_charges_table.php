<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extra Port Charges become a job-level shared list (one list per job,
     * applying to the job as a whole rather than to any one vehicle) —
     * "merged" across vehicles per the multi-vehicle-form change.
     *
     * This is a NEW table rather than reusing either existing extra-port-
     * charges table: the legacy daily_job_extra_port_charges (2025_08_04)
     * still uses the old from_port_id/to_port_id pairing that predates
     * item 2's single-port simplification, and daily_job_vehicle_extra_port_charges
     * (2026_09_03) is scoped per vehicle-row, not per job. Keeping this
     * separate leaves both of those exactly as they are for historical jobs.
     */
    public function up(): void
    {
        if (Schema::hasTable('daily_job_shared_extra_port_charges')) {
            return;
        }

        Schema::create('daily_job_shared_extra_port_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('daily_job_id');
            $table->unsignedBigInteger('port_id');
            $table->decimal('charges', 12, 2)->default(0);
            $table->timestamps();

            // Explicit short names — same MySQL 64-char identifier limit
            // that bit daily_job_vehicle_extra_port_charges earlier.
            $table->foreign('daily_job_id', 'djspc_job_fk')
                ->references('id')->on('daily_jobs')->onDelete('cascade');
            $table->foreign('port_id', 'djspc_port_fk')
                ->references('id')->on('ports')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_job_shared_extra_port_charges');
    }
};