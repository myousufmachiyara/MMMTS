<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Daily Job (Direct) can now involve multiple vehicles. Each vehicle
     * is its own line item here, carrying everything that used to sit flat
     * on daily_jobs: the vehicle/route/container/description, the trip plan
     * (locations only — no charges, per item 2), rent/labour/yard/kanta, and
     * retention charges (renamed from "per day charges", now with a night
     * rate — item 2 & 9). daily_jobs itself becomes a thin header (date,
     * customer, status, job_no, job_total) once this exists.
     *
     * Field split for the assistant/admin access split (item 11):
     *   BASIC  (assistant can set): vehicle_id, route_id, container_no, item_description
     *   ADMIN  (locked behind daily_jobs.fill_rates): everything else below
     *          (trip plan ports, rent/labour/yard/kanta, retention charges,
     *          extra port charges, DC link)
     *
     * The old daily_jobs flat columns (vehicle_id, trip_plan_total, etc.,
     * added 2025_08_04) are deliberately left in place, not dropped —
     * dropping columns with real production data is exactly the kind of
     * destructive change that broke migrations earlier in this project.
     * A later migration backfills one row here per existing direct job so
     * every job — old or new — is read the same way going forward.
     */
    public function up(): void
    {
        if (Schema::hasTable('daily_job_vehicles')) {
            return;
        }

        Schema::create('daily_job_vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('daily_job_id');

            // ── Basic fields (assistant-fillable) ──────────────────────
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('route_id');
            $table->string('container_no', 100)->nullable();
            $table->text('item_description')->nullable();

            // ── Trip Plan — locations only, no charges (item 2) ────────
            $table->enum('trip_type', ['one_way', 'two_way'])->default('one_way');
            $table->unsignedBigInteger('pickup_port_id')->nullable();
            $table->unsignedBigInteger('destination_location_id')->nullable();
            $table->unsignedBigInteger('dropoff_port_id')->nullable();

            // ── Rate fields (admin-only, filled in later) ──────────────
            $table->decimal('rent', 12, 2)->default(0);
            $table->decimal('labour_charges', 12, 2)->default(0);
            $table->decimal('yard_charges', 12, 2)->default(0);
            $table->decimal('kanta_charges', 12, 2)->default(0);

            // ── Retention Charges (formerly "Per Day Charges", item 9) ──
            // total = first_day_charges + (next_day_rate * extra_days) + (night_rate * extra_days)
            // Nights = extra_days: a job kept beyond day 1 spends one extra
            // night per extra day, so night charges only kick in once
            // extra_days >= 1 (item 2's "applied if day > 1" rule) — no
            // separate nights counter is needed, it rides on extra_days.
            $table->decimal('retention_first_day_charges', 12, 2)->default(0);
            $table->decimal('retention_next_day_rate', 12, 2)->default(0);
            $table->unsignedInteger('retention_extra_days')->default(0);
            $table->decimal('retention_night_rate', 12, 2)->default(0);
            $table->decimal('retention_total', 12, 2)->default(0);

            // Sum of this row's daily_job_vehicle_extra_port_charges — stored for fast listing/billing
            $table->decimal('extra_port_charges_total', 12, 2)->default(0);

            // line_total = rent + labour + yard + kanta + retention_total + extra_port_charges_total
            // (trip plan carries no charges of its own any more)
            $table->decimal('line_total', 12, 2)->default(0);

            // One Delivery Challan per vehicle-row (item 7) — linked by
            // entering/selecting an existing UNLINKED dc_no, never created
            // inline here.
            $table->unsignedBigInteger('delivery_challan_id')->nullable();

            // Marks a row that was backfilled from a pre-rewrite single-vehicle
            // job — its historical extra port charges (from/to port pairs)
            // live only in the old daily_job_extra_port_charges table, kept
            // read-only via DailyJob::legacyExtraPortCharges() rather than
            // force-migrated into the new single-port shape.
            $table->boolean('is_legacy')->default(false);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('daily_job_id')->references('id')->on('daily_jobs')->onDelete('cascade');
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('restrict');
            $table->foreign('route_id')->references('id')->on('vehicle_routes')->onDelete('restrict');
            $table->foreign('pickup_port_id')->references('id')->on('ports')->onDelete('restrict');
            $table->foreign('dropoff_port_id')->references('id')->on('ports')->onDelete('restrict');
            $table->foreign('destination_location_id')->references('id')->on('customer_locations')->onDelete('set null');
            $table->foreign('delivery_challan_id')->references('id')->on('delivery_challans')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_job_vehicles');
    }
};