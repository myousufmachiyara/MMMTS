<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleRoute extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'dimension',
        'union_rent',
        'day_detention',
        'night_detention',
        'labour_charges',
        'maripur_charges',
        'h_bay_charges',
        'extra_northern',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'union_rent'      => 'decimal:2',
        'day_detention'   => 'decimal:2',
        'night_detention' => 'decimal:2',
        'labour_charges'  => 'decimal:2',
        'maripur_charges' => 'decimal:2',
        'h_bay_charges'   => 'decimal:2',
        'extra_northern'  => 'decimal:2',
    ];
}
