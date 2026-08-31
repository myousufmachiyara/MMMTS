<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerLocation extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'customer_locations';

    protected $fillable = [
        'customer_id',
        'location_name',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // The linked party — always a Chart of Accounts row with account_type = 'customer'
    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'customer_id', 'id');
    }
}
