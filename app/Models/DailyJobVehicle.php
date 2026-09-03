<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DailyJobVehicle extends Model
{
    use SoftDeletes;

    protected $table = 'daily_job_vehicles';

    protected $fillable = [
        'daily_job_id',
        'vehicle_id',
        'route_id',
        'container_no',
        'item_description',
        'trip_type',
        'pickup_port_id',
        'destination_location_id',
        'dropoff_port_id',
        'rent',
        'labour_charges',
        'yard_charges',
        'kanta_charges',
        'retention_first_day_charges',
        'retention_next_day_rate',
        'retention_extra_days',
        'retention_night_rate',
        'retention_total',
        'extra_port_charges_total',
        'line_total',
        'delivery_challan_id',
        'is_legacy',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_legacy' => 'boolean',
    ];

    public function dailyJob()
    {
        return $this->belongsTo(DailyJob::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
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
        return $this->hasMany(DailyJobVehicleExtraPortCharge::class);
    }

    public function deliveryChallan()
    {
        return $this->belongsTo(DeliveryChallan::class);
    }

    // Read-only view of a legacy (pre-rewrite) job's old-style from/to port
    // extra charges — never editable here, kept only so historical jobs
    // still show their original breakdown. New rows use extraPortCharges().
    public function legacyExtraPortCharges()
    {
        if (!$this->is_legacy) {
            return collect();
        }

        return DailyJobExtraPortCharge::with(['fromPort', 'toPort'])
            ->where('daily_job_id', $this->daily_job_id)
            ->get();
    }

    // Retention (per-day) total = first day + (next-day rate * extra days)
    //                             + (night rate * extra days — item 2's
    //                             "night charges apply once day > 1" rule
    //                             rides on extra_days already being >= 1).
    public static function computeRetentionTotal($first, $nextRate, $extraDays, $nightRate): float
    {
        $extraDays = (int) $extraDays;
        return round(
            (float) $first + ((float) $nextRate * $extraDays) + ((float) $nightRate * $extraDays),
            2
        );
    }
}