<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OurCompany extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'our_companies';

    protected $fillable = [
        'code',
        'name',
        'ntn',
        'logo',
        'address',
        'contact_no',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['logo_url'];

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->logo) : null;
    }

    // Vehicles linked to this company (many-to-many — a vehicle can belong to multiple companies)
    public function vehicles()
    {
        return $this->belongsToMany(Vehicle::class, 'company_vehicle', 'company_id', 'vehicle_id');
    }
}
