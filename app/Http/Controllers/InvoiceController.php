<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\ChartOfAccounts;
use App\Models\Invoice;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Support\DocumentNumber;

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
            // jobs.vehicles / jobs.ptyVehicles so container_count (vehicles,
            // not job rows) is computed from loaded data — see
            // Bill::getContainerCountAttribute().
            ->with(['jobs.vehicles', 'jobs.ptyVehicles'])
            ->orderBy('bill_date')
            ->get(['id', 'bill_no', 'bill_date', 'trip_plan_subtotal', 'other_charges_subtotal', 'total_amount'])
            ->map(function ($bill) {
                return [
                    'id'                  => $bill->id,
                    'bill_no'             => $bill->bill_no,
                    'bill_date'           => $bill->bill_date->format('Y-m-d'),
                    'trip_plan_subtotal'  => (float) $bill->trip_plan_subtotal,
                    'total_amount'        => (float) $bill->total_amount,
                    'container_count'     => $bill->container_count,
                ];
            });

        return response()->json($bills);
    }

    private function nextInvoiceNo($invoiceDate): string
    {
        return DocumentNumber::next(Invoice::class, 'invoice_no', 'invoice', $invoiceDate);
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
                'customer_tax_share_percent' => 'nullable|numeric|min:0|max:100',
                'remarks'       => 'nullable|string|max:1000',
            ]);

            $invoice = DB::transaction(function () use ($data) {
                $bills = Bill::where('customer_id', $data['customer_id'])
                    ->whereNull('invoice_id')
                    ->whereIn('id', $data['bill_ids'])
                    ->with(['jobs.vehicles', 'jobs.ptyVehicles'])
                    ->lockForUpdate()
                    ->get();

                if ($bills->isEmpty()) {
                    throw new \RuntimeException('Selected bills are no longer available to invoice (already invoiced or invalid).');
                }

                $isTaxable = (bool) ($data['is_taxable'] ?? false);
                $taxPct    = $isTaxable ? (float) $data['tax_percent'] : 0;
                $tripPlanSubtotal = round($bills->sum('trip_plan_subtotal'), 2);
                $billsSubtotal    = round($bills->sum('total_amount'), 2);
                // Item 13 — tax is applied on the grand total being invoiced
                // (billsSubtotal, which is each job's full grand total added
                // up), not just the old Trip Plan portion. Trip Plan no
                // longer carries its own charges anyway (item 2), so basing
                // tax on it would previously have zeroed the tax out.
                //
                // Item 2 (round 3) — "Sale tax 20% paid by customer, 80% by
                // company". tax_amount is still the FULL, true sales tax
                // liability (unchanged formula) — what's NEW is that only
                // customerTaxShare% of it is actually billed to the
                // customer; the rest is a cost the company absorbs itself
                // rather than passing on. total_amount below is built from
                // customerTaxAmount now, not the full taxAmount.
                $taxAmount = $isTaxable ? round($billsSubtotal * $taxPct / 100, 2) : 0;
                $customerTaxSharePct = $isTaxable ? (float) ($data['customer_tax_share_percent'] ?? 20) : null;
                $customerTaxAmount = $isTaxable ? round($taxAmount * $customerTaxSharePct / 100, 2) : 0;
                $companyTaxAmount  = $isTaxable ? round($taxAmount - $customerTaxAmount, 2) : 0;
                $total     = round($billsSubtotal + $customerTaxAmount, 2);
                // One vehicle = one container (see Bill::getContainerCountAttribute()).
                $totalContainers = (int) $bills->sum(fn ($bill) => $bill->container_count);

                // Auto-post only the CUSTOMER's share: Dr Customer / Cr Sales
                // Tax Payable. (The bills' own revenue recognition was
                // already posted when each was created.) The company's own
                // companyTaxAmount share above is stored on the invoice for
                // visibility/reporting, but is deliberately NOT auto-posted
                // anywhere yet — there's no designated expense account for
                // "sales tax absorbed by the company" in the chart of
                // accounts today, and inventing one silently here felt like
                // the wrong call for a real ledger posting. Post that 80%
                // manually via a Journal Voucher for now, or tell me which
                // account it should hit and I'll wire it up automatically.
                $voucher = null;
                if ($customerTaxAmount > 0) {
                    $taxAccountId = $this->taxPayableAccountId();
                    if ($taxAccountId) {
                        $voucher = Voucher::create([
                            'voucher_type' => 'journal',
                            'date'         => $data['invoice_date'],
                            'ac_dr_sid'    => $data['customer_id'],
                            'ac_cr_sid'    => $taxAccountId,
                            'amount'       => $customerTaxAmount,
                            'reference'    => null, // invoice_no not known yet — set once the invoice exists
                        ]);
                    }
                }

                $invoice = Invoice::create([
                    'invoice_no'         => $this->nextInvoiceNo($data['invoice_date']),
                    'customer_id'        => $data['customer_id'],
                    'invoice_date'       => $data['invoice_date'],
                    'from_date'          => $data['from_date'],
                    'to_date'            => $data['to_date'],
                    'is_taxable'         => $isTaxable,
                    'tax_percent'        => $isTaxable ? $taxPct : null,
                    'customer_tax_share_percent' => $customerTaxSharePct,
                    'trip_plan_subtotal' => $tripPlanSubtotal,
                    'tax_amount'         => $taxAmount,
                    'customer_tax_amount' => $customerTaxAmount,
                    'company_tax_amount' => $companyTaxAmount,
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
                        'remarks'   => "Sales tax (customer's share) on Invoice {$invoice->invoice_no}",
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
        // bills.jobs.vehicles / ptyVehicles so each bill's container count
        // (vehicles, not job rows) comes from loaded data.
        $invoice = Invoice::with(['customer', 'bills.company', 'bills.jobs.vehicles', 'bills.jobs.ptyVehicles', 'creator'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('MMMTS');
        $pdf->SetAuthor('Your Company');
        $pdf->SetTitle('Invoice ' . $invoice->invoice_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        // Item 8 — an Invoice has no company of its own; it simply reads the
        // company off the first of the bills it aggregates (in practice an
        // invoice only ever aggregates bills from one company). Falls back
        // to the old hard-coded M M Logistics logo if none of its bills has
        // a company set (e.g. bills created before item 8).
        $company = optional($invoice->bills->first())->company;

        $textX = 12;
        // Width capped at 22mm (rather than the old fixed 40mm) so an
        // unusually tall/narrow logo doesn't grow past the divider line
        // below and collide with the customer block that follows it.
        $logoPath = $company && $company->logo ? Storage::disk('public')->path($company->logo) : public_path('assets/img/logo.png');
        if ($logoPath && file_exists($logoPath)) {
            $pdf->Image($logoPath, 10, 8, 22);
            $textX = 34;
        }

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY($textX, 10);
        $pdf->Cell(0, 7, $company ? strtoupper($company->name) : 'M M LOGISTICS', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 8);
        $lineY = 17;
        if ($company && $company->address) {
            $pdf->SetXY($textX, $lineY);
            $pdf->Cell(0, 4, $company->address, 0, 1, 'L');
            $lineY += 4;
        }
        if ($company && $company->contact_no) {
            $pdf->SetXY($textX, $lineY);
            $pdf->Cell(0, 4, 'Contact: ' . $company->contact_no, 0, 1, 'L');
        }

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(120, 12);
        $pdf->Cell(80, 8, $invoice->is_taxable ? 'SALES TAX INVOICE' : 'SALES INVOICE', 0, 1, 'R');

        // A fixed divider line (mirroring Bill's letterhead) placed clear of
        // the 22mm-tall logo (bottom edge ~30mm) and of the text block above
        // it, then an explicit SetY — not a Ln() off the text block, whose
        // height varies with whether an address/contact were printed — so
        // the info table below always starts at the same fixed position.
        $pdf->Line(10, 32, 200, 32);
        $pdf->SetY(37);
        $pdf->SetFont('helvetica', '', 10);

        $infoHtml = '
        <table cellpadding="3" cellspacing="0" width="100%">
            <tr>
                <td width="60%">
                    <b>' . e($invoice->customer->name ?? '') . '</b><br>
                    ' . e($invoice->customer->address ?? '') . '<br>
                    ' . e($invoice->customer->trn ?? '') . '
                </td>
                <td width="40%">
                    <table border="1" cellpadding="4" cellspacing="0" style="font-size:10px;">
                        <tr><td width="40%"><b>Invoice No.</b></td><td width="60%">' . e($invoice->invoice_no) . '</td></tr>
                        <tr><td width="40%"><b>Invoice Date</b></td><td width="60%">' . $invoice->invoice_date->format('d-m-Y') . '</td></tr>
                        <tr><td width="40%"><b>Created By</b></td><td width="60%">' . e($invoice->creator->name ?? '—') . '</td></tr>
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

        // Item 2 (round 3) — grand-total-before-tax is derived from
        // customer_tax_amount (what actually landed in total_amount), not
        // the full tax_amount — see InvoiceController::store(). This is the
        // ONE "Subtotal (Bills)" row; the older row based on
        // total_amount - tax_amount was wrong once the tax is split.
        $grandTotalBeforeTax = $invoice->total_amount - $invoice->customer_tax_amount;

        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="4" align="right">Subtotal (Bills)</td>
                <td align="right">' . number_format($grandTotalBeforeTax, 2) . '</td>
            </tr>';

        if ($invoice->is_taxable) {
            $customerSharePct = rtrim(rtrim(number_format($invoice->customer_tax_share_percent ?? 20, 2), '0'), '.');
            $html .= '
            <tr>
                <td colspan="4" align="right">Sales Tax (' . rtrim(rtrim(number_format($invoice->tax_percent, 2), '0'), '.') . '% on Grand Total of ' . number_format($grandTotalBeforeTax, 2) . ')</td>
                <td align="right">' . number_format($invoice->tax_amount, 2) . '</td>
            </tr>
            <tr>
                <td colspan="4" align="right">Less: Company-Absorbed Portion (' . (100 - (float) ($invoice->customer_tax_share_percent ?? 20)) . '% of Sales Tax)</td>
                <td align="right">(' . number_format($invoice->company_tax_amount, 2) . ')</td>
            </tr>
            <tr>
                <td colspan="4" align="right">Net Sales Tax Payable by Customer (' . $customerSharePct . '%)</td>
                <td align="right">' . number_format($invoice->customer_tax_amount, 2) . '</td>
            </tr>';
        }

        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="4" align="right"><b>Total Payable Amount</b></td>
                <td align="right"><b>' . number_format($invoice->total_amount, 2) . '</b></td>
            </tr>
            <tr>
                <td colspan="4" align="right">Total Containers</td>
                <td align="right">' . $invoice->bills->sum('container_count') . '</td>
            </tr>';
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(3);

        // Item 14 — explicit tax-inclusive/exclusive statement on print.
        $pdf->SetFont('helvetica', 'I', 8);
        $taxStatement = $invoice->is_taxable
            // Item 2 (round 3) — make the split explicit on print, not just
            // the net figure, since the customer is only being charged part
            // of the calculated tax.
            ? ('Amounts above are exclusive of Sales Tax; Sales Tax of ' . rtrim(rtrim(number_format($invoice->tax_percent, 2), '0'), '.') . '% has been calculated and split per agreement — ' . rtrim(rtrim(number_format($invoice->customer_tax_share_percent ?? 20, 2), '0'), '.') . '% payable by the customer (added above) and the remainder absorbed by the company.')
            : 'This invoice does not include Sales Tax.';
        $pdf->Cell(0, 5, $taxStatement, 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Ln(2);

        if (!empty($invoice->remarks)) {
            $pdf->writeHTML('<b>Remarks:</b><br><span style="font-size:12px;">' . nl2br(e($invoice->remarks)) . '</span>', true, false, true, false, '');
        }

        // If the content above has filled the page, start the signature block
        // on a fresh page as a whole instead of splitting it across pages.
        if ($pdf->GetY() + 32 > $pdf->getPageHeight() - $pdf->getBreakMargin()) {
            $pdf->AddPage();
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
        return $pdf->Output('invoice_' . str_replace('/', '-', $invoice->invoice_no) . '.pdf', 'I');
    }
}