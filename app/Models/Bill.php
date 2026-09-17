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
        // Item 8 — which "Our Company" is billing this customer, picked at
        // creation. Drives the Bill print's letterhead. See the
        // 2026_09_12_000001 migration's docblock.
        'company_id',
        'from_date',
        'to_date',
        'bill_date',
        'trip_plan_subtotal',
        'other_charges_subtotal',
        'detention_charges_subtotal',
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

    // Item 4 — who created this bill, shown on the Bill print.
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Item 8 — which "Our Company" this bill was issued under; drives the
    // Bill print's letterhead (logo/address/contact).
    public function company()
    {
        return $this->belongsTo(OurCompany::class, 'company_id');
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