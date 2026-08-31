<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\ChartOfAccounts;
use App\Models\Invoice;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::with('customer');

        if ($request->filled('customer_id') && $request->customer_id !== 'all') {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $invoices = $query->latest('invoice_date')->latest('id')->get();
        $customers = ChartOfAccounts::customers()->orderBy('name')->get();

        return view('invoices.index', compact('invoices', 'customers'));
    }

    public function create()
    {
        $customers = ChartOfAccounts::customers()->orderBy('name')->get();
        return view('invoices.create', compact('customers'));
    }

    // AJAX: non-invoiced bills for a customer within a bill-date range
    public function getBills(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:chart_of_accounts,id',
            'from_date'   => 'required|date',
            'to_date'     => 'required|date|after_or_equal:from_date',
        ]);

        $bills = Bill::where('customer_id', $request->customer_id)
            ->whereNull('invoice_id')
            ->whereBetween('bill_date', [$request->from_date, $request->to_date])
            ->withCount('jobs')
            ->orderBy('bill_date')
            ->get(['id', 'bill_no', 'bill_date', 'trip_plan_subtotal', 'other_charges_subtotal', 'total_amount'])
            ->map(function ($bill) {
                return [
                    'id'                  => $bill->id,
                    'bill_no'             => $bill->bill_no,
                    'bill_date'           => $bill->bill_date->format('Y-m-d'),
                    'trip_plan_subtotal'  => (float) $bill->trip_plan_subtotal,
                    'total_amount'        => (float) $bill->total_amount,
                    'jobs_count'          => $bill->jobs_count,
                ];
            });

        return response()->json($bills);
    }

    private function nextInvoiceNo(): string
    {
        $last = Invoice::withTrashed()
            ->where('invoice_no', 'like', 'INV-%')
            ->pluck('invoice_no')
            ->map(fn ($no) => (int) substr($no, 4))
            ->sort()
            ->last();

        return 'INV-' . str_pad(($last ?? 0) + 1, 6, '0', STR_PAD_LEFT);
    }

    private function taxPayableAccountId(): ?int
    {
        return ChartOfAccounts::where('account_code', '201002')->value('id')
            ?? ChartOfAccounts::where('name', 'like', 'Sales Tax%')->value('id');
    }

    public function store(Request $request)
    {
        try {
            Log::info('[Invoice] Store called', ['user_id' => auth()->id()]);

            $data = $request->validate([
                'customer_id'   => 'required|exists:chart_of_accounts,id',
                'invoice_date'  => 'required|date',
                'from_date'     => 'required|date',
                'to_date'       => 'required|date|after_or_equal:from_date',
                'bill_ids'      => 'required|array|min:1',
                'bill_ids.*'    => 'exists:bills,id',
                'is_taxable'    => 'nullable|boolean',
                'tax_percent'   => 'nullable|required_if:is_taxable,1|numeric|min:0|max:100',
                'remarks'       => 'nullable|string|max:1000',
            ]);

            $invoice = DB::transaction(function () use ($data) {
                $bills = Bill::where('customer_id', $data['customer_id'])
                    ->whereNull('invoice_id')
                    ->whereIn('id', $data['bill_ids'])
                    ->lockForUpdate()
                    ->get();

                if ($bills->isEmpty()) {
                    throw new \RuntimeException('Selected bills are no longer available to invoice (already invoiced or invalid).');
                }

                $isTaxable = (bool) ($data['is_taxable'] ?? false);
                $taxPct    = $isTaxable ? (float) $data['tax_percent'] : 0;
                $tripPlanSubtotal = round($bills->sum('trip_plan_subtotal'), 2);
                $billsSubtotal    = round($bills->sum('total_amount'), 2);
                $taxAmount = $isTaxable ? round($tripPlanSubtotal * $taxPct / 100, 2) : 0;
                $total     = round($billsSubtotal + $taxAmount, 2);
                $totalContainers = (int) $bills->sum(fn ($bill) => $bill->jobs()->count());

                // Auto-post the tax portion only: Dr Customer / Cr Sales Tax Payable.
                // (The bills' own revenue recognition was already posted when each was created.)
                $voucher = null;
                if ($taxAmount > 0) {
                    $taxAccountId = $this->taxPayableAccountId();
                    if ($taxAccountId) {
                        $voucher = Voucher::create([
                            'voucher_type' => 'journal',
                            'date'         => $data['invoice_date'],
                            'ac_dr_sid'    => $data['customer_id'],
                            'ac_cr_sid'    => $taxAccountId,
                            'amount'       => $taxAmount,
                            'reference'    => null, // invoice_no not known yet — set once the invoice exists
                        ]);
                    }
                }

                $invoice = Invoice::create([
                    'invoice_no'         => $this->nextInvoiceNo(),
                    'customer_id'        => $data['customer_id'],
                    'invoice_date'       => $data['invoice_date'],
                    'from_date'          => $data['from_date'],
                    'to_date'            => $data['to_date'],
                    'is_taxable'         => $isTaxable,
                    'tax_percent'        => $isTaxable ? $taxPct : null,
                    'trip_plan_subtotal' => $tripPlanSubtotal,
                    'tax_amount'         => $taxAmount,
                    'total_containers'   => $totalContainers,
                    'total_amount'       => $total,
                    'paid_amount'        => 0,
                    'status'             => 'pending',
                    'voucher_id'         => $voucher->id ?? null,
                    'remarks'            => $data['remarks'] ?? null,
                    'created_by'         => auth()->id(),
                    'updated_by'         => auth()->id(),
                ]);

                if ($voucher) {
                    $voucher->update([
                        'reference' => $invoice->invoice_no,
                        'remarks'   => "Sales tax on Invoice {$invoice->invoice_no}",
                    ]);
                }

                Bill::whereIn('id', $bills->pluck('id'))->update(['invoice_id' => $invoice->id]);

                return $invoice;
            });

            return redirect()->route('invoices.index')->with('success', "Invoice {$invoice->invoice_no} created successfully.");

        } catch (\Throwable $e) {
            Log::error('[Invoice] Store error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $invoice = Invoice::with(['customer', 'bills'])->findOrFail($id);
        return response()->json($invoice);
    }

    // Deleting an invoice is a full "undo" — any payments already recorded
    // against it are reversed (their vouchers deleted) rather than blocking
    // the delete, so a mistake made after payments were entered can still be
    // corrected. Its bills are released back to Pending, ready to re-invoice.
    public function destroy($id)
    {
        try {
            DB::transaction(function () use ($id) {
                $invoice = Invoice::with('payments.lines')->whereKey($id)->lockForUpdate()->firstOrFail();

                foreach ($invoice->payments as $payment) {
                    foreach ($payment->lines as $line) {
                        if ($line->voucher_id) {
                            Voucher::whereKey($line->voucher_id)->delete();
                        }
                    }
                    $payment->lines()->delete();
                    $payment->delete();
                }

                Bill::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);

                if ($invoice->voucher_id) {
                    Voucher::whereKey($invoice->voucher_id)->delete();
                }

                $invoice->delete();
            });

            return redirect()->route('invoices.index')->with('success', 'Invoice deleted successfully. Any payments recorded against it were reversed, and its bills released back to Pending.');

        } catch (\Throwable $e) {
            Log::error('[Invoice] Destroy error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    // Print — adapted from the existing MM Logistics Sales Tax Invoice layout,
    // itemised by the bills that make up this invoice.
    public function print($id)
    {
        $invoice = Invoice::with(['customer', 'bills'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('MMMTS');
        $pdf->SetAuthor('Your Company');
        $pdf->SetTitle('Invoice ' . $invoice->invoice_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 8, 40);
        }

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(120, 12);
        $pdf->Cell(80, 8, $invoice->is_taxable ? 'SALES TAX INVOICE' : 'SALES INVOICE', 0, 1, 'R');

        $pdf->Ln(5);
        $pdf->SetFont('helvetica', '', 10);

        $infoHtml = '
        <table cellpadding="3" cellspacing="0" width="60%">
            <tr>
                <td width="60%">
                    <b>' . e($invoice->customer->name ?? '') . '</b><br>
                    ' . e($invoice->customer->address ?? '') . '
                </td>
                <td width="40%">
                    <table border="1" cellpadding="4" cellspacing="0" style="font-size:10px;">
                        <tr><td width="40%"><b>Invoice No.</b></td><td width="60%">' . e($invoice->invoice_no) . '</td></tr>
                        <tr><td width="40%"><b>Invoice Date</b></td><td width="60%">' . $invoice->invoice_date->format('d-m-Y') . '</td></tr>
                    </table>
                </td>
            </tr>
        </table>';
        $pdf->writeHTML($infoHtml, true, false, false, false, '');

        $html = '<table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5; font-weight:bold;">
                <th width="8%">S.No</th>
                <th width="27%">Bill No.</th>
                <th width="15%">Bill Date</th>
                <th width="15%">Containers</th>
                <th width="35%">Amount</th>
            </tr>';

        foreach ($invoice->bills as $i => $bill) {
            $html .= '<tr>
                <td>' . ($i + 1) . '</td>
                <td>' . e($bill->bill_no) . '</td>
                <td>' . $bill->bill_date->format('d-m-Y') . '</td>
                <td>' . $bill->container_count . '</td>
                <td align="right">' . number_format($bill->total_amount, 2) . '</td>
            </tr>';
        }

        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="4" align="right">Subtotal (Bills)</td>
                <td align="right">' . number_format($invoice->total_amount - $invoice->tax_amount, 2) . '</td>
            </tr>';

        if ($invoice->is_taxable) {
            $html .= '
            <tr>
                <td colspan="4" align="right">Sales Tax (' . rtrim(rtrim(number_format($invoice->tax_percent, 2), '0'), '.') . '% on Trip Plan charges of ' . number_format($invoice->trip_plan_subtotal, 2) . ')</td>
                <td align="right">' . number_format($invoice->tax_amount, 2) . '</td>
            </tr>';
        }

        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="4" align="right"><b>Total Payable Amount</b></td>
                <td align="right"><b>' . number_format($invoice->total_amount, 2) . '</b></td>
            </tr>
            <tr>
                <td colspan="4" align="right">Total Containers</td>
                <td align="right">' . $invoice->total_containers . '</td>
            </tr>';
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(5);

        if (!empty($invoice->remarks)) {
            $pdf->writeHTML('<b>Remarks:</b><br><span style="font-size:12px;">' . nl2br(e($invoice->remarks)) . '</span>', true, false, true, false, '');
        }

        $pdf->Ln(20);
        $yPos = $pdf->GetY();
        $lineWidth = 40;
        $pdf->Line(28, $yPos, 28 + $lineWidth, $yPos);
        $pdf->Line(130, $yPos, 130 + $lineWidth, $yPos);
        $pdf->SetXY(28, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Prepared By', 0, 0, 'C');
        $pdf->SetXY(130, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output('invoice_' . $invoice->invoice_no . '.pdf', 'I');
    }
}