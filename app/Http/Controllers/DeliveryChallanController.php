<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\DeliveryChallan;
use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Delivery Challan (DC) — item 7. Standalone entity, creatable BEFORE any
 * job exists: pick a customer + a single port, fill the rest by hand. It
 * is later linked to a specific vehicle-row on a Daily Job by entering/
 * selecting its DC# (see DailyJobController — only UNLINKED DCs are
 * offered there, via the unlinked() endpoint below).
 *
 * This is a separate module from the legacy per-job DC fields that still
 * live on daily_jobs (see DailyJobController::saveDc/printDc) — those
 * remain exactly as they were for jobs created before this change.
 */
class DeliveryChallanController extends Controller
{
    public function index(Request $request)
    {
        $query = DeliveryChallan::with(['customer', 'port', 'vehicleLine.dailyJob']);

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

            $dc = DeliveryChallan::create(array_merge($data, [
                'dc_no'      => $this->nextDcNo(),
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]));

            return redirect()->route('delivery-challans.index')
                ->with('success', "Delivery Challan {$dc->dc_no} created successfully.");

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
            $dc = DeliveryChallan::findOrFail($id);

            if ($dc->vehicleLine()->exists()) {
                return redirect()->back()->with('error', "Delivery Challan {$dc->dc_no} is linked to a job and cannot be deleted. Unlink it from the job first.");
            }

            $dc->delete();

            return redirect()->route('delivery-challans.index')->with('success', 'Delivery Challan deleted successfully.');

        } catch (\Throwable $e) {
            Log::error('[DeliveryChallan] Destroy error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    // AJAX: unlinked DCs available to attach to a vehicle-row when creating/
    // editing a Daily Job — "show only unlinked DC# in dropdown for linking"
    // (item 7). Optionally scoped to a customer.
    public function unlinked(Request $request)
    {
        $query = DeliveryChallan::whereDoesntHave('vehicleLine')->with('customer');

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

    // Print — 2 copies in one PDF: Customer Copy then Company Copy (item 6).
    public function print($id)
    {
        $dc = DeliveryChallan::with(['customer', 'port', 'vehicleLine.dailyJob.vehicle'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('MMMTS');
        $pdf->SetAuthor('M M Logistics');
        $pdf->SetTitle('Delivery Challan ' . $dc->dc_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->setCellPadding(1.5);

        foreach (['CUSTOMER COPY', 'COMPANY COPY'] as $copyLabel) {
            $pdf->AddPage();
            $this->renderDcPage($pdf, $dc, $copyLabel);
        }

        return $pdf->Output('dc_' . $dc->dc_no . '.pdf', 'I');
    }

    private function renderDcPage(\TCPDF $pdf, DeliveryChallan $dc, string $copyLabel): void
    {
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(10, 10);
        $pdf->Cell(0, 7, 'M M LOGISTICS', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(40, 17);
        $pdf->Cell(0, 5, 'Room No 301, 303, 305, 307, 3rd Floor, Custom Trade Tower,', 0, 1, 'L');
        $pdf->SetXY(40, 22);
        $pdf->Cell(0, 5, 'KPT Stadium, Kharadar, Karachi', 0, 1, 'L');

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(140, 10);
        $pdf->Cell(60, 6, 'DELIVERY CHALLAN', 0, 1, 'R');
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(140, 17);
        $pdf->Cell(60, 6, $copyLabel, 0, 1, 'R');

        $pdf->Line(10, 30, 200, 30);
        $pdf->Ln(12);

        $pdf->SetFont('helvetica', '', 10);
        $vehicleLine = $dc->vehicleLine;
        $job = $vehicleLine?->dailyJob;

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
                <td colspan="3">' . e($dc->container_no ?? '') . '</td>
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

        $pdf->Ln(18);
        $yPos = $pdf->GetY();
        $lineWidth = 50;
        $pdf->Line(20, $yPos, 20 + $lineWidth, $yPos);
        $pdf->Line(130, $yPos, 130 + $lineWidth, $yPos);
        $pdf->SetXY(20, $yPos + 2);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell($lineWidth, 6, 'Driver / Received By', 0, 0, 'C');
        $pdf->SetXY(130, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'WITH COMPANY STAMP', 0, 0, 'C');
    }
}