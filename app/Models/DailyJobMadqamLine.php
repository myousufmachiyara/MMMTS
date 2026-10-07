<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One vehicle on a Madqam job: our own vehicle, hired out at rate_per_day for
// `days` days. `amount` is stored as rate_per_day × days.
class DailyJobMadqamLine extends Model
{
    protected $fillable = [
        'daily_job_id',
        'vehicle_id',
        'rate_per_day',
        'days',
        'amount',
    ];

    protected $casts = [
        'rate_per_day' => 'float',
        'days'         => 'float',
        'amount'       => 'float',
    ];

    public function dailyJob()
    {
        return $this->belongsTo(DailyJob::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    // "3" for 3.00 days, "2.5" for 2.50 — no needless trailing zeros.
    public function getDaysLabelAttribute(): string
    {
        return rtrim(rtrim(number_format((float) $this->days, 2, '.', ''), '0'), '.');
    }
}