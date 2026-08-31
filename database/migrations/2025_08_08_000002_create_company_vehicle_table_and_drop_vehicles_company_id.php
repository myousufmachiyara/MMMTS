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
     */
    public function up(): void
    {
        Schema::create('company_vehicle', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('our_companies')->onDelete('cascade');
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('cascade');
            $table->unique(['company_id', 'vehicle_id']);
        });

        // Carry forward any existing single-company assignments into the pivot before dropping the column.
        if (Schema::hasColumn('vehicles', 'company_id')) {
            $rows = \Illuminate\Support\Facades\DB::table('vehicles')->whereNotNull('company_id')->get(['id', 'company_id']);
            $now = now();
            foreach ($rows as $row) {
                \Illuminate\Support\Facades\DB::table('company_vehicle')->insert([
                    'company_id' => $row->company_id,
                    'vehicle_id' => $row->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropForeign(['company_id']);
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
