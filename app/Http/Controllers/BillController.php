<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\ChartOfAccounts;
use App\Models\DailyJob;
use App\Models\Invoice;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillController extends Controller
{
    public function index(Request $request)
    {
        $query = Bill::with('customer')->withCount('jobs');

        if ($request->filled('customer_id') && $request->customer_id !== 'all') {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('invoiced') && $request->invoiced !== 'all') {
            $request->invoiced === 'invoiced' ? $query->whereNotNull('invoice_id') : $query->whereNull('invoice_id');
        }

        $bills = $query->latest('bill_date')->latest('id')->get();
        $customers = ChartOfAccounts::customers()->orderBy('name')->get();

        return view('bills.index', compact('bills', 'customers'));
    }

    public function create()
    {
        $customers = ChartOfAccounts::customers()->orderBy('name')->get();
        return view('bills.create', compact('customers'));
    }

    // AJAX: non-billed jobs for a customer within a date range
    public function getJobs(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:chart_of_accounts,id',
            'from_date'   => 'required|date',
            'to_date'     => 'required|date|after_or_equal:from_date',
        ]);

        $jobs = DailyJob::with(['vehicle', 'route', 'vendor'])
            ->where('customer_id', $request->customer_id)
            ->whereNull('bill_id')
            ->whereBetween('date', [$request->from_date, $request->to_date])
            ->orderBy('date')
            ->get()
            ->map(function ($job) {
                $isPty = $job->job_type === 'party_to_party';
                return [
                    'id'                  => $job->id,
                    'job_no'              => $job->job_no,
                    'job_type'            => $job->job_type,
                    'date'                => $job->date->format('Y-m-d'),
                    'vehicle'             => $isPty ? ($job->pty_vehicle_no ?? '—') : ($job->vehicle->name ?? '—'),
                    'route'               => $isPty ? ($job->pty_destination ?? '—') : ($job->route->name ?? '—'),
                    'vendor'              => $isPty ? ($job->vendor->name ?? '—') : null,
                    'trip_plan_total'     => (float) $job->trip_plan_total,
                    'other_charges_total' => (float) $job->other_charges_total,
                    'job_total'           => (float) $job->job_total,
                ];
            });

        return response()->json($jobs);
    }

    private function nextBillNo(): string
    {
        $last = Bill::withTrashed()
            ->where('bill_no', 'like', 'BILL-%')
            ->pluck('bill_no')
            ->map(fn ($no) => (int) substr($no, 5))
            ->sort()
            ->last();

        return 'BILL-' . str_pad(($last ?? 0) + 1, 6, '0', STR_PAD_LEFT);
    }

    private function revenueAccountId(): ?int
    {
        return ChartOfAccounts::where('account_code', '401001')->value('id')
            ?? ChartOfAccounts::where('account_type', 'revenue')->value('id');
    }

    private function rules(): array
    {
        return [
            'customer_id' => 'required|exists:chart_of_accounts,id',
            'from_date'   => 'required|date',
            'to_date'     => 'required|date|after_or_equal:from_date',
            'bill_date'   => 'required|date',
            'job_ids'     => 'required|array|min:1',
            'job_ids.*'   => 'exists:daily_jobs,id',
            'remarks'     => 'nullable|string|max:1000',
        ];
    }

    public function store(Request $request)
    {
        try {
            Log::info('[Bill] Store called', ['user_id' => auth()->id()]);
            $data = $request->validate($this->rules());

            $bill = DB::transaction(function () use ($data) {
                // Never trust client-side totals — recompute from the live job records,
                // scoped to this customer and still non-billed (avoids double-billing races).
                $jobs = DailyJob::where('customer_id', $data['customer_id'])
                    ->whereNull('bill_id')
                    ->whereIn('id', $data['job_ids'])
                    ->lockForUpdate()
                    ->get();

                if ($jobs->isEmpty()) {
                    throw new \RuntimeException('Selected jobs are no longer available to bill (already billed or invalid).');
                }

                $tripPlanSubtotal   = round($jobs->sum('trip_plan_total'), 2);
                $otherChargesSubtotal = round($jobs->sum('other_charges_total'), 2);
                // No tax at Bill level — tax (if any) is applied on the Invoice, on top
                // of the combined trip-plan charges of the bills it aggregates.
                $total = round($tripPlanSubtotal + $otherChargesSubtotal, 2);

                $billNo = $this->nextBillNo();

                // Auto-post: Dr Customer (Receivable) / Cr Sales Revenue
                $voucher = null;
                $revenueAccountId = $this->revenueAccountId();
                if ($revenueAccountId) {
                    $voucher = Voucher::create([
                        'voucher_type' => 'journal',
                        'date'         => $data['bill_date'],
                        'ac_dr_sid'    => $data['customer_id'],
                        'ac_cr_sid'    => $revenueAccountId,
                        'amount'       => $total,
                        'reference'    => $billNo,
                        'remarks'      => "Bill {$billNo}",
                    ]);
                }

                $bill = Bill::create([
                    'bill_no'                => $billNo,
                    'customer_id'            => $data['customer_id'],
                    'from_date'              => $data['from_date'],
                    'to_date'                => $data['to_date'],
                    'bill_date'              => $data['bill_date'],
                    'trip_plan_subtotal'     => $tripPlanSubtotal,
                    'other_charges_subtotal' => $otherChargesSubtotal,
                    'total_amount'           => $total,
                    'voucher_id'             => $voucher->id ?? null,
                    'remarks'                => $data['remarks'] ?? null,
                    'created_by'             => auth()->id(),
                    'updated_by'             => auth()->id(),
                ]);

                DailyJob::whereIn('id', $jobs->pluck('id'))->update(['bill_id' => $bill->id]);

                return $bill;
            });

            return redirect()->route('bills.index')->with('success', "Bill {$bill->bill_no} created successfully.");

        } catch (\Throwable $e) {
            Log::error('[Bill] Store error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $bill = Bill::with(['customer', 'jobs'])->findOrFail($id);
        return response()->json($bill);
    }

    // Deleting a bill is a full "undo" — if it was already invoiced (and that
    // invoice already had payments recorded), all of that gets cascaded and
    // reversed rather than blocked, so a mistake can always be corrected:
    //   payments on the invoice -> reversed (vouchers deleted)
    //   invoice                 -> voided (all its bills released to Pending)
    //   this bill               -> its jobs released, its voucher reversed, deleted
    public function destroy($id)
    {
        try {
            DB::transaction(function () use ($id) {
                $bill = Bill::whereKey($id)->lockForUpdate()->firstOrFail();

                if ($bill->invoice_id) {
                    $invoice = Invoice::with('payments.lines')->whereKey($bill->invoice_id)->lockForUpdate()->first();

                    if ($invoice) {
                        foreach ($invoice->payments as $payment) {
                            foreach ($payment->lines as $line) {
                                if ($line->voucher_id) {
                                    Voucher::whereKey($line->voucher_id)->delete();
                                }
                            }
                            $payment->lines()->delete();
                            $payment->delete();
                        }

                        // Release every bill that was aggregated onto this invoice
                        // (not just this one) — the invoice's totals no longer apply
                        // once one of its component bills is removed.
                        Bill::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);

                        if ($invoice->voucher_id) {
                            Voucher::whereKey($invoice->voucher_id)->delete();
                        }

                        $invoice->delete();
                    }
                }

                DailyJob::where('bill_id', $bill->id)->update(['bill_id' => null]);
                if ($bill->voucher_id) {
                    Voucher::whereKey($bill->voucher_id)->delete();
                }
                $bill->delete();
            });

            return redirect()->route('bills.index')->with('success', 'Bill deleted successfully. If it was on an invoice, that invoice (and any payments against it) were reversed too, and its jobs released back to Non-Billed.');

        } catch (\Throwable $e) {
            Log::error('[Bill] Destroy error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    // Print — Bill PDF itemising the jobs it aggregates.
    public function print($id)
    {
        $bill = Bill::with(['customer', 'jobs.vehicle', 'jobs.route', 'jobs.vendor'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('MMMTS');
        $pdf->SetAuthor('Your Company');
        $pdf->SetTitle('Bill ' . $bill->bill_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 8, 40);
        }

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(120, 12);
        $pdf->Cell(80, 8, 'BILL', 0, 1, 'R');

        $pdf->Ln(5);
        $pdf->SetFont('helvetica', '', 10);

        $infoHtml = '
        <table cellpadding="3" cellspacing="0" width="60%">
            <tr>
                <td width="60%">
                    <b>' . e($bill->customer->name ?? '') . '</b><br>
                    ' . e($bill->customer->address ?? '') . '
                </td>
                <td width="40%">
                    <table border="1" cellpadding="4" cellspacing="0" style="font-size:10px;">
                        <tr><td width="40%"><b>Bill No.</b></td><td width="60%">' . e($bill->bill_no) . '</td></tr>
                        <tr><td width="40%"><b>Bill Date</b></td><td width="60%">' . $bill->bill_date->format('d-m-Y') . '</td></tr>
                        <tr><td width="40%"><b>Period</b></td><td width="60%">' . $bill->from_date->format('d-m-Y') . ' — ' . $bill->to_date->format('d-m-Y') . '</td></tr>
                    </table>
                </td>
            </tr>
        </table>';
        $pdf->writeHTML($infoHtml, true, false, false, false, '');

        $html = '<table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5; font-weight:bold;">
                <th width="6%">S.No</th>
                <th width="18%">Job No.</th>
                <th width="12%">Date</th>
                <th width="20%">Vehicle / Vendor</th>
                <th width="20%">Route / Destination</th>
                <th width="12%">Trip Plan</th>
                <th width="12%">Other Charges</th>
            </tr>';

        foreach ($bill->jobs as $i => $job) {
            $isPty = $job->job_type === 'party_to_party';
            $html .= '<tr>
                <td>' . ($i + 1) . '</td>
                <td>' . e($job->job_no) . '</td>
                <td>' . $job->date->format('d-m-Y') . '</td>
                <td>' . e($isPty ? ($job->vendor->name ?? '—') : ($job->vehicle->name ?? '—')) . '</td>
                <td>' . e($isPty ? ($job->pty_destination ?? '—') : ($job->route->name ?? '—')) . '</td>
                <td align="right">' . number_format($job->trip_plan_total, 2) . '</td>
                <td align="right">' . number_format($job->other_charges_total, 2) . '</td>
            </tr>';
        }

        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="5" align="right">Trip Plan Subtotal</td>
                <td colspan="2" align="right">' . number_format($bill->trip_plan_subtotal, 2) . '</td>
            </tr>
            <tr style="background-color:#f5f5f5;">
                <td colspan="5" align="right">Other Charges Subtotal</td>
                <td colspan="2" align="right">' . number_format($bill->other_charges_subtotal, 2) . '</td>
            </tr>
            <tr style="background-color:#f5f5f5;">
                <td colspan="5" align="right"><b>Total Bill Amount</b></td>
                <td colspan="2" align="right"><b>' . number_format($bill->total_amount, 2) . '</b></td>
            </tr>
            <tr>
                <td colspan="5" align="right">Total Containers</td>
                <td colspan="2" align="right">' . $bill->container_count . '</td>
            </tr>';
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(5);

        if (!empty($bill->remarks)) {
            $pdf->writeHTML('<b>Remarks:</b><br><span style="font-size:12px;">' . nl2br(e($bill->remarks)) . '</span>', true, false, true, false, '');
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

        return $pdf->Output('bill_' . $bill->bill_no . '.pdf', 'I');
    }
}