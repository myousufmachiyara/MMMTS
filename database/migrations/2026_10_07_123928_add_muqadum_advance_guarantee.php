<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Muqadum jobs (job_type 'madqam' in the database) changed shape:
//   - each vehicle now just has a RENT (daily_job_madqam_lines.amount) — the
//     old rate-per-day / number-of-days columns are dropped;
//   - the job carries an ADVANCE and a GUARANTEE, both entered per vehicle and
//     multiplied by the vehicle count, plus the cash/bank account the advance
//     was received into and the voucher posted for it.
//
// daily_jobs.job_total for a Muqadum job = total rent + total guarantee.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('daily_jobs', 'mq_advance')) {
                $table->decimal('mq_advance', 12, 2)->default(0);           // per vehicle
            }
            if (!Schema::hasColumn('daily_jobs', 'mq_guarantee')) {
                $table->decimal('mq_guarantee', 12, 2)->default(0);         // per vehicle
            }
            if (!Schema::hasColumn('daily_jobs', 'mq_advance_account_id')) {
                $table->unsignedBigInteger('mq_advance_account_id')->nullable(); // chart_of_accounts: cash / bank
            }
            if (!Schema::hasColumn('daily_jobs', 'mq_advance_voucher_id')) {
                $table->unsignedBigInteger('mq_advance_voucher_id')->nullable(); // receipt voucher posted for the advance
            }
        });

        foreach (['rate_per_day', 'days'] as $col) {
            if (Schema::hasColumn('daily_job_madqam_lines', $col)) {
                Schema::table('daily_job_madqam_lines', function (Blueprint $table) use ($col) {
                    $table->dropColumn($col);
                });
            }
        }
    }

    public function down(): void
    {
        // Not reversed — the old rate/days figures can't be rebuilt from a rent.
    }
};