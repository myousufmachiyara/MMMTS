<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Madqam" — the simplest kind of daily job: a date, a customer, and a grid of
// OUR vehicles each hired out at a rate per day for a number of days. The
// job's total is just the sum of the grid's line totals.
//
// Two changes:
//   1. daily_jobs.job_type was a native ENUM('direct','party_to_party'); it is
//      widened to a plain VARCHAR so 'madqam' can be stored (the allowed values
//      are enforced by the app, as was already done for payment_lines.method).
//   2. daily_job_madqam_lines holds the vehicle grid — one row per vehicle:
//      vehicle, rate per day, number of days, and the stored line amount
//      (rate × days).
//
// Existing jobs are untouched.
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite can't alter an ENUM's CHECK constraint — rebuild the
            // column through a temporary copy.
            if (!Schema::hasColumn('daily_jobs', 'job_type_new')) {
                Schema::table('daily_jobs', function (Blueprint $table) {
                    $table->string('job_type_new', 30)->default('direct');
                });
                DB::statement('UPDATE daily_jobs SET job_type_new = job_type');
                Schema::table('daily_jobs', function (Blueprint $table) {
                    $table->dropColumn('job_type');
                });
                Schema::table('daily_jobs', function (Blueprint $table) {
                    $table->renameColumn('job_type_new', 'job_type');
                });
            }
        } else {
            // Left to throw on failure on purpose: if this didn't run, saving a
            // Madqam job would fail with "Data truncated", so better to know now.
            DB::statement("ALTER TABLE daily_jobs MODIFY job_type VARCHAR(30) NOT NULL DEFAULT 'direct'");
        }

        if (!Schema::hasTable('daily_job_madqam_lines')) {
            Schema::create('daily_job_madqam_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('daily_job_id')->constrained('daily_jobs')->cascadeOnDelete();
                $table->unsignedBigInteger('vehicle_id');   // vehicles master (our own vehicle)
                $table->decimal('rate_per_day', 12, 2)->default(0);
                $table->decimal('days', 8, 2)->default(1);
                $table->decimal('amount', 12, 2)->default(0); // rate_per_day × days
                $table->timestamps();

                $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('restrict');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_job_madqam_lines');
        // job_type stays VARCHAR — narrowing it back to an ENUM could reject
        // 'madqam' rows already saved.
    }
};