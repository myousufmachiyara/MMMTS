<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Party-to-Party jobs can now carry several vehicles, like Direct jobs do.
//
// A Party-to-Party vehicle is the VENDOR's vehicle, so — unlike
// daily_job_vehicles (which points at our own Vehicles/Routes masters) —
// every field here is plain free text: vehicle no., route and size are typed
// in, never picked from a dropdown.
//
// The money fields (pty_cost / pty_sale_amount / pty_advance / pty_guarantee /
// pty_balance) stay on daily_jobs exactly as before and now mean "per
// vehicle"; the job's real totals are those amounts × the number of rows
// here (see DailyJob::billableVehicleCount() and the pty_total_* accessors).
//
// Every existing Party-to-Party job is backfilled with exactly one row built
// from its old single pty_vehicle_no / pty_size, so its amounts and totals
// stay exactly as they were (× 1).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_job_pty_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_job_id')->constrained('daily_jobs')->cascadeOnDelete();
            $table->string('vehicle_no', 50);
            $table->string('route', 255)->nullable();
            $table->string('size', 50)->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('daily_jobs')
            ->where('job_type', 'party_to_party')
            ->orderBy('id')
            ->get(['id', 'pty_vehicle_no', 'pty_size'])
            ->each(function ($job) use ($now) {
                DB::table('daily_job_pty_vehicles')->insert([
                    'daily_job_id' => $job->id,
                    'vehicle_no'   => $job->pty_vehicle_no ?: '—',
                    'route'        => null,
                    'size'         => $job->pty_size,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_job_pty_vehicles');
    }
};