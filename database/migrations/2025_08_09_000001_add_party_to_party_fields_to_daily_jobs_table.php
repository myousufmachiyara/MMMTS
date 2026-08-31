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
     *
     * Written to be safely re-runnable on databases where these FKs weren't
     * created under Laravel's default naming (or a prior attempt died
     * partway through): every drop/add of a constraint or column is
     * defensive rather than assuming a fixed starting state.
     */
    public function up(): void
    {
        // Drop each FK defensively — some databases (e.g. this one) don't have
        // them under Laravel's default constraint names, and dropForeign()
        // errors out (MySQL #1091) if asked to drop one that isn't there.
        foreach (['vehicle_id', 'route_id', 'pickup_port_id', 'dropoff_port_id'] as $column) {
            try {
                Schema::table('daily_jobs', function (Blueprint $table) use ($column) {
                    $table->dropForeign([$column]);
                });
            } catch (\Throwable $e) {
                // FK didn't exist on this database — nothing to drop, continue.
            }
        }

        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_id')->nullable()->change();
            $table->unsignedBigInteger('route_id')->nullable()->change();
            $table->unsignedBigInteger('pickup_port_id')->nullable()->change();
            $table->unsignedBigInteger('dropoff_port_id')->nullable()->change();
        });

        // Re-add each FK defensively too — if a prior attempt got this far
        // before failing further down, retrying would otherwise hit a
        // duplicate-constraint error.
        $refs = [
            'vehicle_id'      => ['vehicles', 'id'],
            'route_id'        => ['vehicle_routes', 'id'],
            'pickup_port_id'  => ['ports', 'id'],
            'dropoff_port_id' => ['ports', 'id'],
        ];
        foreach ($refs as $column => [$refTable, $refColumn]) {
            try {
                Schema::table('daily_jobs', function (Blueprint $table) use ($column, $refTable, $refColumn) {
                    $table->foreign($column)->references($refColumn)->on($refTable)->onDelete('restrict');
                });
            } catch (\Throwable $e) {
                // Already present from a prior partial run — fine.
            }
        }

        // ── Party-to-Party (Vendor to Customer directly) columns ─────────
        // Added one at a time with hasColumn guards so a retry after a
        // partial run doesn't hit "column already exists".
        Schema::table('daily_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('daily_jobs', 'vendor_id')) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('job_type'); // chart_of_accounts, account_type = vendor
            }
            if (!Schema::hasColumn('daily_jobs', 'pty_vehicle_no')) {
                $table->string('pty_vehicle_no', 50)->nullable()->after('vendor_id'); // vendor's own vehicle — free text, not our Vehicles master
            }
            if (!Schema::hasColumn('daily_jobs', 'pty_destination')) {
                $table->string('pty_destination', 255)->nullable()->after('pty_vehicle_no');
            }
            if (!Schema::hasColumn('daily_jobs', 'pty_size')) {
                $table->string('pty_size', 50)->nullable()->after('pty_destination'); // e.g. "1X40"
            }
            if (!Schema::hasColumn('daily_jobs', 'pty_amount')) {
                $table->decimal('pty_amount', 12, 2)->default(0)->after('pty_size');
            }
            if (!Schema::hasColumn('daily_jobs', 'pty_advance')) {
                $table->decimal('pty_advance', 12, 2)->default(0)->after('pty_amount');
            }
            if (!Schema::hasColumn('daily_jobs', 'pty_guarantee')) {
                $table->decimal('pty_guarantee', 12, 2)->default(0)->after('pty_advance');
            }
            if (!Schema::hasColumn('daily_jobs', 'pty_balance')) {
                $table->decimal('pty_balance', 12, 2)->default(0)->after('pty_guarantee'); // amount - advance - guarantee
            }
        });

        try {
            Schema::table('daily_jobs', function (Blueprint $table) {
                $table->foreign('vendor_id')->references('id')->on('chart_of_accounts')->onDelete('restrict');
            });
        } catch (\Throwable $e) {
            // Already present from a prior partial run — fine.
        }
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