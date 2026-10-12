<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Direct jobs: detention is now charged PER VEHICLE, per day, for every day
     * after the first (the first day is covered by the vehicle's Rent).
     *
     *   daily_jobs.end_date                       job end date (days = end - start)
     *   daily_job_vehicles.detention_day_charge   detention, day rate, per day
     *   daily_job_vehicles.detention_night_charge detention, night rate, per day
     *
     * No data is touched: jobs saved before this change keep their old shared
     * detention (daily_jobs.detention_total) exactly as it was, and have no
     * end date, so no per-vehicle detention is added on top of it.
     */
    public function up(): void
    {
        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('date');
        });

        Schema::table('daily_job_vehicles', function (Blueprint $table) {
            $table->decimal('detention_day_charge', 12, 2)->default(0)->after('rent');
            $table->decimal('detention_night_charge', 12, 2)->default(0)->after('detention_day_charge');
        });
    }

    public function down(): void
    {
        Schema::table('daily_job_vehicles', function (Blueprint $table) {
            $table->dropColumn(['detention_day_charge', 'detention_night_charge']);
        });

        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->dropColumn('end_date');
        });
    }
};