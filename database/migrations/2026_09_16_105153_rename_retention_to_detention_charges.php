<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item 6 — rename "Retention Charges" to "Detention Charges" everywhere,
     * including the underlying columns (per the user's explicit choice of a
     * full rename over a labels-only cosmetic change).
     *
     * Implemented as add-new-column / copy-data / drop-old-column rather
     * than Schema::renameColumn(), which needs doctrine/dbal (not installed
     * here) and behaves inconsistently across MySQL/SQLite. Plain SQL
     * UPDATE ... SET col = col works identically on both.
     *
     * Guarded per-table/per-column so this is safe to run whether or not
     * the 2026_09_04_000001 migration (which added the daily_jobs columns
     * this renames) has already run in a given environment.
     */
    public function up(): void
    {
        $this->renameSet('daily_jobs');
        $this->renameSet('daily_job_vehicles');

        if (Schema::hasColumn('bills', 'retention_charges_subtotal') && !Schema::hasColumn('bills', 'detention_charges_subtotal')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->decimal('detention_charges_subtotal', 12, 2)->default(0)->after('retention_charges_subtotal');
            });

            DB::statement('UPDATE bills SET detention_charges_subtotal = retention_charges_subtotal');

            Schema::table('bills', function (Blueprint $table) {
                $table->dropColumn('retention_charges_subtotal');
            });
        }
    }

    private function renameSet(string $table): void
    {
        if (!Schema::hasColumn($table, 'retention_total') || Schema::hasColumn($table, 'detention_total')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->decimal('detention_first_day_charges', 12, 2)->default(0)->after('retention_first_day_charges');
            $t->decimal('detention_next_day_rate', 12, 2)->default(0)->after('retention_next_day_rate');
            $t->unsignedInteger('detention_extra_days')->default(0)->after('retention_extra_days');
            $t->decimal('detention_night_rate', 12, 2)->default(0)->after('retention_night_rate');
            $t->decimal('detention_total', 12, 2)->default(0)->after('retention_total');
        });

        DB::statement("UPDATE {$table} SET
            detention_first_day_charges = retention_first_day_charges,
            detention_next_day_rate     = retention_next_day_rate,
            detention_extra_days        = retention_extra_days,
            detention_night_rate        = retention_night_rate,
            detention_total             = retention_total");

        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn([
                'retention_first_day_charges',
                'retention_next_day_rate',
                'retention_extra_days',
                'retention_night_rate',
                'retention_total',
            ]);
        });
    }

    public function down(): void
    {
        $this->revertSet('daily_jobs');
        $this->revertSet('daily_job_vehicles');

        if (Schema::hasColumn('bills', 'detention_charges_subtotal') && !Schema::hasColumn('bills', 'retention_charges_subtotal')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->decimal('retention_charges_subtotal', 12, 2)->default(0)->after('other_charges_subtotal');
            });

            DB::statement('UPDATE bills SET retention_charges_subtotal = detention_charges_subtotal');

            Schema::table('bills', function (Blueprint $table) {
                $table->dropColumn('detention_charges_subtotal');
            });
        }
    }

    private function revertSet(string $table): void
    {
        if (!Schema::hasColumn($table, 'detention_total') || Schema::hasColumn($table, 'retention_total')) {
            return;
        }

        Schema::table($table, function (Blueprint $t) {
            $t->decimal('retention_first_day_charges', 12, 2)->default(0)->after('detention_first_day_charges');
            $t->decimal('retention_next_day_rate', 12, 2)->default(0)->after('detention_next_day_rate');
            $t->unsignedInteger('retention_extra_days')->default(0)->after('detention_extra_days');
            $t->decimal('retention_night_rate', 12, 2)->default(0)->after('detention_night_rate');
            $t->decimal('retention_total', 12, 2)->default(0)->after('detention_total');
        });

        DB::statement("UPDATE {$table} SET
            retention_first_day_charges = detention_first_day_charges,
            retention_next_day_rate     = detention_next_day_rate,
            retention_extra_days        = detention_extra_days,
            retention_night_rate        = detention_night_rate,
            retention_total             = detention_total");

        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn([
                'detention_first_day_charges',
                'detention_next_day_rate',
                'detention_extra_days',
                'detention_night_rate',
                'detention_total',
            ]);
        });
    }
};