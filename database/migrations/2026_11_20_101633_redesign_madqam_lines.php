<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_job_madqam_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('daily_job_madqam_lines', 'start_date')) {
                $table->date('start_date')->nullable();
            }
            if (!Schema::hasColumn('daily_job_madqam_lines', 'end_date')) {
                $table->date('end_date')->nullable();
            }
            if (!Schema::hasColumn('daily_job_madqam_lines', 'destination')) {
                $table->string('destination')->nullable();
            }
            if (!Schema::hasColumn('daily_job_madqam_lines', 'advance')) {
                $table->decimal('advance', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('daily_job_madqam_lines', 'guarantee')) {
                $table->decimal('guarantee', 12, 2)->default(0);
            }
            if (!Schema::hasColumn('daily_job_madqam_lines', 'kanta')) {
                $table->decimal('kanta', 12, 2)->default(0);
            }
        });
    }

    public function down(): void {}
};