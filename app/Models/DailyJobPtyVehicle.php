<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One row per vehicle on a Party-to-Party job. These are the VENDOR's
// vehicles, so all three fields are free text (see the
// 2026_10_06_090000 migration's docblock) — not links to our own masters.
class DailyJobPtyVehicle extends Model
{
    protected $fillable = [
        'daily_job_id',
        'vehicle_no',
        'route',
        'size',
    ];

    public function dailyJob()
    {
        return $this->belongsTo(DailyJob::class);
    }
}