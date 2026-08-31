<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_no',
        'customer_id',
        'invoice_date',
        'from_date',
        'to_date',
        'is_taxable',
        'tax_percent',
        'trip_plan_subtotal',
        'tax_amount',
        'total_containers',
        'total_amount',
        'paid_amount',
        'status',
        'voucher_id',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'from_date'    => 'date',
        'to_date'      => 'date',
        'is_taxable'   => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'customer_id', 'id');
    }

    public function bills()
    {
        return $this->hasMany(Bill::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function getBalanceAmountAttribute()
    {
        return round($this->total_amount - $this->paid_amount, 2);
    }

    // Recompute status from paid_amount vs total_amount — call after any payment change
    public function refreshStatus(): void
    {
        if ($this->paid_amount <= 0) {
            $this->status = 'pending';
        } elseif ($this->paid_amount >= $this->total_amount) {
            $this->status = 'cleared';
        } else {
            $this->status = 'partial';
        }
        $this->save();
    }
}
