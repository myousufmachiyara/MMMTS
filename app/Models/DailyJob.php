<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DailyJob extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'job_no',
        'job_type',
        'date',
        'vehicle_id',
        'customer_id',
        'route_id',
        'container_no',
        'item_description',
        'rent',
        'labour_charges',
        'yard_charges',
        'kanta_charges',
        'trip_type',
        'pickup_port_id',
        'pickup_charges',
        'destination_location_id',
        'destination_charges',
        'dropoff_port_id',
        'dropoff_charges',
        'trip_plan_total',
        'per_day_first_charges',
        'per_day_next_rate',
        'per_day_extra_days',
        'per_day_total',
        'extra_port_charges_total',
        // Retention Charges (item 9's naming) — shared across every vehicle
        // on the job (see the 2026_09_04 migration). Not to be confused
        // with per_day_* above, which is the OLD single-vehicle-era naming
        // left untouched for historical jobs.
        'retention_first_day_charges',
        'retention_next_day_rate',
        'retention_extra_days',
        'retention_night_rate',
        'retention_total',
        'job_total',
        'bill_id',
        'remarks',
        'created_by',
        'updated_by',
        // Assistant/Admin split (item 11) — 'incomplete' jobs were created
        // by an assistant with only the basic fields; an admin
        // (daily_jobs.fill_rates) later fills in the rest and flips this.
        'status',
        // ── Party-to-Party (Vendor to Customer directly) ──
        'vendor_id',
        'pty_vehicle_no',
        'pty_destination',
        'pty_size',
        'pty_cost',        // what we owe the vendor
        'pty_sale_amount', // what we bill the customer — feeds job_total
        'pty_advance',
        'pty_guarantee',
        'pty_balance',
        // ── Delivery Challan (Direct jobs only — proof our vehicle delivered/
        // received the container). Snapshot fields living on the job itself
        // rather than a separate module, since a DC carries no accounting
        // weight of its own. dc_no being set marks the job as "DC issued".
        'dc_no',
        'dc_date',
        'dc_clearing_agent',
        'dc_unit',
        'dc_bl_no',
        'dc_container_no',
        'dc_quantity',
        'dc_item_description',
        'dc_truck_no',
    ];

    protected $casts = [
        'date'    => 'date',
        'dc_date' => 'date',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'customer_id', 'id');
    }

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id', 'id');
    }

    public function route()
    {
        return $this->belongsTo(VehicleRoute::class, 'route_id', 'id');
    }

    public function pickupPort()
    {
        return $this->belongsTo(Port::class, 'pickup_port_id', 'id');
    }

    public function dropoffPort()
    {
        return $this->belongsTo(Port::class, 'dropoff_port_id', 'id');
    }

    public function destinationLocation()
    {
        return $this->belongsTo(CustomerLocation::class, 'destination_location_id', 'id');
    }

    // Legacy (pre-multi-vehicle) extra port charges — still readable for
    // jobs created before item 3's rewrite. Not used by current code paths;
    // see sharedExtraPortCharges() for the extra port charges a job created
    // today actually has.
    public function extraPortCharges()
    {
        return $this->hasMany(DailyJobExtraPortCharge::class);
    }

    // Extra Port Charges are shared across every vehicle on the job (one
    // list per job, not per vehicle-row) — see the 2026_09_04 migration.
    public function sharedExtraPortCharges()
    {
        return $this->hasMany(DailyJobSharedExtraPortCharge::class);
    }

    // One row per vehicle on this job (item 3 — a Direct job can involve
    // multiple vehicles). Since the multi-vehicle-form change, a vehicle
    // row carries only vehicle_id/container_no/delivery_challan_id — route,
    // trip plan, and every rate/charge field are shared across all of a
    // job's vehicles and live on the job header instead (see this model's
    // route_id/rent/retention_* etc. and sharedExtraPortCharges() above).
    // Every direct job — old or new — has at least one row: pre-rewrite
    // jobs were backfilled with exactly one (see the
    // 2026_09_03_000005 migration).
    public function vehicles()
    {
        return $this->hasMany(DailyJobVehicle::class)->orderBy('id');
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    // True if ANY vehicle-row on this job still has no Delivery Challan
    // linked, OR (legacy) the old per-job dc_no was never issued.
    public function getHasDcAttribute(): bool
    {
        if ($this->job_type !== 'direct') {
            return false;
        }

        if (!empty($this->dc_no)) {
            return true; // legacy single-DC-per-job jobs
        }

        return $this->relationLoaded('vehicles')
            ? $this->vehicles->contains(fn ($v) => $v->delivery_challan_id)
            : $this->vehicles()->whereNotNull('delivery_challan_id')->exists();
    }

    // Profit on a Party-to-Party job = what we bill the customer minus what we
    // owe the vendor. Not meaningful for Direct jobs (returns null there).
    public function getPtyProfitAttribute(): ?float
    {
        if ($this->job_type !== 'party_to_party') {
            return null;
        }

        return round((float) $this->pty_sale_amount - (float) $this->pty_cost, 2);
    }

    // "Other charges" = everything except the Trip Plan portion. Trip Plan
    // no longer carries any charges of its own (item 2) — tax is applied to
    // the job's grand total instead (item 13). Rent/labour/yard/kanta/
    // retention/extra-port-charges are shared once across the whole job
    // (not per vehicle any more — see this model's vehicles() docblock), so
    // for Direct jobs this is simply those job-header fields added up, once,
    // regardless of how many vehicles are on the job. Party-to-Party jobs
    // have no trip-plan/vehicle-row concept, so the amount billed to the
    // customer (pty_sale_amount — NOT pty_cost, which is what we owe the
    // vendor) is carried entirely as "other charges" here.
    public function getOtherChargesTotalAttribute()
    {
        if ($this->job_type === 'party_to_party') {
            return round((float) $this->pty_sale_amount, 2);
        }

        return round(
            (float) $this->rent + (float) $this->labour_charges + (float) $this->yard_charges
            + (float) $this->kanta_charges + (float) $this->retention_total + (float) $this->extra_port_charges_total,
            2
        );
    }

    // Retention Charges (formerly "Per Day Charges", item 9) — a single
    // shared amount per job (item 10's Bill column). Not meaningful for
    // Party-to-Party jobs.
    public function getRetentionChargesTotalAttribute()
    {
        if ($this->job_type === 'party_to_party') {
            return 0;
        }

        return round((float) $this->retention_total, 2);
    }
}