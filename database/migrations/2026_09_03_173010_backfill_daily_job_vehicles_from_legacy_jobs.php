<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data migration: every existing 'direct' daily_jobs row gets exactly
     * one daily_job_vehicles row copying its (formerly flat) vehicle/trip/
     * charge data across, so old and new jobs render through the same
     * single code path from here on (DailyJob::vehicles, job_total as a
     * sum of line totals, etc.) instead of branching on "does this job
     * predate the rewrite".
     *
     * Deliberately NOT migrated:
     *   - Old daily_job_extra_port_charges (from/to port pairs) — the new
     *     schema only has a single port per extra charge row (item 2), and
     *     collapsing a from/to pair into one port would misrepresent the
     *     historical record. Those rows are left in their original table,
     *     untouched, and still readable read-only via
     *     DailyJob::legacyExtraPortCharges() for jobs whose backfilled line
     *     is flagged is_legacy — the backfilled row's extra_port_charges_total
     *     still carries the correct total, it just isn't broken into
     *     per-port rows in the new UI for these older jobs.
     *   - The old dc_* columns on daily_jobs — copied into a new standalone
     *     delivery_challans row (linked back to the backfilled vehicle line)
     *     rather than dropped, so DC history and printing keep working.
     *
     * Idempotent: skips any job that already has a daily_job_vehicles row,
     * so a retried migration (e.g. after a partial failure on production)
     * never double-inserts.
     */
    public function up(): void
    {
        if (!Schema::hasTable('daily_job_vehicles') || !Schema::hasTable('daily_jobs')) {
            return;
        }

        $now = now();

        DB::table('daily_jobs')
            ->where('job_type', 'direct')
            ->whereNotNull('vehicle_id')
            ->orderBy('id')
            ->chunkById(200, function ($jobs) use ($now) {
                foreach ($jobs as $job) {
                    try {
                        $this->backfillOne($job, $now);
                    } catch (\Throwable $e) {
                        // Never let one bad historical row block the whole
                        // deploy — log it and keep going; it can be fixed
                        // up by hand afterwards.
                        Log::error('[Backfill daily_job_vehicles] Failed for job', [
                            'daily_job_id' => $job->id,
                            'message'      => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function backfillOne($job, $now): void
    {
        $alreadyDone = DB::table('daily_job_vehicles')->where('daily_job_id', $job->id)->exists();
        if ($alreadyDone) {
            return;
        }

        $retentionTotal = round(
            (float) $job->per_day_first_charges
            + ((float) $job->per_day_next_rate * (int) $job->per_day_extra_days),
            2
        );

        $lineTotal = round(
            (float) $job->rent + (float) $job->labour_charges + (float) $job->yard_charges
            + (float) $job->kanta_charges + $retentionTotal + (float) $job->extra_port_charges_total,
            2
        );

        $deliveryChallanId = null;
        if (!empty($job->dc_no)) {
            $deliveryChallanId = DB::table('delivery_challans')->where('dc_no', $job->dc_no)->value('id');

            if (!$deliveryChallanId) {
                $deliveryChallanId = DB::table('delivery_challans')->insertGetId([
                    'dc_no'             => $job->dc_no,
                    'dc_date'           => $job->dc_date ?? $job->date,
                    'customer_id'       => $job->customer_id,
                    'port_id'           => $job->pickup_port_id,
                    'clearing_agent'    => $job->dc_clearing_agent,
                    'unit'              => $job->dc_unit,
                    'bl_no'             => $job->dc_bl_no,
                    'container_no'      => $job->dc_container_no ?? $job->container_no,
                    'quantity'          => $job->dc_quantity,
                    'item_description'  => $job->dc_item_description ?? $job->item_description,
                    'truck_no'          => $job->dc_truck_no,
                    'remarks'           => 'Backfilled from legacy Daily Job ' . $job->job_no,
                    'created_by'        => $job->created_by,
                    'updated_by'        => $job->updated_by,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            }
        }

        DB::table('daily_job_vehicles')->insert([
            'daily_job_id'              => $job->id,
            'vehicle_id'                => $job->vehicle_id,
            'route_id'                  => $job->route_id,
            'container_no'              => $job->container_no,
            'item_description'          => $job->item_description,
            'trip_type'                 => $job->trip_type,
            'pickup_port_id'            => $job->pickup_port_id,
            'destination_location_id'   => $job->destination_location_id,
            'dropoff_port_id'           => $job->dropoff_port_id,
            'rent'                      => $job->rent,
            'labour_charges'            => $job->labour_charges,
            'yard_charges'              => $job->yard_charges,
            'kanta_charges'             => $job->kanta_charges,
            'retention_first_day_charges' => $job->per_day_first_charges,
            'retention_next_day_rate'   => $job->per_day_next_rate,
            'retention_extra_days'      => $job->per_day_extra_days,
            'retention_night_rate'      => 0, // not tracked pre-rewrite
            'retention_total'           => $retentionTotal,
            'extra_port_charges_total'  => $job->extra_port_charges_total,
            'line_total'                => $lineTotal,
            'delivery_challan_id'       => $deliveryChallanId,
            'is_legacy'                 => true,
            'created_by'                => $job->created_by,
            'updated_by'                => $job->updated_by,
            'created_at'                => $now,
            'updated_at'                => $now,
        ]);
    }

    public function down(): void
    {
        // Data-only migration — intentionally not reversed (dropping the
        // daily_job_vehicles/delivery_challans tables in their own down()
        // methods already removes this data).
    }
};