<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Job-level (shared across every vehicle on the job) Extra Port Charges —
// see the 2026_09_04 migration for why this is a separate table from the
// two other extra-port-charges tables in this app.
class DailyJobSharedExtraPortCharge extends Model
{
    protected $table = 'daily_job_shared_extra_port_charges';

    protected $fillable = [
        'daily_job_id',
        'port_id',
        'charges',
    ];

    public function dailyJob()
    {
        return $this->belongsTo(DailyJob::class);
    }

    public function port()
    {
        return $this->belongsTo(Port::class);
    }
}