<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One vehicle on a Muqadum job (job_type 'madqam'): one of OUR vehicles and its
// rent for the job. (An earlier version had rate-per-day × days; it is now just
// a rent, stored in `amount`.)
class DailyJobMadqamLine extends Model
{
    protected $fillable = [
        'daily_job_id',
        'vehicle_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function dailyJob()
    {
        return $this->belongsTo(DailyJob::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}