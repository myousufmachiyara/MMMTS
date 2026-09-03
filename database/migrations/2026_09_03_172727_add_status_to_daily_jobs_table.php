<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assistant/Admin split (item 11): a Direct job an assistant initiates
     * with only the basic fields is saved as 'incomplete' and can be
     * filtered in the jobs list; an admin (daily_jobs.fill_rates) later
     * fills in the rate fields and marks it 'complete'. Every job created
     * before this change is backfilled to 'complete' — it already carries
     * full data.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('daily_jobs', 'status')) {
            Schema::table('daily_jobs', function (Blueprint $table) {
                $table->enum('status', ['incomplete', 'complete'])->default('complete')->after('job_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('daily_jobs', 'status')) {
            Schema::table('daily_jobs', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};