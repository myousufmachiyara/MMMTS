<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bill extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'bill_no',
        'customer_id',
        'from_date',
        'to_date',
        'bill_date',
        'trip_plan_subtotal',
        'other_charges_subtotal',
        'total_amount',
        'voucher_id',
        'invoice_id',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date'   => 'date',
        'bill_date' => 'date',
    ];

    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'customer_id', 'id');
    }

    public function jobs()
    {
        return $this->hasMany(DailyJob::class);
    }

    // Total containers/entries on this bill (1 job row = 1 container per the legacy sheet convention)
    public function getContainerCountAttribute(): int
    {
        return $this->jobs()->count();
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
