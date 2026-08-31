<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'vehicle_no',
        'remarks',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Companies this vehicle is linked to (many-to-many — a vehicle can belong to multiple companies)
    public function companies()
    {
        return $this->belongsToMany(OurCompany::class, 'company_vehicle', 'vehicle_id', 'company_id');
    }
}
