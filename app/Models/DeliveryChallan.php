<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryChallan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'dc_no',
        'dc_date',
        'customer_id',
        'port_id',
        'clearing_agent',
        'unit',
        'bl_no',
        'container_no',
        'quantity',
        'item_description',
        'truck_no',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'dc_date' => 'date',
    ];

    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'customer_id', 'id');
    }

    public function port()
    {
        return $this->belongsTo(Port::class, 'port_id', 'id');
    }

    // The single vehicle-row (if any) this DC has been linked to.
    public function vehicleLine()
    {
        return $this->hasOne(DailyJobVehicle::class, 'delivery_challan_id');
    }

    public function getIsLinkedAttribute(): bool
    {
        return $this->vehicleLine()->exists();
    }
}