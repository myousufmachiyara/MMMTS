<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extra Port Charges, simplified per item 2: just a port + its charges,
     * no from/to pairing any more. Scoped to a single vehicle-row (each
     * vehicle on a job can have its own extra port charges).
     */
    public function up(): void
    {
        if (Schema::hasTable('daily_job_vehicle_extra_port_charges')) {
            return;
        }

        Schema::create('daily_job_vehicle_extra_port_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('daily_job_vehicle_id');
            $table->unsignedBigInteger('port_id');
            $table->decimal('charges', 12, 2)->default(0);
            $table->timestamps();

            // Explicit short constraint name — MySQL's identifier limit is 64
            // chars and Laravel's auto-generated name
            // ("daily_job_vehicle_extra_port_charges_daily_job_vehicle_id_foreign",
            // 66 chars) exceeds it (error 1059) on MySQL, even though SQLite
            // (used for local verification) has no such limit and never
            // surfaced this.
            $table->foreign('daily_job_vehicle_id', 'djv_extra_port_charges_vehicle_fk')
                ->references('id')->on('daily_job_vehicles')->onDelete('cascade');
            $table->foreign('port_id')->references('id')->on('ports')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_job_vehicle_extra_port_charges');
    }
};