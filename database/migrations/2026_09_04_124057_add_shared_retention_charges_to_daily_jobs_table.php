<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-vehicle Direct jobs: everything except Vehicle and Container #
     * is the SAME across every vehicle on the job (route, trip plan, rent/
     * labour/yard/kanta, retention charges, extra port charges) — so these
     * move from being duplicated on every daily_job_vehicles row to being
     * entered once here on the job header.
     *
     * daily_jobs already has route_id, item_description, trip_type,
     * pickup_port_id, destination_location_id, dropoff_port_id, rent,
     * labour_charges, yard_charges, kanta_charges, and extra_port_charges_total
     * sitting unused since the item-3 multi-vehicle rewrite (they were the
     * original single-vehicle-job columns, left in place rather than
     * dropped) — those are simply reused as the new shared fields. Only the
     * newer "retention" naming (introduced on daily_job_vehicles for item 9,
     * replacing the old "per_day" naming) doesn't exist here yet, so this
     * migration adds it fresh rather than reinterpreting the old
     * per_day_* columns' meaning.
     */
    public function up(): void
    {
        if (Schema::hasColumn('daily_jobs', 'retention_total')) {
            return;
        }

        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->decimal('retention_first_day_charges', 12, 2)->default(0)->after('extra_port_charges_total');
            $table->decimal('retention_next_day_rate', 12, 2)->default(0)->after('retention_first_day_charges');
            $table->unsignedInteger('retention_extra_days')->default(0)->after('retention_next_day_rate');
            $table->decimal('retention_night_rate', 12, 2)->default(0)->after('retention_extra_days');
            $table->decimal('retention_total', 12, 2)->default(0)->after('retention_night_rate');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('daily_jobs', 'retention_total')) {
            return;
        }

        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'retention_first_day_charges',
                'retention_next_day_rate',
                'retention_extra_days',
                'retention_night_rate',
                'retention_total',
            ]);
        });
    }
};