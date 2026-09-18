<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\DailyJob;
use App\Models\DeliveryChallan;
use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delivery Challan (DC) — item 7. Standalone entity, creatable BEFORE any
 * job's DETAILS exist. Since item 1, creating a DC also auto-creates a
 * "pending" Direct job at the same time (job_no + status='incomplete' only
 * — see store()) so every DC always has a job to show against it; that
 * job's actual vehicle/route/rates get filled in later, from the Edit Job
 * screen, same as any other incomplete job.
 *
 * The DC itself is later ASSIGNED to a specific vehicle-row on a Daily Job
 * by entering/selecting its DC# (see DailyJobController — only DCs not yet
 * assigned to a vehicle are offered there, via the unlinked() endpoint
 * below). See DeliveryChallan::dailyJob()/vehicleLine() and the
 * 2026_09_11_000001 migration's docblock for how the job-header-level link
 * (set here, at creation) differs from the vehicle-row-level one (set once
 * a vehicle is actually picked).
 *
 * This is a separate module from the legacy per-job DC fields that still
 * live on daily_jobs (see DailyJobController::saveDc/printDc) — those
 * remain exactly as they were for jobs created before this change.
 */
class DeliveryChallanController extends Controller
{
    public function index(Request $request)
    {
        $query = DeliveryChallan::with(['customer', 'port', 'vehicleLine.dailyJob', 'dailyJob']);

        if ($request->filled('customer_id') && $request->customer_id !== 'all') {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('linked') && $request->linked !== 'all') {
            $request->linked === 'linked'
                ? $query->whereHas('vehicleLine')
                : $query->whereDoesntHave('vehicleLine');
        }

        $challans = $query->latest('dc_date')->latest('id')->get();
        $customers = ChartOfAccounts::customers()->orderBy('name')->get();
        $ports = Port::where('is_active', true)->orderBy('name')->get();

        return view('delivery_challans.index', compact('challans', 'customers', 'ports'));
    }

    // Not used as a page — Add/Edit are modals on the index view, same
    // convention as the other Fleet Setup master-data modules (Vehicle,
    // OurCompany, VehicleRoute). Kept only so the generic module route loop
    // in routes/web.php has something to bind to.
    public function create()
    {
        return redirect()->route('delivery-challans.index');
    }

    private function nextDcNo(): string
    {
        $last = DeliveryChallan::withTrashed()
            ->where('dc_no', 'like', 'DC-%')
            ->pluck('dc_no')
            ->map(fn ($no) => (int) substr($no, 3))
            ->sort()
            ->last();

        return 'DC-' . str_pad(($last ?? 0) + 1, 6, '0', STR_PAD_LEFT);
    }

    // Duplicated from DailyJobController — same convention already used for
    // nextDcNo() there (that controller has its own copy for the legacy
    // per-job DC flow). Keeping each controller's numbering helper local
    // avoids a cross-controller dependency for a one-line sequence lookup.
    private function nextJobNo(): string
    {
        $last = DailyJob::withTrashed()
            ->where('job_no', 'like', 'DJ-%')
            ->pluck('job_no')
            ->map(fn ($no) => (int) substr($no, 3))
            ->sort()
            ->last();

        return 'DJ-' . str_pad(($last ?? 0) + 1, 6, '0', STR_PAD_LEFT);
    }

    private function rules(): array
    {
        return [
            'dc_date'           => 'required|date',
            'customer_id'       => 'required|exists:chart_of_accounts,id',
            'port_id'           => 'nullable|exists:ports,id',
            'clearing_agent'    => 'nullable|string|max:255',
            'unit'              => 'nullable|string|max:100',
            'bl_no'             => 'nullable|string|max:100',
            'container_no'      => 'nullable|string|max:100',
            'quantity'          => 'nullable|string|max:100',
            'item_description'  => 'nullable|string',
            'truck_no'          => 'nullable|string|max:100',
            'remarks'           => 'nullable|string|max:1000',
        ];
    }

