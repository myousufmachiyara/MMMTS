<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Punch-list item 1 (round 3) — a single, optional "Detention Date" field on
// the Daily Job, alongside the existing detention_* charge fields (shared
// once across the whole job, same as those — see
// 2026_09_16_105153_rename_retention_to_detention_charges.php). Not
// mirrored onto daily_job_vehicles rows, matching how the other detention_*
// fields are job-header-only and deliberately zeroed per vehicle-line (see
// DailyJobController::persistDirect()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->date('detention_date')->nullable()->after('detention_total');
        });
    }

    public function down(): void
    {
        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->dropColumn('detention_date');
        });
    }
};