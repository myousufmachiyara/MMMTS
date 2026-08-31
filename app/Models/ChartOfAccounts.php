<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChartOfAccounts extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'shoa_id',
        'name',
        'account_code',
        'account_type',
        'trn', // NTN# / Tax Registration Number for Parties (customers/vendors)
        'receivables',
        'payables',
        'credit_limit',
        'opening_date',
        'remarks',
        'address',
        'contact_no',
        'created_by',
        'updated_by',
    ];

    // Define the relationship with SubHeadOfAccounts (belongs to)
    public function subHeadOfAccount()
    {
        return $this->belongsTo(SubHeadOfAccounts::class, 'shoa_id', 'id');
    }

    public function purchaseInvoices()
    {
        return $this->hasMany(PurchaseInvoice::class, 'vendor_id');
    }

    // Locations belonging to this account (only meaningful when account_type = 'customer')
    public function locations()
    {
        return $this->hasMany(CustomerLocation::class, 'customer_id');
    }

    // ── Parties scopes ──────────────────────────────────────────────
    // Parties (customers/vendors) are managed from Chart of Accounts and
    // differentiated purely by account_type — no separate parties table.
    public function scopeCustomers($query)
    {
        return $query->where('account_type', 'customer');
    }

    public function scopeVendors($query)
    {
        return $query->where('account_type', 'vendor');
    }

}
