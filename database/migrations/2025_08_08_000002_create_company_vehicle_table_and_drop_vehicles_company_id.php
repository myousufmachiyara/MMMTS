<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Supersedes the single vehicles.company_id FK (added in
     * 2025_08_02_000001) with a many-to-many pivot — a vehicle can now be
     * linked to multiple "Our Companies" at once.
     *
     * Written to be safely re-runnable: MySQL doesn't support transactional
     * DDL, so a run that failed partway through (e.g. table already created,
     * rows already copied) can be retried without any manual DB cleanup.
     */
    public function up(): void
    {
        if (!Schema::hasTable('company_vehicle')) {
            Schema::create('company_vehicle', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('vehicle_id');
                $table->timestamps();

                $table->foreign('company_id')->references('id')->on('our_companies')->onDelete('cascade');
                $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('cascade');
                $table->unique(['company_id', 'vehicle_id']);
            });
        }

        // Carry forward any existing single-company assignments into the pivot before dropping the column.
        if (Schema::hasColumn('vehicles', 'company_id')) {
            $rows = \Illuminate\Support\Facades\DB::table('vehicles')->whereNotNull('company_id')->get(['id', 'company_id']);
            $now = now();
            foreach ($rows as $row) {
                // insertOrIgnore — safe to retry after a prior partial run already copied some rows
                // across (the pivot's unique(['company_id','vehicle_id']) would otherwise throw a
                // duplicate-key error on a retry).
                \Illuminate\Support\Facades\DB::table('company_vehicle')->insertOrIgnore([
                    'company_id' => $row->company_id,
                    'vehicle_id' => $row->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Some environments have the vehicles.company_id column WITHOUT the FK constraint
            // that's supposed to go with it (added manually, or already dropped by a prior
            // partial run of this migration) — dropForeign() errors out (MySQL #1091) if asked
            // to drop a constraint that isn't there. Try it on its own and swallow only that
            // failure; the column drop always runs regardless, in its own statement.
            try {
                Schema::table('vehicles', function (Blueprint $table) {
                    $table->dropForeign(['company_id']);
                });
            } catch (\Throwable $e) {
                // FK didn't exist on this database — nothing to reverse, carry on.
            }

            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropColumn('company_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('id');
            $table->foreign('company_id')->references('id')->on('our_companies')->onDelete('set null');
        });

        Schema::dropIfExists('company_vehicle');
    }
};