    public function store(Request $request)
    {
        try {
            Log::info('[DeliveryChallan] Store called', ['user_id' => auth()->id()]);

            $data = $request->validate($this->rules());

            // Item 1 (round 2) — whether to also spin up a pending job is now
            // the user's choice, not automatic. The Create DC form submits
            // this as a hidden+checkbox pair (0 when unchecked, 1 when
            // checked — see delivery_challans/index.blade.php), so the key
            // is always present; the true default only covers a stale
            // cached form from before this field existed.
            $createJob = $request->boolean('create_job', true);

            $dc = DB::transaction(function () use ($data, $createJob) {
                $dc = DeliveryChallan::create(array_merge($data, [
                    'dc_no'      => $this->nextDcNo(),
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ]));

                if ($createJob) {
                    // Item 1 — creating a DC can also create its pending
                    // Direct job right away: job_no only, status='incomplete'.
                    // Only the handful of fields the DC itself already
                    // carries (date, customer) are copied across —
                    // everything else (vehicle, route, rates) is
                    // deliberately left unset for whoever fills in the
                    // job's basic details next (see
                    // DailyJobController::edit()/_form.blade.php's pendingDc
                    // handling). Not run through DailyJobController::persist()
                    // since there is no form submission to validate here —
                    // this is a direct, minimal insert.
                    $job = DailyJob::create([
                        'job_no'          => $this->nextJobNo(),
                        'job_type'        => 'direct',
                        'status'          => 'incomplete',
                        'date'            => $dc->dc_date,
                        'customer_id'     => $dc->customer_id,
                        'trip_plan_total' => 0,
                        'job_total'       => 0,
                        'created_by'      => auth()->id(),
                        'updated_by'      => auth()->id(),
                    ]);

                    $dc->update(['daily_job_id' => $job->id]);
                }

                return $dc;
            });

            $message = $dc->daily_job_id
                ? "Delivery Challan {$dc->dc_no} created — pending job {$dc->dailyJob->job_no} was created with it. Fill in its vehicle/route from Daily Jobs > Edit when ready."
                : "Delivery Challan {$dc->dc_no} created (no job). Link it to an existing job's vehicle from Daily Jobs > Edit when ready.";

            return redirect()->route('delivery-challans.index')->with('success', $message);

        } catch (\Throwable $e) {
            Log::error('[DeliveryChallan] Store error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    // Returns JSON for the edit modal AJAX call
    public function edit($id)
    {
        $dc = DeliveryChallan::findOrFail($id);
        return response()->json($dc);
    }

    public function update(Request $request, $id)
    {
        try {
            $dc = DeliveryChallan::findOrFail($id);

            $data = $request->validate($this->rules());

            $dc->update(array_merge($data, ['updated_by' => auth()->id()]));

            Log::info('[DeliveryChallan] Updated', ['id' => $id, 'user' => auth()->id()]);

            return redirect()->route('delivery-challans.index')
                ->with('success', "Delivery Challan {$dc->dc_no} updated successfully.");

        } catch (\Throwable $e) {
            Log::error('[DeliveryChallan] Update error', ['message' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $dc = DeliveryChallan::with(['customer', 'port', 'vehicleLine.dailyJob'])->findOrFail($id);
        return response()->json($dc);
    }

    public function destroy($id)
    {
        try {
            $dc = DeliveryChallan::with('dailyJob.vehicles')->findOrFail($id);

            if ($dc->vehicleLine()->exists()) {
                return redirect()->back()->with('error', "Delivery Challan {$dc->dc_no} is linked to a job and cannot be deleted. Unlink it from the job first.");
            }

            DB::transaction(function () use ($dc) {
                // Item 1's auto-created pending job has no accounting weight
                // of its own until a vehicle is actually added to it (the
                // vehicleLine guard above already rules that case out) — so
                // it's safe, and expected, to remove it together with its
                // DC rather than leave a useless empty "incomplete" job
                // behind. A job that already has OTHER vehicle-rows besides
                // this DC's own (e.g. it was created via "Add Direct Job"
                // and this DC was picked afterwards) is left alone — only a
                // job with zero vehicle-rows is cleaned up here.
                if ($dc->dailyJob && $dc->dailyJob->vehicles->isEmpty()) {
                    $dc->dailyJob->delete();
                }

                $dc->delete();
            });

            return redirect()->route('delivery-challans.index')->with('success', 'Delivery Challan deleted successfully.');

        } catch (\Throwable $e) {
            Log::error('[DeliveryChallan] Destroy error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    // AJAX: DCs available to assign to a vehicle-row when creating/editing a
    // Daily Job — "show only unassigned DC# in dropdown for linking" (item
    // 7), i.e. DCs with no vehicleLine yet. Since item 1, that now includes
    // every DC whose own auto-created pending job hasn't had a vehicle
    // picked for it yet — which is exactly what should show up (and get
    // pre-selected — see _form.blade.php's pendingDc handling) for THIS
    // job's own vehicle rows.
    //
    // A DC pending against a DIFFERENT job (job_id not passed, or passed
    // but not matching) is excluded — it belongs to that other job's own
    // pending workflow, not up for grabs here. Pass job_id (the job
    // currently being created/edited, if any) so its own pending DC is
    // still included even though it already has a daily_job_id set.
    public function unlinked(Request $request)
    {
        $query = DeliveryChallan::whereDoesntHave('vehicleLine')
            ->where(function ($q) use ($request) {
                $q->whereNull('daily_job_id');
                if ($request->filled('job_id')) {
                    $q->orWhere('daily_job_id', $request->job_id);
                }
            })
            ->with('customer');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $challans = $query->orderByDesc('dc_date')->orderByDesc('id')->get()
            ->map(fn ($dc) => [
                'id'          => $dc->id,
                'dc_no'       => $dc->dc_no,
                'dc_date'     => $dc->dc_date->format('d-m-Y'),
                'customer'    => $dc->customer->name ?? '—',
            ]);

        return response()->json($challans);
    }

    // Print — both copies (Customer Copy + Company Copy) stacked on ONE page
    // rather than 2 separate pages, to save paper (item 6). The Company
    // Copy's vertical offset is computed from wherever the Customer Copy's
    // content actually finished (via GetY()) instead of a hard-coded guess,
    // so this keeps working if renderDcPage()'s content height ever changes.
    // Auto page-break is turned off — TCPDF's default ~25mm bottom-margin
    // auto-break would otherwise push the tail of the Company Copy onto an
    // unwanted 3rd page once both copies' combined height gets close to a
    // full A4 page.
    public function print($id)
    {
        $dc = DeliveryChallan::with(['customer', 'port', 'vehicleLine.dailyJob.vehicle', 'dailyJob', 'creator'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('MMMTS');
        $pdf->SetAuthor('M M Logistics');
        $pdf->SetTitle('Delivery Challan ' . $dc->dc_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->setCellPadding(1.5);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();

        $this->renderDcPage($pdf, $dc, 'CUSTOMER COPY', 0);

        $cutY = $pdf->GetY() + 5;
        $pdf->SetLineStyle(['width' => 0.2, 'dash' => '2,2', 'color' => [140, 140, 140]]);
        $pdf->Line(10, $cutY, 200, $cutY);
        $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0, 'color' => [0, 0, 0]]);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(140, 140, 140);
        $pdf->SetXY(10, $cutY - 3);
        $pdf->Cell(190, 4, '- - - - - - - - - - - - - - - - - -  C U T   H E R E  - - - - - - - - - - - - - - - - - -', 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);

        $this->renderDcPage($pdf, $dc, 'COMPANY COPY', $cutY + 5);

        return $pdf->Output('dc_' . $dc->dc_no . '.pdf', 'I');
    }

    private function renderDcPage(\TCPDF $pdf, DeliveryChallan $dc, string $copyLabel, float $yOffset = 0): void
    {

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(40, $yOffset);
        $pdf->Cell(0, 7, 'M M LOGISTICS', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(40, $yOffset + 17);
        $pdf->Cell(0, 5, 'Room No 301, 303, 305, 307, 3rd Floor, Custom Trade Tower,', 0, 1, 'L');
        $pdf->SetXY(40, $yOffset + 22);
        $pdf->Cell(0, 5, 'KPT Stadium, Kharadar, Karachi', 0, 1, 'L');

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(140, $yOffset + 10);
        $pdf->Cell(60, 6, 'DELIVERY CHALLAN', 0, 1, 'R');
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(140, $yOffset + 17);
        $pdf->Cell(60, 6, $copyLabel, 0, 1, 'R');

        // yOffset applied only up to here — Line() doesn't move the cursor,
        // but everything below (writeHTML tables, Ln(), the GetY()-based
        // signature block) advances relative to whatever Y the cell calls
        // above already left the cursor at, so it naturally stays offset
        // without needing $yOffset added to every subsequent call.
        $pdf->Line(10, $yOffset + 30, 200, $yOffset + 30);
        $pdf->Ln(9);

        $pdf->SetFont('helvetica', '', 10);
        $vehicleLine = $dc->vehicleLine;
        // Prefer the vehicle-assigned job (the normal case); fall back to
        // this DC's own pending job header (item 1) so a DC printed before
        // any vehicle has been picked for it still shows its Job No.
        // rather than "not linked to a job yet".
        $job = $vehicleLine?->dailyJob ?? $dc->dailyJob;

        $headHtml = '
        <table cellpadding="3" cellspacing="0" width="100%">
            <tr>
                <td width="50%"><b>DC No.:</b> ' . e($dc->dc_no) . '</td>
                <td width="50%" align="right"><b>Date:</b> ' . $dc->dc_date->format('d-m-Y') . '</td>
            </tr>
        </table>';
        $pdf->writeHTML($headHtml, true, false, false, false, '');

        $detailsHtml = '
        <table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:10px;">
            <tr>
                <td width="15%"><b>Consignee</b></td>
                <td width="35%">' . e($dc->customer->name ?? '') . '</td>
                <td width="15%"><b>Clearing Agent</b></td>
                <td width="35%">' . e($dc->clearing_agent ?? '') . '</td>
            </tr>
            <tr>
                <td><b>Port</b></td>
                <td>' . e($dc->port->name ?? '') . '</td>
                <td><b>Job No.</b></td>
                <td>' . e($job->job_no ?? '— not linked to a job yet —') . '</td>
            </tr>
            <tr>
                <td><b>Unit</b></td>
                <td>' . e($dc->unit ?? '') . '</td>
                <td><b>BL #</b></td>
                <td>' . e($dc->bl_no ?? '') . '</td>
            </tr>
            <tr>
                <td><b>Container No.</b></td>
                <td>' . e($dc->container_no ?? '') . '</td>
                <td><b>Created By</b></td>
                <td>' . e($dc->creator->name ?? '—') . '</td>
            </tr>
        </table>';
        $pdf->writeHTML($detailsHtml, true, false, true, false, '');
        $pdf->Ln(3);

        $itemHtml = '
        <table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:10px;">
            <tr style="background-color:#f5f5f5; font-weight:bold;">
                <th width="20%">Quantity</th>
                <th width="55%">Item Description</th>
                <th width="25%">Truck No.</th>
            </tr>
            <tr>
                <td>' . e($dc->quantity ?? '') . '</td>
                <td>' . e($dc->item_description ?? '') . '</td>
                <td>' . e($dc->truck_no ?? ($vehicleLine?->vehicle?->vehicle_no ?? '')) . '</td>
            </tr>
        </table>';
        $pdf->writeHTML($itemHtml, true, false, true, false, '');
        $pdf->Ln(3);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'FOR COMPANY USE ONLY', 0, 1, 'L');
        $gateHtml = '
        <table border="0.3" cellpadding="6" cellspacing="0" width="100%" style="font-size:10px;">
            <tr>
                <td width="50%"><b>Gate In:</b> ________________________</td>
                <td width="50%"><b>Gate Out:</b> ________________________</td>
            </tr>
        </table>';
        $pdf->writeHTML($gateHtml, true, false, true, false, '');

        $pdf->Ln(11);
        $yPos = $pdf->GetY();
        $lineWidth = 50;
        $pdf->Line(20, $yPos, 20 + $lineWidth, $yPos);
        $pdf->Line(130, $yPos, 130 + $lineWidth, $yPos);
        $pdf->SetXY(20, $yPos + 2);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell($lineWidth, 6, 'Driver / Received By', 0, 0, 'C');
        $pdf->SetXY(130, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'WITH COMPANY STAMP', 0, 0, 'C');

        // The two Cell() calls above pass ln=0 (cursor doesn't advance to a
        // new line), so GetY() would otherwise still report $yPos+2 — short
        // of where this signature row actually ends visually. print()
        // relies on GetY() right after this call to know where to place the
        // CUT HERE divider / the next copy, so it must reflect the true
        // bottom of this content or the divider ends up overlapping this
        // signature line.
        $pdf->SetY($yPos + 2 + 6);
    }
}