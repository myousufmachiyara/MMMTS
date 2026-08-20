<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Models\ChartOfAccounts;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseBiltyDetail;
use Carbon\Carbon;

class SalesReportController extends Controller
{
    public function saleReports(Request $request)
    {
        $tab = $request->get('tab', 'SR');

        $from = $request->get('from_date', Carbon::now()->startOfMonth()->toDateString());
        $to   = $request->get('to_date', Carbon::now()->toDateString());

        $customerId = $request->get('customer_id');

        $sales        = collect();
        $returns      = collect();
        $customerWise = collect();

        /* ================= SALES REGISTER ================= */
        if ($tab === 'SR') {
            $sales = SaleInvoice::with(['account', 'items'])
                ->whereBetween('date', [$from, $to])
                ->get()
                ->map(function ($sale) {
                    $total = $sale->items->sum(function ($item) {
                        return ($item->sale_price ?? $item->price) * $item->quantity;
                    });

                    return (object)[
                        'date'     => $sale->date,
                        'invoice'  => $sale->invoice_no ?? $sale->id,
                        'customer' => $sale->account->name ?? '',
                        'revenue'  => $total - ($sale->discount ?? 0), // Added this
                        'total'    => $total, // Kept for safety
                        'cost'     => 0,      // Placeholder to prevent Blade errors
                        'profit'   => 0,      // Placeholder
                        'margin'   => 0       // Placeholder
                    ];
                });
        }

        /* ================= SALES RETURN ================= */
        if ($tab === 'SRET') {
            $returns = SaleReturn::with(['customer', 'items'])
                ->whereBetween('return_date', [$from, $to])
                ->get()
                ->map(function ($ret) {

                    $total = $ret->items->sum(function ($item) {
                        return $item->qty * $item->price;
                    });

                    return (object)[
                        'date'     => $ret->return_date,
                        'invoice'  => $ret->invoice_no ?? $ret->id,
                        'customer' => $ret->account->name ?? '',
                        'total'    => $total,
                    ];
                });
        }

        /* ================= CUSTOMER WISE ================= */
        if ($tab === 'CW') {
            $query = SaleInvoice::with(['account', 'items'])
                ->whereBetween('date', [$from, $to]);

            if ($customerId) {
                $query->where('account_id', $customerId);
            }

            $customerWise = $query->get()
                ->groupBy('account_id')
                ->map(function ($sales) {
                    $customerName = $sales->first()->account->name ?? 'Unknown Customer';
                    
                    $totalAmount = $sales->sum(function ($sale) {
                        $lineTotal = $sale->items->sum(function ($item) {
                            return ($item->sale_price ?? $item->price ?? 0) * ($item->quantity ?? 0);
                        });
                        return $lineTotal - ($sale->discount ?? 0);
                    });

                    return (object)[
                        'customer' => $customerName,
                        'count'    => $sales->count(),
                        'total'    => $totalAmount,
                    ];
                })
                ->values();
        }

        $customers = ChartOfAccounts::where('account_type', 'customer')->get();

        return view('reports.sales_reports', compact(
            'tab',
            'from',
            'to',
            'sales',
            'returns',
            'customerWise',
            'customers',
            'customerId'
        ));
    }
}
