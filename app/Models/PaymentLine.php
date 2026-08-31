<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentLine extends Model
{
    protected $fillable = [
        'payment_id',
        'method',
        'account_id',
        'amount',
        'cheque_no',
        'cheque_date',
        'reference',
        'voucher_id',
    ];

    protected $casts = [
        'cheque_date' => 'date',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function account()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'account_id', 'id');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }
}
