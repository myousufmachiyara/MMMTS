<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\CustomerLocation;
use App\Models\DailyJob;
use App\Models\DailyJobVehicle;
use App\Models\DeliveryChallan;
use App\Models\Port;
use App\Models\Vehicle;
use App\Models\VehicleRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DailyJobController extends Controller
{
    // Item 11 — gates every RATE field (trip plan ports, rent/labour/yard/
    // kanta, retention charges, extra port charges, DC linking) and the
    // "mark complete" toggle. Someone without this can still create/edit a
    // job's BASIC fields only (date, customer, and per-vehicle vehicle/
    // route/container/description) — the job stays 'incomplete' until an
    // admin fills the rest in.
    private function canFillRates(): bool
    {
        return (bool) auth()->user()?->can('daily_jobs.fill_rates');
    }

    public function index(Request $request)
    {
        // pickupPort/dropoffPort (header-level) are only used by the legacy
        // DC modal below, for jobs created before the standalone Delivery
        // Challan module existed.
        $query = DailyJob::with(['vehicles.vehicle', 'vehicles.route', 'customer', 'vendor', 'pickupPort', 'dropoffPort']);

        $from = $request->filled('from_date') ? $request->from_date : now()->startOfMonth()->toDateString();
        $to   = $request->filled('to_date') ? $request->to_date : now()->toDateString();
        $query->whereBetween('date', [$from, $to]);

        if ($request->filled('customer_id') && $request->customer_id !== 'all') {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('billed') && $request->billed !== 'all') {
            $request->billed === 'billed' ? $query->whereNotNull('bill_id') : $query->whereNull('bill_id');
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $jobs = $query->orderByDesc('date')->orderByDesc('id')->get();

        $customers = ChartOfAccounts::customers()->orderBy('name')->get();

        return view('daily_jobs.index', compact('jobs', 'customers', 'from', 'to'));
    }

    private function formData()
    {
        return [
            'vehicles'          => Vehicle::where('is_active', true)->orderBy('name')->get(),
            'customers'         => ChartOfAccounts::customers()->orderBy('name')->get(),
            'vendors'           => ChartOfAccounts::vendors()->orderBy('name')->get(),
            'routes'            => VehicleRoute::where('is_active', true)->orderBy('name')->get(),
            'ports'             => Port::where('is_active', true)->orderBy('name')->get(),
            'customerLocations' => CustomerLocation::where('is_active', true)->get(['id', 'customer_id', 'location_name']),
            'canFillRates'      => $this->canFillRates(),
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
            'date'        => 'required|date',
            'customer_id' => 'required|exists:chart_of_accounts,id',
            'remarks'     => 'nullable|string|max:1000',
            'mark_complete' => 'nullable|boolean',

            'vehicles'                              => 'required|array|min:1',
            'vehicles.*.id'                          => 'nullable|integer',
            'vehicles.*.vehicle_id'                  => 'required|exists:vehicles,id',
            'vehicles.*.route_id'                    => 'required|exists:vehicle_routes,id',
            'vehicles.*.container_no'                => 'nullable|string|max:100',
            'vehicles.*.item_description'            => 'nullable|string|max:1000',

            'vehicles.*.trip_type'                   => 'nullable|in:one_way,two_way',
            'vehicles.*.pickup_port_id'               => 'nullable|exists:ports,id',
            'vehicles.*.destination_location_id'      => 'nullable|exists:customer_locations,id',
            'vehicles.*.dropoff_port_id'               => 'nullable|exists:ports,id',
            'vehicles.*.rent'                         => 'nullable|numeric|min:0',
            'vehicles.*.labour_charges'                => 'nullable|numeric|min:0',
            'vehicles.*.yard_charges'                  => 'nullable|numeric|min:0',
            'vehicles.*.kanta_charges'                 => 'nullable|numeric|min:0',
            'vehicles.*.retention_first_day_charges'   => 'nullable|numeric|min:0',
            'vehicles.*.retention_next_day_rate'       => 'nullable|numeric|min:0',
            'vehicles.*.retention_extra_days'          => 'nullable|integer|min:0',
            'vehicles.*.retention_night_rate'          => 'nullable|numeric|min:0',
            'vehicles.*.delivery_challan_id'           => 'nullable|exists:delivery_challans,id',

            'vehicles.*.extra_port'                    => 'nullable|array',
            'vehicles.*.extra_port.*.port_id'           => 'nullable|exists:ports,id',
            'vehicles.*.extra_port.*.charges'           => 'nullable|numeric|min:0',
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

        return $this->persistDirect($request, $job);
    }

    // Direct jobs — one or more vehicles, each its own line item (item 3).
    private function persistDirect(Request $request, ?DailyJob $job = null)
    {
        $data = $request->validate($this->rules());
        $canFillRates = $this->canFillRates();

        return DB::transaction(function () use ($data, $job, $canFillRates, $request) {
            if ($job) {
                $job->update([
                    'date'        => $data['date'],
                    'customer_id' => $data['customer_id'],
                    'remarks'     => $data['remarks'] ?? null,
                    'updated_by'  => auth()->id(),
                ]);
            } else {
                $job = DailyJob::create([
                    'job_no'          => $this->nextJobNo(),
                    'job_type'        => 'direct',
                    // Every new direct job starts 'incomplete' regardless of
                    // who creates it — it only flips to 'complete' below,
                    // and only for someone with daily_jobs.fill_rates.
                    'status'          => 'incomplete',
                    'date'            => $data['date'],
                    'customer_id'     => $data['customer_id'],
                    // Trip Plan no longer carries charges (item 2) — tax
                    // now applies to the job's grand total instead (item 13).
                    'trip_plan_total' => 0,
                    'job_total'       => 0,
                    'remarks'         => $data['remarks'] ?? null,
                    'created_by'      => auth()->id(),
                    'updated_by'      => auth()->id(),
                ]);
            }

            $existingLineIds = $job->vehicles()->pluck('id')->all();
            $keptLineIds = [];
            $jobTotal = 0;

            foreach ($data['vehicles'] as $vRow) {
                $basic = [
                    'vehicle_id'       => $vRow['vehicle_id'],
                    'route_id'         => $vRow['route_id'],
                    'container_no'     => $vRow['container_no'] ?? null,
                    'item_description' => $vRow['item_description'] ?? null,
                ];

                $extraPortRows = [];

                if ($canFillRates) {
                    $tripType  = $vRow['trip_type'] ?? 'one_way';
                    $first     = (float) ($vRow['retention_first_day_charges'] ?? 0);
                    $nextRate  = (float) ($vRow['retention_next_day_rate'] ?? 0);
                    $extraDays = (int) ($vRow['retention_extra_days'] ?? 0);
                    $nightRate = (float) ($vRow['retention_night_rate'] ?? 0);
                    $retentionTotal = DailyJobVehicle::computeRetentionTotal($first, $nextRate, $extraDays, $nightRate);

                    $extraTotal = 0;
                    foreach (($vRow['extra_port'] ?? []) as $ep) {
                        if (empty($ep['port_id'])) {
                            continue;
                        }
                        $charge = (float) ($ep['charges'] ?? 0);
                        $extraPortRows[] = ['port_id' => $ep['port_id'], 'charges' => $charge];
                        $extraTotal += $charge;
                    }
                    $extraTotal = round($extraTotal, 2);

                    $rent   = (float) ($vRow['rent'] ?? 0);
                    $labour = (float) ($vRow['labour_charges'] ?? 0);
                    $yard   = (float) ($vRow['yard_charges'] ?? 0);
                    $kanta  = (float) ($vRow['kanta_charges'] ?? 0);
                    $lineTotal = round($rent + $labour + $yard + $kanta + $retentionTotal + $extraTotal, 2);

                    $rateFields = [
                        'trip_type'                   => $tripType,
                        'pickup_port_id'              => $vRow['pickup_port_id'] ?? null,
                        'destination_location_id'     => $tripType === 'two_way' ? ($vRow['destination_location_id'] ?? null) : null,
                        'dropoff_port_id'             => $vRow['dropoff_port_id'] ?? null,
                        'rent'                        => $rent,
                        'labour_charges'              => $labour,
                        'yard_charges'                => $yard,
                        'kanta_charges'                => $kanta,
                        'retention_first_day_charges' => $first,
                        'retention_next_day_rate'     => $nextRate,
                        'retention_extra_days'        => $extraDays,
                        'retention_night_rate'        => $nightRate,
                        'retention_total'             => $retentionTotal,
                        'extra_port_charges_total'    => $extraTotal,
                        'line_total'                  => $lineTotal,
                        'delivery_challan_id'         => $vRow['delivery_challan_id'] ?? null,
                    ];
                } else {
                    // No fill_rates permission — never trust the client for
                    // gated fields; force them to a clean zero state
                    // whatever was actually submitted.
                    $rateFields = [
                        'trip_type'                   => 'one_way',
                        'pickup_port_id'              => null,
                        'destination_location_id'     => null,
                        'dropoff_port_id'             => null,
                        'rent'                        => 0,
                        'labour_charges'               => 0,
                        'yard_charges'                 => 0,
                        'kanta_charges'                => 0,
                        'retention_first_day_charges' => 0,
                        'retention_next_day_rate'     => 0,
                        'retention_extra_days'        => 0,
                        'retention_night_rate'        => 0,
                        'retention_total'             => 0,
                        'extra_port_charges_total'    => 0,
                        'line_total'                  => 0,
                        'delivery_challan_id'         => null,
                    ];
                }

                $lineId = $vRow['id'] ?? null;
                $line = ($lineId && in_array($lineId, $existingLineIds, true)) ? DailyJobVehicle::find($lineId) : null;

                $payload = array_merge($basic, $rateFields, ['updated_by' => auth()->id()]);

                if ($line) {
                    $line->update($payload);
                } else {
                    $payload['daily_job_id'] = $job->id;
                    $payload['created_by']   = auth()->id();
                    $payload['is_legacy']    = false;
                    $line = DailyJobVehicle::create($payload);
                }

                // Extra port charges are a rate-field concern — only
                // touched when the user is allowed to fill rates, so an
                // assistant's save never wipes out charges an admin already
                // entered on a row they're also editing.
                if ($canFillRates) {
                    $line->extraPortCharges()->delete();
                    foreach ($extraPortRows as $epRow) {
                        $line->extraPortCharges()->create($epRow);
                    }
                }

                $keptLineIds[] = $line->id;
                $jobTotal += $line->line_total;
            }

            // Rows removed from the form (vehicle taken off the job) are
            // dropped — cascades to their extra port charges automatically.
            $job->vehicles()->whereNotIn('id', $keptLineIds)->delete();

            $updateData = ['job_total' => round($jobTotal, 2)];
            if ($canFillRates) {
                $updateData['status'] = $request->boolean('mark_complete') ? 'complete' : 'incomplete';
            }
            $job->update($updateData);

            return $job;
        });
    }

    // Party-to-Party (Vendor to Customer directly) — simple ledger-style row,
    // no vehicle/route/trip-plan masters involved. Unaffected by items 2/3/11.
    private function persistPartyToParty(Request $request, ?DailyJob $job = null)
    {
        $data = $request->validate($this->ptyRules());

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
            $payload['status']     = 'complete'; // assistant/admin split doesn't apply to Party-to-Party
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
        $job = DailyJob::with([
            'vehicles.extraPortCharges', 'vehicles.deliveryChallan', 'extraPortCharges',
        ])->findOrFail($id);

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
        $job = DailyJob::with([
            'vehicles.vehicle', 'vehicles.route', 'vehicles.pickupPort', 'vehicles.dropoffPort',
            'vehicles.destinationLocation', 'vehicles.extraPortCharges.port', 'vehicles.deliveryChallan',
            'customer', 'vendor',
        ])->findOrFail($id);

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

    // ── Legacy Delivery Challan (pre-rewrite direct jobs only) ─────────
    // Kept exactly as it was before the standalone Delivery Challan module
    // (item 7) existed — only reachable for jobs that already have a dc_no
    // on the job header. New jobs use DeliveryChallanController instead and
    // never populate these columns.

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

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(10, 10);
        $pdf->Cell(0, 7, 'M M LOGISTICS', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(10, 17);
        $pdf->Cell(0, 5, 'Room No 301, 303, 305, 307, 3rd Floor, Custom Trade Tower,', 0, 1, 'L');
        $pdf->SetXY(10, 22);
        $pdf->Cell(0, 5, 'KPT Stadium, Kharadar, Karachi', 0, 1, 'L');

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(140, 12);
        $pdf->Cell(60, 8, 'DELIVERY CHALLAN', 0, 1, 'R');

        $pdf->Line(10, 30, 200, 30);
        $pdf->Ln(12);

        $pdf->SetFont('helvetica', '', 10);
        $headHtml = '
        <table cellpadding="3" cellspacing="0" width="100%">
            <tr>
                <td width="50%"><b>DC No.:</b> ' . e($job->dc_no) . '</td>
                <td width="50%" align="right"><b>Date:</b> ' . ($job->dc_date ? $job->dc_date->format('d-m-Y') : '') . '</td>
            </tr>
        </table>';
        $pdf->writeHTML($headHtml, true, false, false, false, '');

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

        return $pdf->Output('dc_' . $job->dc_no . '.pdf', 'I');
    }


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
        $pdf->SetXY(10, $yOffset + 10);
        $pdf->Cell(0, 7, 'M M LOGISTICS', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(10, $yOffset + 17);
        $pdf->Cell(0, 5, 'Room No 301, 303, 305, 307, 3rd Floor, Custom Trade Tower,', 0, 1, 'L');
        $pdf->SetXY(10, $yOffset + 22);
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