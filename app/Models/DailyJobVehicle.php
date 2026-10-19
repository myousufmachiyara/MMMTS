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
        // Detention is entered per vehicle: day rate x number of days + night
        // rate x number of nights (the first day is the Rent). See
        // detention_amount below and DailyJob::lineDetention().
        'detention_day_charge',
        'detention_days',
        'detention_night_charge',
        'detention_nights',
        'labour_charges',
        'yard_charges',
        'kanta_charges',
        'detention_first_day_charges',
        'detention_next_day_rate',
        'detention_extra_days',
        'detention_night_rate',
        'detention_total',
        'extra_port_charges_total',
        'line_total',
        'delivery_challan_id',
        'is_legacy',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_legacy' => 'boolean',
        'detention_days' => 'integer',
        'detention_nights' => 'integer',
    ];

    // This vehicle's detention:
    //   (day rate x number of days) + (night rate x number of nights)
    public function getDetentionAmountAttribute(): float
    {
        return round(
            (float) $this->detention_day_charge * (int) $this->detention_days
            + (float) $this->detention_night_charge * (int) $this->detention_nights,
            2
        );
    }

    // "2 days + 1 night" — what the prints show next to a vehicle's detention.
    // Empty when the vehicle has no detention days or nights.
    public function getDetentionLabelAttribute(): string
    {
        $parts = [];
        $days = (int) $this->detention_days;
        $nights = (int) $this->detention_nights;

        if ($days > 0) {
            $parts[] = $days . ($days === 1 ? ' day' : ' days');
        }
        if ($nights > 0) {
            $parts[] = $nights . ($nights === 1 ? ' night' : ' nights');
        }

        return implode(' + ', $parts);
    }

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

    // Detention (per-day) total = first day + (next-day rate * extra days)
    //                              + night charges.
    //
    // Item 7 fix: night charges only apply once extra_days is GREATER THAN
    // 1 (i.e. 2 or more) — at exactly 1 extra day there is no "night" yet,
    // so night_rate must contribute 0. The previous formula multiplied
    // night_rate by extra_days unconditionally, so it incorrectly charged a
    // night rate even at extra_days = 1.
    public static function computeDetentionTotal($first, $nextRate, $extraDays, $nightRate): float
    {
        $extraDays = (int) $extraDays;
        $nightCharges = $extraDays > 1 ? ((float) $nightRate * $extraDays) : 0.0;

        return round(
            (float) $first + ((float) $nextRate * $extraDays) + $nightCharges,
            2
        );
    }
}