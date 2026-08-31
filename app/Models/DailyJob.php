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

    public function extraPortCharges()
    {
        return $this->hasMany(DailyJobExtraPortCharge::class);
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function getHasDcAttribute(): bool
    {
        return !empty($this->dc_no);
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

    // "Other charges" = everything except the Trip Plan portion — used when a Bill
    // adds Trip Plan + Other Charges together but taxes only the Trip Plan slice.
    // Party-to-Party jobs have no trip-plan/tax portion of their own, so the amount
    // billed to the customer (pty_sale_amount — NOT pty_cost, which is what we owe
    // the vendor) is carried entirely as "other charges" here.
    public function getOtherChargesTotalAttribute()
    {
        if ($this->job_type === 'party_to_party') {
            return round((float) $this->pty_sale_amount, 2);
        }

        return round(
            $this->rent + $this->labour_charges + $this->yard_charges + $this->kanta_charges
            + $this->extra_port_charges_total + $this->per_day_total,
            2
        );
    }
}