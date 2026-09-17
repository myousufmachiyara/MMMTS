<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\ChartOfAccounts;
use App\Models\DailyJob;
use App\Models\Invoice;
use App\Models\OurCompany;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
        // Item 8 — which "Our Company" is billing this customer, picked here
        // and carried onto the Bill print's letterhead.
        $companies = OurCompany::where('is_active', true)->orderBy('name')->get();
        return view('bills.create', compact('customers', 'companies'));
    }

    // AJAX: non-billed jobs for a customer within a date range
    public function getJobs(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:chart_of_accounts,id',
            'from_date'   => 'required|date',
            'to_date'     => 'required|date|after_or_equal:from_date',
        ]);

        $jobs = DailyJob::with(['vehicles.vehicle', 'route', 'vendor'])
            ->where('customer_id', $request->customer_id)
            ->whereNull('bill_id')
            // An 'incomplete' Direct job (item 11 — assistant hasn't had its
            // rates filled in by an admin yet) has no real charges to bill.
            ->where(function ($q) {
                $q->where('job_type', 'party_to_party')->orWhere('status', 'complete');
            })
            ->whereBetween('date', [$request->from_date, $request->to_date])
            ->orderBy('date')
            ->get()
            ->map(function ($job) {
                $isPty = $job->job_type === 'party_to_party';
                return [
                    'id'                       => $job->id,
                    'job_no'                   => $job->job_no,
                    'job_type'                 => $job->job_type,
                    'date'                     => $job->date->format('Y-m-d'),
                    'vehicle'                  => $isPty ? ($job->pty_vehicle_no ?? '—') : ($job->vehicles->pluck('vehicle.name')->filter()->implode(', ') ?: '—'),
                    // Route is shared across every vehicle on the job — read
                    // from the job header, falling back to a per-vehicle
                    // pluck only for older jobs saved before that change.
                    'route'                    => $isPty ? ($job->pty_destination ?? '—') : ($job->route->name ?? ($job->vehicles->pluck('route.name')->filter()->implode(', ') ?: '—')),
                    'vendor'                   => $isPty ? ($job->vendor->name ?? '—') : null,
                    'trip_plan_total'          => (float) $job->trip_plan_total,
                    'detention_charges_total'  => (float) $job->detention_charges_total,
                    'other_charges_total'      => (float) $job->other_charges_total,
                    'job_total'                => (float) $job->job_total,
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
            // Item 8 — required going forward so every new bill's print
            // always has a real letterhead; older bills (company_id null)
            // fall back to the old hard-coded one at print time.
            'company_id'  => 'required|exists:our_companies,id',
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
                $jobs = DailyJob::with('vehicles')
                    ->where('customer_id', $data['customer_id'])
                    ->whereNull('bill_id')
                    // Mirrors getJobs() — never bill a Direct job an admin
                    // hasn't finished filling rates into yet (item 11).
                    ->where(function ($q) {
                        $q->where('job_type', 'party_to_party')->orWhere('status', 'complete');
                    })
                    ->whereIn('id', $data['job_ids'])
                    ->lockForUpdate()
                    ->get();

                if ($jobs->isEmpty()) {
                    throw new \RuntimeException('Selected jobs are no longer available to bill (already billed, invalid, or still incomplete).');
                }

                $tripPlanSubtotal      = round($jobs->sum('trip_plan_total'), 2);
                $detentionChargesSubtotal = round($jobs->sum('detention_charges_total'), 2);
                $otherChargesSubtotal  = round($jobs->sum('other_charges_total'), 2);
                // No tax at Bill level — tax (if any) is applied on the Invoice, on top
                // of the combined grand totals of the bills it aggregates (item 13).
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
                    'bill_no'                     => $billNo,
                    'customer_id'                 => $data['customer_id'],
                    'company_id'                  => $data['company_id'],
                    'from_date'                   => $data['from_date'],
                    'to_date'                     => $data['to_date'],
                    'bill_date'                   => $data['bill_date'],
                    'trip_plan_subtotal'          => $tripPlanSubtotal,
                    'other_charges_subtotal'      => $otherChargesSubtotal,
                    'detention_charges_subtotal'  => $detentionChargesSubtotal,
                    'total_amount'                => $total,
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
        $bill = Bill::with([
            'customer', 'company', 'creator',
            'jobs.vehicles.vehicle', 'jobs.vehicles.deliveryChallan', 'jobs.route', 'jobs.vendor',
        ])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('MMMTS');
        $pdf->SetAuthor('Your Company');
        $pdf->SetTitle('Bill ' . $bill->bill_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        // Item 8 — company-wise letterhead. A bill created before this
        // change (or with no company picked) has no $bill->company and
        // falls back to the old hard-coded M M Logistics block below.
        $company = $bill->company;
        $textX = 10;
        if ($company) {
            $logoPath = $company->logo ? Storage::disk('public')->path($company->logo) : null;
            if ($logoPath && file_exists($logoPath)) {
                $pdf->Image($logoPath, 10, 8, 22);
                $textX = 34;
            }

            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->SetXY($textX, 10);
            $pdf->Cell(0, 7, strtoupper($company->name), 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 9);
            $lineY = 17;
            if ($company->address) {
                $pdf->SetXY($textX, $lineY);
                $pdf->Cell(0, 5, $company->address, 0, 1, 'L');
                $lineY += 5;
            }
            if ($company->contact_no) {
                $pdf->SetXY($textX, $lineY);
                $pdf->Cell(0, 5, 'Contact: ' . $company->contact_no, 0, 1, 'L');
            }
        } else {
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->SetXY(10, 10);
            $pdf->Cell(0, 7, 'M M LOGISTICS', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetXY(10, 17);
            $pdf->Cell(0, 5, 'Room No 301/307, 3rd Floor, Custom Trade Tower,', 0, 1, 'L');
            $pdf->SetXY(10, 22);
            $pdf->Cell(0, 5, 'KPT Stadium, Kharadar, Karachi', 0, 1, 'L');
        }

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(140, 10);
        $pdf->Cell(60, 6, 'BILL', 0, 1, 'R');
        $pdf->SetFont('helvetica', 'B', 10);

        $pdf->Line(10, 30, 200, 30);
        $pdf->Ln(12);
        $pdf->SetFont('helvetica', '', 10);

        $infoHtml = '
        <table cellpadding="3" cellspacing="0" width="100%">
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
                        <tr><td width="40%"><b>Created By</b></td><td width="60%">' . e($bill->creator->name ?? '—') . '</td></tr>
                    </table>
                </td>
            </tr>
        </table>';
        $pdf->writeHTML($infoHtml, true, false, false, false, '');

        $html = '<table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5; font-weight:bold;">
                <th width="6%">S.No</th>
                <th width="16%">Job No.</th>
                <th width="10%">Date</th>
                <th width="17%">Vehicle / Vendor</th>
                <th width="17%">Route / Destination</th>
                <th width="12%">Detention Charges</th>
                <th width="11%">Other Charges</th>
                <th width="11%">Job Total</th>
            </tr>';

        foreach ($bill->jobs as $i => $job) {
            $isPty = $job->job_type === 'party_to_party';
            $html .= '<tr>
                <td>' . ($i + 1) . '</td>
                <td>' . e($job->job_no) . '</td>
                <td>' . $job->date->format('d-m-Y') . '</td>
                <td>' . e($isPty ? ($job->vendor->name ?? '—') : ($job->vehicles->pluck('vehicle.name')->filter()->implode(', ') ?: '—')) . '</td>
                <td>' . e($isPty ? ($job->pty_destination ?? '—') : ($job->route->name ?? ($job->vehicles->pluck('route.name')->filter()->implode(', ') ?: '—'))) . '</td>
                <td align="right">' . number_format($job->detention_charges_total, 2) . '</td>
                <td align="right">' . number_format($job->other_charges_total, 2) . '</td>
                <td align="right">' . number_format($job->job_total, 2) . '</td>
            </tr>';
        }

        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="5" align="right">Detention Charges Subtotal</td>
                <td colspan="3" align="right">' . number_format($bill->detention_charges_subtotal, 2) . '</td>
            </tr>
            <tr style="background-color:#f5f5f5;">
                <td colspan="5" align="right">Other Charges Subtotal</td>
                <td colspan="3" align="right">' . number_format($bill->other_charges_subtotal, 2) . '</td>
            </tr>
            <tr style="background-color:#f5f5f5;">
                <td colspan="5" align="right"><b>Total Bill Amount (Grand Total)</b></td>
                <td colspan="3" align="right"><b>' . number_format($bill->total_amount, 2) . '</b></td>
            </tr>
            <tr>
                <td colspan="5" align="right">Total Containers</td>
                <td colspan="3" align="right">' . $bill->container_count . '</td>
            </tr>';
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(3);

        // Item 9 — the Rent/Labour/Yard/Kanta/Extra Port/Detention breakdown
        // that used to live on the Job Slip print now lives here instead,
        // summed across every job this bill aggregates (same row labels and
        // layout Job Slip used to show for a single job).
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Charges Breakdown', 0, 1, 'L');

        $breakdownHtml = '
        <table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="text-align:right;font-size:10px;">
            <tr><td width="80%" align="left">Rent</td><td width="20%">' . number_format($bill->jobs->sum('rent'), 2) . '</td></tr>
            <tr><td align="left">Labour Charges</td><td>' . number_format($bill->jobs->sum('labour_charges'), 2) . '</td></tr>
            <tr><td align="left">Yard Charges</td><td>' . number_format($bill->jobs->sum('yard_charges'), 2) . '</td></tr>
            <tr><td align="left">Weight Bridge (Kanta)</td><td>' . number_format($bill->jobs->sum('kanta_charges'), 2) . '</td></tr>
            <tr><td align="left">Extra Port Charges</td><td>' . number_format($bill->jobs->sum('extra_port_charges_total'), 2) . '</td></tr>
            <tr><td align="left">Detention Charges</td><td>' . number_format($bill->jobs->sum('detention_total'), 2) . '</td></tr>
        </table>';
        $pdf->writeHTML($breakdownHtml, true, false, true, false, '');
        $pdf->Ln(4);

        // Item 10 — every vehicle across every job on this bill (matching
        // the Job Slip's own Vehicles table), rather than the collapsed
        // comma-separated list in the "Vehicle / Vendor" column above.
        $vehicleRowsHtml = '';
        $vi = 0;
        foreach ($bill->jobs as $job) {
            if ($job->job_type === 'party_to_party') {
                $vi++;
                $vehicleRowsHtml .= '<tr>
                    <td>' . $vi . '</td>
                    <td>' . e($job->job_no) . '</td>
                    <td>' . e($job->pty_vehicle_no ?? '—') . '</td>
                    <td>—</td>
                    <td>—</td>
                </tr>';
                continue;
            }
            foreach ($job->vehicles as $line) {
                $vi++;
                $vehicleRowsHtml .= '<tr>
                    <td>' . $vi . '</td>
                    <td>' . e($job->job_no) . '</td>
                    <td>' . e($line->vehicle->name ?? '') . ' (' . e($line->vehicle->vehicle_no ?? '') . ')</td>
                    <td>' . e($line->container_no ?? '—') . '</td>
                    <td>' . e($line->deliveryChallan->dc_no ?? '—') . '</td>
                </tr>';
            }
        }

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Vehicles (' . $vi . ')', 0, 1, 'L');

        $vehHtml = '<table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:10px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <th width="6%">#</th><th width="14%">Job No.</th><th width="35%">Vehicle</th><th width="25%">Container #</th><th width="20%">DC #</th>
            </tr>' . $vehicleRowsHtml . '</table>';
        $pdf->writeHTML($vehHtml, true, false, true, false, '');
        $pdf->Ln(3);

        // Item 14 — make explicit that Sales Tax, when applicable, is a
        // separate line added at the Invoice stage on top of this bill's
        // grand total, not included in the figure above.
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell(0, 5, 'Note: amounts above do not include Sales Tax. Tax, where applicable, is calculated on the invoiced grand total and shown on the Invoice.', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Ln(2);

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