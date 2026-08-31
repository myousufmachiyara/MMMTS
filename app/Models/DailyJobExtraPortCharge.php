<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyJobExtraPortCharge extends Model
{
    protected $table = 'daily_job_extra_port_charges';

    protected $fillable = [
        'daily_job_id',
        'from_port_id',
        'to_port_id',
        'charges',
    ];

    public function dailyJob()
    {
        return $this->belongsTo(DailyJob::class);
    }

    public function fromPort()
    {
        return $this->belongsTo(Port::class, 'from_port_id', 'id');
    }

    public function toPort()
    {
        return $this->belongsTo(Port::class, 'to_port_id', 'id');
    }
}
