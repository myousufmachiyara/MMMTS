<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\PurchaseBilty;
use App\Models\ChartOfAccounts;
use App\Models\Product;
use Carbon\Carbon;

class PurchaseReportController extends Controller
{
    public function purchaseReports(Request $request)
    {
        $tab  = $request->get('tab', 'PUR');
        $from = $request->get('from_date', Carbon::now()->startOfMonth()->toDateString());
        $to   = $request->get('to_date', Carbon::now()->toDateString());

        $vendors = ChartOfAccounts::where('account_type', 'vendor')->get();

        $purchaseRegister    = collect();
        $purchaseReturns     = collect();
        $vendorWisePurchase  = collect();
        $vendorWiseBilty     = collect(); // New

        /* ================= PURCHASE REGISTER ================= */
        if ($tab === 'PUR') {
            $query = PurchaseInvoice::with(['vendor','items.product'])
                ->whereBetween('invoice_date', [$from, $to]);

            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            $purchaseRegister = $query->get()->flatMap(function ($invoice) {
                return $invoice->items->map(function ($item) use ($invoice) {
                    return (object)[
                        'invoice_id'  => $invoice->id, // ✅ REQUIRED
                        'date'        => $invoice->invoice_date,
                        'invoice_no'  => $invoice->bill_no ?? $invoice->invoice_no,
                        'vendor_name' => $invoice->vendor->name ?? '',
                        'item_name'   => $item->product->name ?? 'N/A',
                        'quantity'    => $item->quantity,
                        'rate'        => $item->price,
                        'total'       => $item->quantity * $item->price,
                    ];
                });
            });
        }

        /* ================= PURCHASE RETURNS ================= */
        if ($tab === 'PR') {
            $query = PurchaseReturn::with(['vendor','items.item'])
                ->whereBetween('return_date', [$from, $to]);

            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            $purchaseReturns = $query->get()->flatMap(function ($return) {
                return $return->items->map(function ($item) use ($return) {
                    return (object)[
                        'return_id'   => $return->id, // ✅ REQUIRED    
                        'date'        => $return->return_date,
                        'return_no'   => $return->invoice_no,
                        'vendor_name' => $return->vendor->name ?? '',
                        'item_name'   => $item->item->name ?? 'N/A',
                        'quantity'    => $item->quantity,
                        'rate'        => $item->price,
                        'total'       => $item->quantity * $item->price,
                    ];
                });
            });
        }

        /* ================= VENDOR-WISE PURCHASE ================= */
        /* ================= VENDOR-WISE PURCHASE ================= */
        if ($tab === 'VWP') {
            $query = PurchaseInvoice::with(['vendor', 'items.product', 'items.variation']) // Add variation eager load if it exists
            ->whereBetween('invoice_date', [$from, $to]);

            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            $vendorWisePurchase = $query->get()
            ->groupBy('vendor_id')
            ->map(function ($purchases) {
                $vendorName = $purchases->first()->vendor->name ?? 'Unknown Vendor';
                $items = collect();

                foreach ($purchases as $invoice) {
                    foreach ($invoice->items as $item) {
                        $items->push((object)[
                            'invoice_date' => $invoice->invoice_date,
                            'invoice_no'   => $invoice->bill_no ?? $invoice->invoice_no,
                            'item_name'    => $item->product->name ?? 'N/A',
                            // --- FIX START ---
                            'variation'    => $item->variation->sku ?? '-', // Adjust 'name' to your variation column
                            // --- FIX END ---
                            'quantity'     => $item->quantity,
                            'rate'         => $item->price,
                            'total'        => $item->quantity * $item->price,
                        ]);
                    }
                }

                return (object)[
                    'vendor_name'  => $vendorName,
                    'items'        => $items,
                    'total_qty'    => $items->sum('quantity'),
                    'total_amount' => $items->sum('total'),
                ];
            })
            ->values();
        }

        return view('reports.purchase_reports', compact(
            'tab',
            'from',
            'to',
            'vendors',
            'purchaseRegister',
            'purchaseReturns',
            'vendorWisePurchase',
            'vendorWiseBilty'
        ));
    }
}
