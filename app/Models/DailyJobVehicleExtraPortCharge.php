<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyJobVehicleExtraPortCharge extends Model
{
    protected $table = 'daily_job_vehicle_extra_port_charges';

    protected $fillable = [
        'daily_job_vehicle_id',
        'port_id',
        'charges',
    ];

    public function vehicleLine()
    {
        return $this->belongsTo(DailyJobVehicle::class, 'daily_job_vehicle_id');
    }

    public function port()
    {
        return $this->belongsTo(Port::class, 'port_id', 'id');
    }
}