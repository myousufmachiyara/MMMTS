<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryChallan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        // Item 1 — set the moment this DC is created (see
        // DeliveryChallanController::store()), pointing at the pending
        // Direct job auto-created alongside it. See the
        // 2026_09_11_000001 migration's docblock for how this differs from
        // DailyJobVehicle::delivery_challan_id.
        'daily_job_id',
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

    // Item 4 — who created this DC, shown on the DC print.
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function port()
    {
        return $this->belongsTo(Port::class, 'port_id', 'id');
    }

    // The single vehicle-row (if any) this DC has been ASSIGNED to — i.e.
    // someone has actually picked a vehicle for it. Stays null while the
    // job created alongside this DC (see dailyJob() below) is still
    // pending (item 1).
    public function vehicleLine()
    {
        return $this->hasOne(DailyJobVehicle::class, 'delivery_challan_id');
    }

    public function getIsLinkedAttribute(): bool
    {
        return $this->vehicleLine()->exists();
    }

    // The job's HEADER this DC belongs to (item 1) — set the moment the DC
    // is created, well before any vehicle-row/vehicleLine exists. See the
    // 2026_09_11_000001 migration's docblock.
    public function dailyJob()
    {
        return $this->belongsTo(DailyJob::class);
    }
}