<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Party-to-Party (Vendor to Customer directly) jobs don't use our own
     * vehicle/route/port masters — the vehicle is the vendor's own truck
     * (captured as free text) and there's no trip-plan/ports breakdown.
     * So the "direct" job columns that were required become nullable, and
     * new party-to-party-only columns are added (all nullable — only
     * populated when job_type = 'party_to_party').
     */
    public function up(): void
    {
        // SQLite can't drop/modify a column FK inline the way MySQL can via change(),
        // and doctrine/dbal (needed for ->change()) may not be installed — recreate
        // the FK columns as nullable the portable way: drop + re-add.
        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropForeign(['route_id']);
            $table->dropForeign(['pickup_port_id']);
            $table->dropForeign(['dropoff_port_id']);
        });

        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_id')->nullable()->change();
            $table->unsignedBigInteger('route_id')->nullable()->change();
            $table->unsignedBigInteger('pickup_port_id')->nullable()->change();
            $table->unsignedBigInteger('dropoff_port_id')->nullable()->change();
        });

        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('restrict');
            $table->foreign('route_id')->references('id')->on('vehicle_routes')->onDelete('restrict');
            $table->foreign('pickup_port_id')->references('id')->on('ports')->onDelete('restrict');
            $table->foreign('dropoff_port_id')->references('id')->on('ports')->onDelete('restrict');
        });

        Schema::table('daily_jobs', function (Blueprint $table) {
            // ── Party-to-Party (Vendor to Customer directly) ─────────
            $table->unsignedBigInteger('vendor_id')->nullable()->after('job_type'); // chart_of_accounts, account_type = vendor
            $table->string('pty_vehicle_no', 50)->nullable()->after('vendor_id');   // vendor's own vehicle — free text, not our Vehicles master
            $table->string('pty_destination', 255)->nullable()->after('pty_vehicle_no');
            $table->string('pty_size', 50)->nullable()->after('pty_destination');   // e.g. "1X40"
            $table->decimal('pty_amount', 12, 2)->default(0)->after('pty_size');
            $table->decimal('pty_advance', 12, 2)->default(0)->after('pty_amount');
            $table->decimal('pty_guarantee', 12, 2)->default(0)->after('pty_advance');
            $table->decimal('pty_balance', 12, 2)->default(0)->after('pty_guarantee'); // amount - advance - guarantee

            $table->foreign('vendor_id')->references('id')->on('chart_of_accounts')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
            $table->dropColumn([
                'vendor_id', 'pty_vehicle_no', 'pty_destination', 'pty_size',
                'pty_amount', 'pty_advance', 'pty_guarantee', 'pty_balance',
            ]);
        });

        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropForeign(['route_id']);
            $table->dropForeign(['pickup_port_id']);
            $table->dropForeign(['dropoff_port_id']);
        });

        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_id')->nullable(false)->change();
            $table->unsignedBigInteger('route_id')->nullable(false)->change();
            $table->unsignedBigInteger('pickup_port_id')->nullable(false)->change();
            $table->unsignedBigInteger('dropoff_port_id')->nullable(false)->change();
        });

        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('restrict');
            $table->foreign('route_id')->references('id')->on('vehicle_routes')->onDelete('restrict');
            $table->foreign('pickup_port_id')->references('id')->on('ports')->onDelete('restrict');
            $table->foreign('dropoff_port_id')->references('id')->on('ports')->onDelete('restrict');
        });
    }
};
