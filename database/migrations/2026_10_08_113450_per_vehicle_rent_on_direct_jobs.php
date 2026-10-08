<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Direct jobs: Rent is now entered PER VEHICLE (daily_job_vehicles.rent);
     * labour / yard / kanta / detention / extra-port charges stay shared on
     * the job and are still multiplied by the number of vehicles.
     *
     * To keep every existing job (and every bill already built from it)
     * showing exactly the same money:
     *   - each vehicle row gets the job's old single rent,
     *   - daily_jobs.rent becomes the TOTAL rent (old rent x vehicles),
     *   - daily_jobs.job_total becomes the FULL job total (old per-vehicle
     *     total x vehicles) instead of the per-vehicle figure.
     */
    public function up(): void
    {
        DB::table('daily_jobs')
            ->where('job_type', 'direct')
            ->orderBy('id')
            ->chunkById(200, function ($jobs) {
                foreach ($jobs as $job) {
                    $count = DB::table('daily_job_vehicles')
                        ->where('daily_job_id', $job->id)
                        ->whereNotNull('vehicle_id')
                        ->count();
                    $count = max($count, 1);

                    $oldRent = (float) $job->rent;

                    DB::table('daily_job_vehicles')
                        ->where('daily_job_id', $job->id)
                        ->update(['rent' => $oldRent]);

                    $perVehicle = $oldRent + (float) $job->labour_charges + (float) $job->yard_charges
                        + (float) $job->kanta_charges + (float) $job->detention_total
                        + (float) $job->extra_port_charges_total;

                    DB::table('daily_jobs')->where('id', $job->id)->update([
                        'rent'      => round($oldRent * $count, 2),
                        'job_total' => round($perVehicle * $count, 2),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('daily_jobs')
            ->where('job_type', 'direct')
            ->orderBy('id')
            ->chunkById(200, function ($jobs) {
                foreach ($jobs as $job) {
                    $count = max(DB::table('daily_job_vehicles')
                        ->where('daily_job_id', $job->id)
                        ->whereNotNull('vehicle_id')
                        ->count(), 1);

                    DB::table('daily_jobs')->where('id', $job->id)->update([
                        'rent'      => round((float) $job->rent / $count, 2),
                        'job_total' => round((float) $job->job_total / $count, 2),
                    ]);
                }
            });
    }
};