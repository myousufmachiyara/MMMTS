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
    // jobs created before item 3's rewrite. New jobs use
    // DailyJobVehicle::extraPortCharges() per vehicle-row instead.
    public function extraPortCharges()
    {
        return $this->hasMany(DailyJobExtraPortCharge::class);
    }

    // One row per vehicle on this job (item 3 — a Direct job can involve
    // multiple vehicles, each with its own trip plan / charges / DC).
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
    // the job's grand total instead (item 13) — so for Direct jobs this is
    // simply the sum of every vehicle-row's line_total (rent + labour +
    // yard + kanta + retention + extra port charges). Party-to-Party jobs
    // have no trip-plan/vehicle-row concept, so the amount billed to the
    // customer (pty_sale_amount — NOT pty_cost, which is what we owe the
    // vendor) is carried entirely as "other charges" here.
    public function getOtherChargesTotalAttribute()
    {
        if ($this->job_type === 'party_to_party') {
            return round((float) $this->pty_sale_amount, 2);
        }

        return round($this->vehicles->sum('line_total'), 2);
    }

    // Retention Charges (formerly "Per Day Charges", item 9) summed across
    // every vehicle-row — used for the Bill's separate Retention Charges
    // column (item 10). Not meaningful for Party-to-Party jobs.
    public function getRetentionChargesTotalAttribute()
    {
        if ($this->job_type === 'party_to_party') {
            return 0;
        }

        return round($this->vehicles->sum('retention_total'), 2);
    }
}