<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Direct jobs: detention is now entered per vehicle as
     *
     *   (day rate x number of days) + (night rate x number of nights)
     *
     * instead of being derived from the job's start/end dates. The first day's
     * rent is still the vehicle's Rent. The job end date stays on the job, but
     * no longer drives any amount.
     *
     *   daily_job_vehicles.detention_days    number of detention days
     *   daily_job_vehicles.detention_nights  number of detention nights
     *
     * Jobs saved under the previous design charged (day + night rate) x
     * (end date - start date). So that their totals do not change, each of
     * their vehicles gets that day count as its number of days (when it has a
     * day rate) and as its number of nights (when it has a night rate).
     */
    public function up(): void
    {
        Schema::table('daily_job_vehicles', function (Blueprint $table) {
            $table->unsignedInteger('detention_days')->default(0)->after('detention_day_charge');
            $table->unsignedInteger('detention_nights')->default(0)->after('detention_night_charge');
        });

        $jobs = DB::table('daily_jobs')->whereNotNull('end_date')->get(['id', 'date', 'end_date']);

        foreach ($jobs as $job) {
            $days = (int) round(
                Carbon::parse($job->date)->startOfDay()
                    ->diffInDays(Carbon::parse($job->end_date)->startOfDay(), false)
            );

            if ($days <= 0) {
                continue;
            }

            DB::table('daily_job_vehicles')
                ->where('daily_job_id', $job->id)
                ->where('detention_day_charge', '>', 0)
                ->update(['detention_days' => $days]);

            DB::table('daily_job_vehicles')
                ->where('daily_job_id', $job->id)
                ->where('detention_night_charge', '>', 0)
                ->update(['detention_nights' => $days]);
        }
    }

    public function down(): void
    {
        Schema::table('daily_job_vehicles', function (Blueprint $table) {
            $table->dropColumn(['detention_days', 'detention_nights']);
        });
    }
};