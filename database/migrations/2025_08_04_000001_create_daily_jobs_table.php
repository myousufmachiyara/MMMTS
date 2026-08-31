<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_no', 30)->unique();

            // 'party_to_party' fields/flow to be added once briefed — column reserved now
            // so job_type can be filtered/reported on from day one.
            $table->enum('job_type', ['direct', 'party_to_party'])->default('direct');

            // ── Job Info ──────────────────────────────────────────────
            $table->date('date');
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('customer_id'); // chart_of_accounts, account_type = customer
            $table->unsignedBigInteger('route_id');    // vehicle_routes
            $table->string('container_no', 100)->nullable();
            $table->text('item_description')->nullable();
            $table->decimal('rent', 12, 2)->default(0);
            $table->decimal('labour_charges', 12, 2)->default(0);
            $table->decimal('yard_charges', 12, 2)->default(0);
            $table->decimal('kanta_charges', 12, 2)->default(0); // weight bridge / Kanta

            // ── Trip Plan ─────────────────────────────────────────────
            // one_way = pickup + dropoff only. two_way = pickup + destination + dropoff.
            $table->enum('trip_type', ['one_way', 'two_way'])->default('one_way');
            $table->unsignedBigInteger('pickup_port_id');           // ports
            $table->decimal('pickup_charges', 12, 2)->default(0);
            $table->unsignedBigInteger('destination_location_id')->nullable(); // customer_locations, two_way only
            $table->decimal('destination_charges', 12, 2)->default(0);
            $table->unsignedBigInteger('dropoff_port_id');          // ports
            $table->decimal('dropoff_charges', 12, 2)->default(0);
            // Stored so Bill generation doesn't need to recompute from live rows —
            // this is the amount tax is calculated against.
            $table->decimal('trip_plan_total', 12, 2)->default(0);

            // ── Per Day Charges ───────────────────────────────────────
            // total = first_charges + (next_day_rate * extra_days)
            $table->decimal('per_day_first_charges', 12, 2)->default(0);
            $table->decimal('per_day_next_rate', 12, 2)->default(0);
            $table->unsignedInteger('per_day_extra_days')->default(0);
            $table->decimal('per_day_total', 12, 2)->default(0);

            // Sum of daily_job_extra_port_charges rows — stored for fast listing/billing
            $table->decimal('extra_port_charges_total', 12, 2)->default(0);

            // Grand total = rent + labour + yard + kanta + trip_plan_total
            //             + extra_port_charges_total + per_day_total
            $table->decimal('job_total', 12, 2)->default(0);

            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('restrict');
            $table->foreign('customer_id')->references('id')->on('chart_of_accounts')->onDelete('restrict');
            $table->foreign('route_id')->references('id')->on('vehicle_routes')->onDelete('restrict');
            $table->foreign('pickup_port_id')->references('id')->on('ports')->onDelete('restrict');
            $table->foreign('dropoff_port_id')->references('id')->on('ports')->onDelete('restrict');
            $table->foreign('destination_location_id')->references('id')->on('customer_locations')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['date', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_jobs');
    }
};
