<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\CustomerLocation;
use App\Models\DailyJob;
use App\Models\Port;
use App\Models\Vehicle;
use App\Models\VehicleRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DailyJobController extends Controller
{
    public function index(Request $request)
    {
        $query = DailyJob::with(['vehicle', 'customer', 'route', 'vendor', 'pickupPort', 'dropoffPort']);

        $from = $request->filled('from_date') ? $request->from_date : now()->startOfMonth()->toDateString();
        $to   = $request->filled('to_date') ? $request->to_date : now()->toDateString();
        $query->whereBetween('date', [$from, $to]);

        if ($request->filled('customer_id') && $request->customer_id !== 'all') {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('billed') && $request->billed !== 'all') {
            $request->billed === 'billed' ? $query->whereNotNull('bill_id') : $query->whereNull('bill_id');
        }

        $jobs = $query->orderByDesc('date')->orderByDesc('id')->get();

        $customers = ChartOfAccounts::customers()->orderBy('name')->get();

        return view('daily_jobs.index', compact('jobs', 'customers', 'from', 'to'));
    }

    private function formData()
    {
        return [
            'vehicles'         => Vehicle::where('is_active', true)->orderBy('name')->get(),
            'customers'        => ChartOfAccounts::customers()->orderBy('name')->get(),
            'vendors'          => ChartOfAccounts::vendors()->orderBy('name')->get(),
            'routes'           => VehicleRoute::where('is_active', true)->orderBy('name')->get(),
            'ports'            => Port::where('is_active', true)->orderBy('name')->get(),
            'customerLocations' => CustomerLocation::where('is_active', true)->get(['id', 'customer_id', 'location_name']),
        ];
    }

    public function create(Request $request)
    {
        $type = $request->query('type', 'direct');
        $type = in_array($type, ['direct', 'party_to_party'], true) ? $type : 'direct';

        return view('daily_jobs.create', array_merge(['type' => $type], $this->formData()));
    }

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
            'date'                     => 'required|date',
            'vehicle_id'               => 'required|exists:vehicles,id',
            'customer_id'              => 'required|exists:chart_of_accounts,id',
            'route_id'                 => 'required|exists:vehicle_routes,id',
            'container_no'             => 'nullable|string|max:100',
            'item_description'         => 'nullable|string|max:1000',
            'rent'                     => 'nullable|numeric|min:0',
            'labour_charges'           => 'nullable|numeric|min:0',
            'yard_charges'             => 'nullable|numeric|min:0',
            'kanta_charges'            => 'nullable|numeric|min:0',

            'trip_type'                => 'required|in:one_way,two_way',
            'pickup_port_id'           => 'required|exists:ports,id',
            'pickup_charges'           => 'nullable|numeric|min:0',
            'destination_location_id'  => 'nullable|required_if:trip_type,two_way|exists:customer_locations,id',
            'destination_charges'      => 'nullable|numeric|min:0',
            'dropoff_port_id'          => 'required|exists:ports,id',
            'dropoff_charges'          => 'nullable|numeric|min:0',

            'per_day_first_charges'    => 'nullable|numeric|min:0',
            'per_day_next_rate'        => 'nullable|numeric|min:0',
            'per_day_extra_days'       => 'nullable|integer|min:0',

            'remarks'                  => 'nullable|string|max:1000',

            'extra_from_port_id'       => 'nullable|array',
            'extra_from_port_id.*'     => 'nullable|exists:ports,id',
            'extra_to_port_id'         => 'nullable|array',
            'extra_to_port_id.*'       => 'nullable|exists:ports,id',
            'extra_charges'            => 'nullable|array',
            'extra_charges.*'          => 'nullable|numeric|min:0',
        ];
    }

    private function ptyRules(): array
    {
        return [
            'date'             => 'required|date',
            'vendor_id'        => 'required|exists:chart_of_accounts,id',
            'customer_id'      => 'required|exists:chart_of_accounts,id',
            'pty_vehicle_no'   => 'required|string|max:50',
            'pty_destination'  => 'required|string|max:255',
            'pty_size'         => 'nullable|string|max:50',
            'pty_cost'         => 'required|numeric|min:0',
            'pty_sale_amount'  => 'required|numeric|min:0',
            'pty_advance'      => 'nullable|numeric|min:0',
            'pty_guarantee'    => 'nullable|numeric|min:0',
            'remarks'          => 'nullable|string|max:1000',
        ];
    }

    // Shared compute + persist logic for store/update — branches on job_type
    private function persist(Request $request, ?DailyJob $job = null)
    {
        $jobType = $job->job_type ?? $request->input('job_type', 'direct');
        $jobType = in_array($jobType, ['direct', 'party_to_party'], true) ? $jobType : 'direct';

        if ($jobType === 'party_to_party') {
            return $this->persistPartyToParty($request, $job);
        }

        $data = $request->validate($this->rules());

        $tripType = $data['trip_type'];
        $pickup      = (float) ($data['pickup_charges'] ?? 0);
        $destination = $tripType === 'two_way' ? (float) ($data['destination_charges'] ?? 0) : 0;
        $dropoff     = (float) ($data['dropoff_charges'] ?? 0);
        $tripPlanTotal = round($pickup + $destination + $dropoff, 2);

        $perDayFirst = (float) ($data['per_day_first_charges'] ?? 0);
        $perDayNext  = (float) ($data['per_day_next_rate'] ?? 0);
        $extraDays   = (int) ($data['per_day_extra_days'] ?? 0);
        $perDayTotal = round($perDayFirst + ($perDayNext * $extraDays), 2);

        // Extra Port Charges — zip the 3 parallel arrays into rows, drop incomplete ones
        $extraRows = [];
        $fromPorts = $request->input('extra_from_port_id', []);
        $toPorts   = $request->input('extra_to_port_id', []);
        $charges   = $request->input('extra_charges', []);
        $extraTotal = 0;
        foreach ($fromPorts as $i => $fromPortId) {
            $toPortId = $toPorts[$i] ?? null;
            $charge   = (float) ($charges[$i] ?? 0);
            if (!$fromPortId || !$toPortId) {
                continue;
            }
            $extraRows[] = [
                'from_port_id' => $fromPortId,
                'to_port_id'   => $toPortId,
                'charges'      => $charge,
            ];
            $extraTotal += $charge;
        }
        $extraTotal = round($extraTotal, 2);

        $rent    = (float) ($data['rent'] ?? 0);
        $labour  = (float) ($data['labour_charges'] ?? 0);
        $yard    = (float) ($data['yard_charges'] ?? 0);
        $kanta   = (float) ($data['kanta_charges'] ?? 0);
        $jobTotal = round($rent + $labour + $yard + $kanta + $tripPlanTotal + $extraTotal + $perDayTotal, 2);

        $payload = [
            'date'                     => $data['date'],
            'vehicle_id'               => $data['vehicle_id'],
            'customer_id'              => $data['customer_id'],
            'route_id'                 => $data['route_id'],
            'container_no'             => $data['container_no'] ?? null,
            'item_description'         => $data['item_description'] ?? null,
            'rent'                     => $rent,
            'labour_charges'           => $labour,
            'yard_charges'             => $yard,
            'kanta_charges'            => $kanta,

            'trip_type'                => $tripType,
            'pickup_port_id'           => $data['pickup_port_id'],
            'pickup_charges'           => $pickup,
            'destination_location_id'  => $tripType === 'two_way' ? $data['destination_location_id'] : null,
            'destination_charges'      => $destination,
            'dropoff_port_id'          => $data['dropoff_port_id'],
            'dropoff_charges'          => $dropoff,
            'trip_plan_total'          => $tripPlanTotal,

            'per_day_first_charges'    => $perDayFirst,
            'per_day_next_rate'        => $perDayNext,
            'per_day_extra_days'       => $extraDays,
            'per_day_total'            => $perDayTotal,

            'extra_port_charges_total' => $extraTotal,
            'job_total'                => $jobTotal,
            'remarks'                  => $data['remarks'] ?? null,
            'updated_by'               => auth()->id(),
        ];

        return DB::transaction(function () use ($job, $payload, $extraRows) {
            if ($job) {
                $job->update($payload);
                $job->extraPortCharges()->delete();
            } else {
                $payload['job_no']     = $this->nextJobNo();
                $payload['job_type']   = 'direct';
                $payload['created_by'] = auth()->id();
                $job = DailyJob::create($payload);
            }

            foreach ($extraRows as $row) {
                $job->extraPortCharges()->create($row);
            }

            return $job;
        });
    }

    // Party-to-Party (Vendor to Customer directly) — simple ledger-style row,
    // no vehicle/route/trip-plan masters involved.
    private function persistPartyToParty(Request $request, ?DailyJob $job = null)
    {
        $data = $request->validate($this->ptyRules());

        // cost   = what we owe the vendor (vendor payable ledger — advance/
        //          guarantee/balance are all worked out against this).
        // sale   = what we bill the customer — this is what flows into
        //          job_total / Bill / Invoice, NOT the cost.
        $cost      = (float) $data['pty_cost'];
        $sale      = (float) $data['pty_sale_amount'];
        $advance   = (float) ($data['pty_advance'] ?? 0);
        $guarantee = (float) ($data['pty_guarantee'] ?? 0);
        $balance   = round($cost - $advance - $guarantee, 2);

        $payload = [
            'date'            => $data['date'],
            'vendor_id'       => $data['vendor_id'],
            'customer_id'     => $data['customer_id'],
            'pty_vehicle_no'  => $data['pty_vehicle_no'],
            'pty_destination' => $data['pty_destination'],
            'pty_size'        => $data['pty_size'] ?? null,
            'pty_cost'        => $cost,
            'pty_sale_amount' => $sale,
            'pty_advance'     => $advance,
            'pty_guarantee'   => $guarantee,
            'pty_balance'     => $balance,
            // No trip-plan/tax portion for party-to-party — the sale amount
            // is carried as job_total / "other charges" (see DailyJob::getOtherChargesTotalAttribute).
            'trip_plan_total' => 0,
            'job_total'       => $sale,
            'remarks'         => $data['remarks'] ?? null,
            'updated_by'      => auth()->id(),
        ];

        return DB::transaction(function () use ($job, $payload) {
            if ($job) {
                $job->update($payload);
                return $job;
            }

            $payload['job_no']     = $this->nextJobNo();
            $payload['job_type']   = 'party_to_party';
            $payload['created_by'] = auth()->id();

            return DailyJob::create($payload);
        });
    }

    public function store(Request $request)
    {
        try {
            Log::info('[DailyJob] Store called', ['user_id' => auth()->id()]);

            $job = $this->persist($request);

            return redirect()->route('daily-jobs.index')
                ->with('success', "Daily job {$job->job_no} added successfully.");

        } catch (\Throwable $e) {
            Log::error('[DailyJob] Store error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $job = DailyJob::with('extraPortCharges')->findOrFail($id);

        if ($job->bill_id) {
            return redirect()->route('daily-jobs.index')
                ->with('error', 'This job is already on a bill and can no longer be edited.');
        }

        return view('daily_jobs.edit', array_merge(['job' => $job], $this->formData()));
    }

    public function update(Request $request, $id)
    {
        try {
            $job = DailyJob::findOrFail($id);

            if ($job->bill_id) {
                return redirect()->back()->with('error', 'This job is already on a bill and can no longer be edited.');
            }

            $this->persist($request, $job);

            Log::info('[DailyJob] Updated', ['id' => $id, 'user' => auth()->id()]);

            return redirect()->route('daily-jobs.index')->with('success', "Daily job {$job->job_no} updated successfully.");

        } catch (\Throwable $e) {
            Log::error('[DailyJob] Update error', ['message' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $job = DailyJob::with(['vehicle', 'customer', 'route', 'vendor', 'pickupPort', 'dropoffPort', 'destinationLocation', 'extraPortCharges'])->findOrFail($id);
        return response()->json($job);
    }

    public function destroy($id)
    {
        try {
            $job = DailyJob::findOrFail($id);

            if ($job->bill_id) {
                return redirect()->back()->with('error', 'This job is already on a bill and cannot be deleted.');
            }

            $job->delete();

            return redirect()->route('daily-jobs.index')->with('success', 'Daily job deleted successfully.');

        } catch (\Throwable $e) {
            Log::error('[DailyJob] Destroy error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    // Print — a single job slip. Branches by job_type since Direct and
    // Party-to-Party jobs carry completely different data.
    public function print($id)
    {
        $job = DailyJob::with([
            'vehicle', 'customer', 'vendor', 'route',
            'pickupPort', 'dropoffPort', 'destinationLocation',
            'extraPortCharges.fromPort', 'extraPortCharges.toPort',
        ])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('MMMTS');
        $pdf->SetAuthor('Your Company');
        $pdf->SetTitle('Job ' . $job->job_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 8, 35);
        }

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(120, 12);
        $pdf->Cell(80, 8, 'JOB SLIP', 0, 1, 'R');

        $pdf->Ln(5);
        $pdf->SetFont('helvetica', '', 10);

        $infoHtml = '
        <table cellpadding="3" cellspacing="0" width="100%">
            <tr>
                <td width="60%">
                    <b>' . e($job->customer->name ?? '') . '</b><br>
                    ' . e($job->customer->address ?? '') . '
                </td>
                <td width="40%">
                    <table border="1" cellpadding="4" cellspacing="0" style="font-size:10px;">
                        <tr><td width="40%"><b>Job No.</b></td><td width="60%">' . e($job->job_no) . '</td></tr>
                        <tr><td width="40%"><b>Date</b></td><td width="60%">' . $job->date->format('d-m-Y') . '</td></tr>
                        <tr><td width="40%"><b>Type</b></td><td width="60%">' . ($job->job_type === 'party_to_party' ? 'Party-to-Party' : 'Direct') . '</td></tr>
                    </table>
                </td>
            </tr>
        </table>';
        $pdf->writeHTML($infoHtml, true, false, false, false, '');
        $pdf->Ln(3);

        if ($job->job_type === 'party_to_party') {
            $html = '
            <table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:10px;">
                <tr><td width="30%"><b>Vendor</b></td><td width="70%">' . e($job->vendor->name ?? '') . '</td></tr>
                <tr><td><b>Vendor Vehicle #</b></td><td>' . e($job->pty_vehicle_no ?? '') . '</td></tr>
                <tr><td><b>Destination</b></td><td>' . e($job->pty_destination ?? '') . '</td></tr>
                <tr><td><b>Size</b></td><td>' . e($job->pty_size ?? '') . '</td></tr>
            </table>';
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Ln(3);

            $html2 = '
            <table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="text-align:right;font-size:10px;">
                <tr style="background-color:#f5f5f5;font-weight:bold;">
                    <td width="20%" align="left">Vendor Cost</td>
                    <td width="20%" align="left">Sale to Customer</td>
                    <td width="20%" align="left">Advance</td>
                    <td width="20%" align="left">Guarantee</td>
                    <td width="20%" align="left">Balance Payable</td>
                </tr>
                <tr>
                    <td>' . number_format($job->pty_cost, 2) . '</td>
                    <td>' . number_format($job->pty_sale_amount, 2) . '</td>
                    <td>' . number_format($job->pty_advance, 2) . '</td>
                    <td>' . number_format($job->pty_guarantee, 2) . '</td>
                    <td>' . number_format($job->pty_balance, 2) . '</td>
                </tr>
            </table>';
            $pdf->writeHTML($html2, true, false, true, false, '');
            $pdf->Ln(3);

            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 8, 'Profit: ' . number_format($job->pty_profit, 2), 0, 1, 'R');
        } else {
            $html = '
            <table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:10px;">
                <tr><td width="20%"><b>Vehicle</b></td><td width="30%">' . e($job->vehicle->name ?? '') . ' (' . e($job->vehicle->vehicle_no ?? '') . ')</td>
                    <td width="20%"><b>Route</b></td><td width="30%">' . e($job->route->name ?? '') . '</td></tr>
                <tr><td><b>Container #</b></td><td>' . e($job->container_no ?? '') . '</td>
                    <td><b>Trip Type</b></td><td>' . ($job->trip_type === 'two_way' ? 'Two Way' : 'One Way') . '</td></tr>
                <tr><td colspan="4"><b>Item Description:</b> ' . e($job->item_description ?? '') . '</td></tr>
            </table>';
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Ln(3);

            $tripHtml = '
            <table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:10px;text-align:center;">
                <tr style="background-color:#f5f5f5;font-weight:bold;">
                    <th>Pickup Port</th><th>Pickup Charges</th>
                    <th>Destination</th><th>Destination Charges</th>
                    <th>Dropoff Port</th><th>Dropoff Charges</th>
                </tr>
                <tr>
                    <td>' . e($job->pickupPort->name ?? '') . '</td><td>' . number_format($job->pickup_charges, 2) . '</td>
                    <td>' . e($job->destinationLocation->location_name ?? '—') . '</td><td>' . number_format($job->destination_charges, 2) . '</td>
                    <td>' . e($job->dropoffPort->name ?? '') . '</td><td>' . number_format($job->dropoff_charges, 2) . '</td>
                </tr>
            </table>';
            $pdf->writeHTML($tripHtml, true, false, true, false, '');
            $pdf->Ln(3);

            if ($job->extraPortCharges->count()) {
                $extraHtml = '<table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:10px;">
                    <tr style="background-color:#f5f5f5;font-weight:bold;"><th>From Port</th><th>To Port</th><th>Charges</th></tr>';
                foreach ($job->extraPortCharges as $epc) {
                    $extraHtml .= '<tr><td>' . e($epc->fromPort->name ?? '') . '</td><td>' . e($epc->toPort->name ?? '') . '</td><td align="right">' . number_format($epc->charges, 2) . '</td></tr>';
                }
                $extraHtml .= '</table>';
                $pdf->writeHTML($extraHtml, true, false, true, false, '');
                $pdf->Ln(3);
            }

            $sumHtml = '
            <table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="text-align:right;font-size:10px;">
                <tr><td width="80%" align="left">Rent</td><td width="20%">' . number_format($job->rent, 2) . '</td></tr>
                <tr><td align="left">Labour Charges</td><td>' . number_format($job->labour_charges, 2) . '</td></tr>
                <tr><td align="left">Yard Charges</td><td>' . number_format($job->yard_charges, 2) . '</td></tr>
                <tr><td align="left">Weight Bridge (Kanta)</td><td>' . number_format($job->kanta_charges, 2) . '</td></tr>
                <tr><td align="left">Trip Plan Total</td><td>' . number_format($job->trip_plan_total, 2) . '</td></tr>
                <tr><td align="left">Extra Port Charges</td><td>' . number_format($job->extra_port_charges_total, 2) . '</td></tr>
                <tr><td align="left">Per Day Charges</td><td>' . number_format($job->per_day_total, 2) . '</td></tr>
                <tr style="background-color:#f5f5f5;font-weight:bold;"><td align="left">Job Total</td><td>' . number_format($job->job_total, 2) . '</td></tr>
            </table>';
            $pdf->writeHTML($sumHtml, true, false, true, false, '');
        }

        $pdf->Ln(3);
        if (!empty($job->remarks)) {
            $pdf->writeHTML('<b>Remarks:</b><br><span style="font-size:10px;">' . nl2br(e($job->remarks)) . '</span>', true, false, true, false, '');
        }

        $pdf->Ln(18);
        $yPos = $pdf->GetY();
        $lineWidth = 40;
        $pdf->Line(28, $yPos, 28 + $lineWidth, $yPos);
        $pdf->Line(130, $yPos, 130 + $lineWidth, $yPos);
        $pdf->SetXY(28, $yPos + 2);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell($lineWidth, 6, 'Prepared By', 0, 0, 'C');
        $pdf->SetXY(130, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output('job_' . $job->job_no . '.pdf', 'I');
    }

    // ── Delivery Challan (DC) ─────────────────────────────────────
    // Not a separate module — just a printable proof-of-delivery document
    // against a single Direct job, so it lives as a handful of fields on
    // the job itself plus a save + print action, gated by the existing
    // daily_jobs.edit / daily_jobs.print permissions.

    private function nextDcNo(): string
    {
        $last = DailyJob::withTrashed()
            ->where('dc_no', 'like', 'DC-%')
            ->pluck('dc_no')
            ->map(fn ($no) => (int) substr($no, 3))
            ->sort()
            ->last();

        return 'DC-' . str_pad(($last ?? 0) + 1, 6, '0', STR_PAD_LEFT);
    }

    private function dcRules(): array
    {
        return [
            'dc_date'             => 'required|date',
            'dc_clearing_agent'   => 'nullable|string|max:255',
            'dc_unit'             => 'nullable|string|max:100',
            'dc_bl_no'            => 'nullable|string|max:100',
            'dc_container_no'     => 'nullable|string|max:100',
            'dc_quantity'         => 'nullable|string|max:100',
            'dc_item_description' => 'nullable|string',
            'dc_truck_no'         => 'nullable|string|max:100',
        ];
    }

    // Save (or update) the DC fields on a Direct job. Issues a dc_no the
    // first time; later saves just update the existing snapshot.
    public function saveDc(Request $request, $id)
    {
        try {
            $job = DailyJob::findOrFail($id);

            if ($job->job_type !== 'direct') {
                return redirect()->back()->with('error', 'Delivery Challans only apply to Direct jobs.');
            }

            $data = $request->validate($this->dcRules());

            $payload = $data;
            if (!$job->dc_no) {
                $payload['dc_no'] = $this->nextDcNo();
            }
            $payload['updated_by'] = auth()->id();

            $job->update($payload);

            return redirect()->route('daily-jobs.index')->with('success', "Delivery Challan {$job->dc_no} saved for job {$job->job_no}.");

        } catch (\Throwable $e) {
            Log::error('[DailyJob] Save DC error', ['message' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function printDc($id)
    {
        $job = DailyJob::with(['customer', 'vehicle', 'pickupPort', 'dropoffPort'])->findOrFail($id);

        if (!$job->dc_no) {
            abort(404, 'No Delivery Challan has been issued for this job yet.');
        }

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('MMMTS');
        $pdf->SetAuthor('M M Logistics');
        $pdf->SetTitle('Delivery Challan ' . $job->dc_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 8, 25);
        }

        // ── Letterhead ────────────────────────────────────────────
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(40, 10);
        $pdf->Cell(0, 7, 'M M LOGISTICS', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(40, 17);
        $pdf->Cell(0, 5, 'Room No 301, 303, 305, 307, 3rd Floor, Custom Trade Tower,', 0, 1, 'L');
        $pdf->SetXY(40, 22);
        $pdf->Cell(0, 5, 'KPT Stadium, Kharadar, Karachi', 0, 1, 'L');

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(140, 12);
        $pdf->Cell(60, 8, 'DELIVERY CHALLAN', 0, 1, 'R');

        $pdf->Line(10, 30, 200, 30);
        $pdf->Ln(12);

        // ── DC No. / Date ─────────────────────────────────────────
        $pdf->SetFont('helvetica', '', 10);
        $headHtml = '
        <table cellpadding="3" cellspacing="0" width="100%">
            <tr>
                <td width="50%"><b>DC No.:</b> ' . e($job->dc_no) . '</td>
                <td width="50%" align="right"><b>Date:</b> ' . ($job->dc_date ? $job->dc_date->format('d-m-Y') : '') . '</td>
            </tr>
        </table>';
        $pdf->writeHTML($headHtml, true, false, false, false, '');

        // ── Details block ────────────────────────────────────────
        $detailsHtml = '
        <table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:10px;">
            <tr>
                <td width="15%"><b>Consignee</b></td>
                <td width="35%">' . e($job->customer->name ?? '') . '</td>
                <td width="15%"><b>Clearing Agent</b></td>
                <td width="35%">' . e($job->dc_clearing_agent ?? '') . '</td>
            </tr>
            <tr>
                <td><b>Port (Pickup)</b></td>
                <td>' . e($job->pickupPort->name ?? '') . '</td>
                <td><b>Port (Dropoff)</b></td>
                <td>' . e($job->dropoffPort->name ?? '') . '</td>
            </tr>
            <tr>
                <td><b>Unit</b></td>
                <td>' . e($job->dc_unit ?? '') . '</td>
                <td><b>BL #</b></td>
                <td>' . e($job->dc_bl_no ?? '') . '</td>
            </tr>
            <tr>
                <td><b>Container No.</b></td>
                <td colspan="3">' . e($job->dc_container_no ?? '') . '</td>
            </tr>
        </table>';
        $pdf->writeHTML($detailsHtml, true, false, true, false, '');
        $pdf->Ln(3);

        // ── Quantity / Description / Truck No. ──────────────────
        $itemHtml = '
        <table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:10px;">
            <tr style="background-color:#f5f5f5; font-weight:bold;">
                <th width="20%">Quantity</th>
                <th width="55%">Item Description</th>
                <th width="25%">Truck No.</th>
            </tr>
            <tr>
                <td>' . e($job->dc_quantity ?? '') . '</td>
                <td>' . e($job->dc_item_description ?? '') . '</td>
                <td>' . e($job->dc_truck_no ?? '') . '</td>
            </tr>
        </table>';
        $pdf->writeHTML($itemHtml, true, false, true, false, '');
        $pdf->Ln(3);

        // ── For Company Use Only (Gate In / Gate Out — filled by hand) ──
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

        // ── Signature / stamp area ───────────────────────────────
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

        return $pdf->Output('dc_' . $job->dc_no . '.pdf', 'I');
    }
}