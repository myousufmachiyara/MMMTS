<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class InventoryReportController extends Controller
{
    public function inventoryReports(Request $request)
    {
        $tab     = $request->get('tab', 'IL');
        $itemId  = $request->get('item_id');
        $from    = $request->get('from_date', date('Y-m-01'));
        $to      = $request->get('to_date', date('Y-m-d'));

        $products    = Product::with('variations')->orderBy('name')->get();
        $itemLedger  = collect();
        $openingQty  = 0;
        $stockInHand = collect();

        // ================================================================
        // TAB 1 — ITEM LEDGER
        // ================================================================
        if ($tab === 'IL' && $itemId) {

            $opPurchased = DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->where('purchase_invoice_items.item_id', $itemId)
                ->whereNull('purchase_invoices.deleted_at')
                ->where('purchase_invoices.invoice_date', '<', $from)
                ->sum('purchase_invoice_items.quantity');

            $opSold = DB::table('sale_invoice_items')
                ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                ->where('sale_invoice_items.product_id', $itemId)
                ->whereNull('sale_invoices.deleted_at')
                ->where('sale_invoices.date', '<', $from)
                ->sum('sale_invoice_items.quantity');

            $opPurchaseReturned = DB::table('purchase_return_items')
                ->join('purchase_returns', 'purchase_return_items.purchase_return_id', '=', 'purchase_returns.id')
                ->where('purchase_return_items.item_id', $itemId)
                ->where('purchase_returns.return_date', '<', $from)
                ->sum('purchase_return_items.quantity');

            $opSaleReturned = DB::table('sale_return_items')
                ->join('sale_returns', 'sale_return_items.sale_return_id', '=', 'sale_returns.id')
                ->where('sale_return_items.product_id', $itemId)
                ->where('sale_returns.return_date', '<', $from)
                ->sum('sale_return_items.qty');

            $openingQty = ((float)$opPurchased + (float)$opSaleReturned)
                        - ((float)$opSold      + (float)$opPurchaseReturned);

            $purchases = DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->select(
                    'purchase_invoices.invoice_date as date',
                    DB::raw("'Purchase' as type"),
                    DB::raw("CONCAT('PI-', purchase_invoices.invoice_no) as description"),
                    'purchase_invoice_items.quantity as qty_in',
                    DB::raw('0 as qty_out')
                )
                ->where('purchase_invoice_items.item_id', $itemId)
                ->whereNull('purchase_invoices.deleted_at')
                ->whereBetween('purchase_invoices.invoice_date', [$from, $to]);

            $sales = DB::table('sale_invoice_items')
                ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                ->select(
                    'sale_invoices.date as date',
                    DB::raw("'Sale' as type"),
                    DB::raw("CONCAT('SI-', sale_invoices.invoice_no) as description"),
                    DB::raw('0 as qty_in'),
                    'sale_invoice_items.quantity as qty_out'
                )
                ->where('sale_invoice_items.product_id', $itemId)
                ->whereNull('sale_invoices.deleted_at')
                ->whereBetween('sale_invoices.date', [$from, $to]);

            $purchaseReturns = DB::table('purchase_return_items')
                ->join('purchase_returns', 'purchase_return_items.purchase_return_id', '=', 'purchase_returns.id')
                ->select(
                    'purchase_returns.return_date as date',
                    DB::raw("'Purchase Return' as type"),
                    DB::raw("CONCAT('PR-', purchase_returns.id) as description"),
                    DB::raw('0 as qty_in'),
                    'purchase_return_items.quantity as qty_out'
                )
                ->where('purchase_return_items.item_id', $itemId)
                ->whereBetween('purchase_returns.return_date', [$from, $to]);

            $saleReturns = DB::table('sale_return_items')
                ->join('sale_returns', 'sale_return_items.sale_return_id', '=', 'sale_returns.id')
                ->select(
                    'sale_returns.return_date as date',
                    DB::raw("'Sale Return' as type"),
                    DB::raw("CONCAT('SR-', sale_returns.id) as description"),
                    'sale_return_items.qty as qty_in',
                    DB::raw('0 as qty_out')
                )
                ->where('sale_return_items.product_id', $itemId)
                ->whereBetween('sale_returns.return_date', [$from, $to]);

            $itemLedger = $purchases
                ->union($sales)
                ->union($purchaseReturns)
                ->union($saleReturns)
                ->orderBy('date', 'asc')
                ->get()
                ->map(fn($row) => (array) $row);
        }

        // ================================================================
        // TAB 2 — STOCK IN HAND
        //
        // ROOT CAUSE OF BUG:
        //   The previous query did INNER JOIN on product_variations.
        //   A product purchased with variation_id = null has NO rows in
        //   product_variations, so the inner join returned 0 rows → empty.
        //
        // FIX:
        //   Query at the product level, not the variation level.
        //   - Products with NO variations: one row, stock = all purchases.
        //   - Products WITH variations: one row per variation, PLUS a
        //     catch-all "No Variation" row for any purchases/sales where
        //     variation_id was left null.
        // ================================================================
        if ($tab === 'SR') {

            $productQuery = Product::with('variations')
                ->leftJoin('measurement_units', 'measurement_units.id', '=', 'products.measurement_unit')
                ->select('products.*', 'measurement_units.shortcode as unit_shortcode')
                ->orderBy('products.name');

            if ($itemId) {
                $productQuery->where('products.id', $itemId);
            }

            $productRows = $productQuery->get();

            foreach ($productRows as $product) {

                $hasVariations = $product->variations->isNotEmpty();

                if (!$hasVariations) {

                    // ── No variations: sum ALL rows for this product ───
                    $purchased = (float) DB::table('purchase_invoice_items')
                        ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                        ->where('purchase_invoice_items.item_id', $product->id)
                        ->whereNull('purchase_invoices.deleted_at')
                        ->sum('purchase_invoice_items.quantity');

                    $sold = (float) DB::table('sale_invoice_items')
                        ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                        ->where('sale_invoice_items.product_id', $product->id)
                        ->whereNull('sale_invoices.deleted_at')
                        ->sum('sale_invoice_items.quantity');

                    $purchaseReturned = (float) DB::table('purchase_return_items')
                        ->where('item_id', $product->id)
                        ->sum('quantity');

                    $saleReturned = (float) DB::table('sale_return_items')
                        ->where('product_id', $product->id)
                        ->sum('qty');

                    $qty = ($purchased + $saleReturned) - ($sold + $purchaseReturned);

                    if ($qty > 0) {
                        $stockInHand->push([
                            'product'   => $product->name,
                            'variation' => '—',
                            'quantity'  => $qty,
                            'unit'      => $product->unit_shortcode ?? '',
                        ]);
                    }

                } else {

                    // ── Has variations ─────────────────────────────────

                    // Catch-all row: purchases/sales where variation_id is null
                    $nullQtyIn = (float) DB::table('purchase_invoice_items')
                        ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                        ->where('purchase_invoice_items.item_id', $product->id)
                        ->whereNull('purchase_invoice_items.variation_id')
                        ->whereNull('purchase_invoices.deleted_at')
                        ->sum('purchase_invoice_items.quantity');

                    $nullQtyOut = (float) DB::table('sale_invoice_items')
                        ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                        ->where('sale_invoice_items.product_id', $product->id)
                        ->whereNull('sale_invoice_items.variation_id')
                        ->whereNull('sale_invoices.deleted_at')
                        ->sum('sale_invoice_items.quantity');

                    $nullPR = (float) DB::table('purchase_return_items')
                        ->where('item_id', $product->id)->whereNull('variation_id')->sum('quantity');

                    $nullSR = (float) DB::table('sale_return_items')
                        ->where('product_id', $product->id)->whereNull('variation_id')->sum('qty');

                    $nullQty = ($nullQtyIn + $nullSR) - ($nullQtyOut + $nullPR);

                    if ($nullQty > 0) {
                        $stockInHand->push([
                            'product'   => $product->name,
                            'variation' => 'No Variation',
                            'quantity'  => $nullQty,
                            'unit'      => $product->unit_shortcode ?? '',
                        ]);
                    }

                    // One row per variation
                    foreach ($product->variations as $v) {

                        $vPurchased = (float) DB::table('purchase_invoice_items')
                            ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                            ->where('purchase_invoice_items.item_id', $product->id)
                            ->where('purchase_invoice_items.variation_id', $v->id)
                            ->whereNull('purchase_invoices.deleted_at')
                            ->sum('purchase_invoice_items.quantity');

                        $vSold = (float) DB::table('sale_invoice_items')
                            ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                            ->where('sale_invoice_items.product_id', $product->id)
                            ->where('sale_invoice_items.variation_id', $v->id)
                            ->whereNull('sale_invoices.deleted_at')
                            ->sum('sale_invoice_items.quantity');

                        $vPR = (float) DB::table('purchase_return_items')
                            ->where('item_id', $product->id)->where('variation_id', $v->id)->sum('quantity');

                        $vSR = (float) DB::table('sale_return_items')
                            ->where('product_id', $product->id)->where('variation_id', $v->id)->sum('qty');

                        $vQty = ($vPurchased + $vSR) - ($vSold + $vPR);

                        if ($vQty > 0) {
                            $stockInHand->push([
                                'product'   => $product->name,
                                'variation' => $v->sku ?? $v->name ?? '—',
                                'quantity'  => $vQty,
                                'unit'      => $product->unit_shortcode ?? '',
                            ]);
                        }
                    }
                }
            }
        }

        return view('reports.inventory_reports', compact(
            'products',
            'itemLedger',
            'openingQty',
            'stockInHand',
            'tab',
            'from',
            'to'
        ));
    }
}