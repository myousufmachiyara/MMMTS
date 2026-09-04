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
        // pickupPort/dropoffPort (header-level) serve double duty: the
        // shared Trip Plan for a Direct job, and the legacy per-job DC modal
        // below for jobs created before the standalone Delivery Challan
        // module existed.
        $query = DailyJob::with(['vehicles.vehicle', 'route', 'customer', 'vendor', 'pickupPort', 'dropoffPort']);

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

            // ── Trip & Charges — shared across every vehicle on the job.
            // Everything on a multi-vehicle Direct job is the same for
            // every vehicle EXCEPT the vehicle itself and its container
            // number, so these are entered once here instead of once per
            // vehicle-row. route_id/item_description stay BASIC fields
            // (assistant-fillable, item 11) exactly as they were when they
            // lived on each vehicle-row; the rest stay ADMIN-only
            // (daily_jobs.fill_rates) exactly as before.
            'route_id'                    => 'required|exists:vehicle_routes,id',
            'item_description'            => 'nullable|string|max:1000',

            'trip_type'                   => 'nullable|in:one_way,two_way',
            'pickup_port_id'              => 'nullable|exists:ports,id',
            'destination_location_id'     => 'nullable|exists:customer_locations,id',
            'dropoff_port_id'             => 'nullable|exists:ports,id',
            'rent'                        => 'nullable|numeric|min:0',
            'labour_charges'              => 'nullable|numeric|min:0',
            'yard_charges'                => 'nullable|numeric|min:0',
            'kanta_charges'               => 'nullable|numeric|min:0',
            'retention_first_day_charges' => 'nullable|numeric|min:0',
            'retention_next_day_rate'     => 'nullable|numeric|min:0',
            'retention_extra_days'        => 'nullable|integer|min:0',
            'retention_night_rate'        => 'nullable|numeric|min:0',

            'extra_port'                  => 'nullable|array',
            'extra_port.*.port_id'        => 'nullable|exists:ports,id',
            'extra_port.*.charges'        => 'nullable|numeric|min:0',

            // ── Vehicles — only what genuinely differs per vehicle.
            // Delivery Challan linking stays PER vehicle (a DC is issued
            // per truck) even though everything else above is now shared.
            'vehicles'                    => 'required|array|min:1',
            'vehicles.*.id'               => 'nullable|integer',
            'vehicles.*.vehicle_id'       => 'required|exists:vehicles,id',
            'vehicles.*.container_no'     => 'nullable|string|max:100',
            'vehicles.*.delivery_challan_id' => 'nullable|exists:delivery_challans,id',
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

    // Direct jobs — one or more vehicles (item 3), but everything except the
    // vehicle itself and its container number is the SAME for every vehicle
    // on the job, so route/trip plan/rates/retention/extra-port-charges are
    // entered once here and applied to the job as a whole rather than
    // duplicated per vehicle-row.
    private function persistDirect(Request $request, ?DailyJob $job = null)
    {
        $data = $request->validate($this->rules());
        $canFillRates = $this->canFillRates();

        return DB::transaction(function () use ($data, $job, $canFillRates, $request) {
            $basic = [
                // route_id/item_description stay BASIC (assistant-fillable,
                // item 11) — same as when they lived on each vehicle-row.
                'route_id'         => $data['route_id'],
                'item_description' => $data['item_description'] ?? null,
                'date'             => $data['date'],
                'customer_id'      => $data['customer_id'],
                'remarks'          => $data['remarks'] ?? null,
            ];

            $extraPortRows = [];

            if ($canFillRates) {
                $tripType  = $data['trip_type'] ?? 'one_way';
                $first     = (float) ($data['retention_first_day_charges'] ?? 0);
                $nextRate  = (float) ($data['retention_next_day_rate'] ?? 0);
                $extraDays = (int) ($data['retention_extra_days'] ?? 0);
                $nightRate = (float) ($data['retention_night_rate'] ?? 0);
                $retentionTotal = DailyJobVehicle::computeRetentionTotal($first, $nextRate, $extraDays, $nightRate);

                $extraTotal = 0;
                foreach (($data['extra_port'] ?? []) as $ep) {
                    if (empty($ep['port_id'])) {
                        continue;
                    }
                    $charge = (float) ($ep['charges'] ?? 0);
                    $extraPortRows[] = ['port_id' => $ep['port_id'], 'charges' => $charge];
                    $extraTotal += $charge;
                }
                $extraTotal = round($extraTotal, 2);

                $rent   = (float) ($data['rent'] ?? 0);
                $labour = (float) ($data['labour_charges'] ?? 0);
                $yard   = (float) ($data['yard_charges'] ?? 0);
                $kanta  = (float) ($data['kanta_charges'] ?? 0);
                $jobTotal = round($rent + $labour + $yard + $kanta + $retentionTotal + $extraTotal, 2);

                $rateFields = [
                    'trip_type'                   => $tripType,
                    'pickup_port_id'              => $data['pickup_port_id'] ?? null,
                    'destination_location_id'     => $tripType === 'two_way' ? ($data['destination_location_id'] ?? null) : null,
                    'dropoff_port_id'             => $data['dropoff_port_id'] ?? null,
                    'rent'                        => $rent,
                    'labour_charges'              => $labour,
                    'yard_charges'                => $yard,
                    'kanta_charges'               => $kanta,
                    'retention_first_day_charges' => $first,
                    'retention_next_day_rate'     => $nextRate,
                    'retention_extra_days'        => $extraDays,
                    'retention_night_rate'        => $nightRate,
                    'retention_total'             => $retentionTotal,
                    'extra_port_charges_total'    => $extraTotal,
                ];
            } elseif ($job) {
                // No fill_rates permission, editing an EXISTING job — never
                // trust the client for gated fields, but also never blank
                // out rates an admin already entered just because an
                // assistant (e.g. adding/removing a vehicle) saved the form.
                $rateFields = [
                    'trip_type'                   => $job->trip_type,
                    'pickup_port_id'              => $job->pickup_port_id,
                    'destination_location_id'     => $job->destination_location_id,
                    'dropoff_port_id'             => $job->dropoff_port_id,
                    'rent'                        => $job->rent,
                    'labour_charges'              => $job->labour_charges,
                    'yard_charges'                => $job->yard_charges,
                    'kanta_charges'               => $job->kanta_charges,
                    'retention_first_day_charges' => $job->retention_first_day_charges,
                    'retention_next_day_rate'     => $job->retention_next_day_rate,
                    'retention_extra_days'        => $job->retention_extra_days,
                    'retention_night_rate'        => $job->retention_night_rate,
                    'retention_total'             => $job->retention_total,
                    'extra_port_charges_total'    => $job->extra_port_charges_total,
                ];
                $jobTotal = $job->job_total;
            } else {
                // Brand new job, no fill_rates — everything starts clean at
                // zero, pending an admin.
                $rateFields = [
                    'trip_type'                   => 'one_way',
                    'pickup_port_id'              => null,
                    'destination_location_id'     => null,
                    'dropoff_port_id'             => null,
                    'rent'                        => 0,
                    'labour_charges'              => 0,
                    'yard_charges'                => 0,
                    'kanta_charges'               => 0,
                    'retention_first_day_charges' => 0,
                    'retention_next_day_rate'     => 0,
                    'retention_extra_days'        => 0,
                    'retention_night_rate'        => 0,
                    'retention_total'             => 0,
                    'extra_port_charges_total'    => 0,
                ];
                $jobTotal = 0;
            }

            if ($job) {
                $job->update(array_merge($basic, $rateFields, [
                    'job_total'  => round($jobTotal, 2),
                    'updated_by' => auth()->id(),
                ]));
            } else {
                $job = DailyJob::create(array_merge($basic, $rateFields, [
                    'job_no'          => $this->nextJobNo(),
                    'job_type'        => 'direct',
                    // Every new direct job starts 'incomplete' regardless of
                    // who creates it — it only flips to 'complete' below,
                    // and only for someone with daily_jobs.fill_rates.
                    'status'          => 'incomplete',
                    // Trip Plan no longer carries charges (item 2) — tax
                    // now applies to the job's grand total instead (item 13).
                    'trip_plan_total' => 0,
                    'job_total'       => round($jobTotal, 2),
                    'created_by'      => auth()->id(),
                    'updated_by'      => auth()->id(),
                ]));
            }

            // Extra port charges are a rate-field concern — only touched
            // when the user is allowed to fill rates, so an assistant's
            // save never wipes out charges an admin already entered.
            if ($canFillRates) {
                $job->sharedExtraPortCharges()->delete();
                foreach ($extraPortRows as $epRow) {
                    $job->sharedExtraPortCharges()->create($epRow);
                }
            }

            $existingLineIds = $job->vehicles()->pluck('id')->all();
            $keptLineIds = [];

            foreach ($data['vehicles'] as $vRow) {
                $lineId = $vRow['id'] ?? null;
                $line = ($lineId && in_array($lineId, $existingLineIds, true)) ? DailyJobVehicle::find($lineId) : null;

                $payload = [
                    'vehicle_id'       => $vRow['vehicle_id'],
                    'container_no'     => $vRow['container_no'] ?? null,
                    // DC linking stays per-vehicle even though everything
                    // else is shared — a DC is issued per truck. Gated the
                    // same way the rate fields are: only someone with
                    // fill_rates can change it, and an existing link is
                    // preserved (not wiped) when they can't.
                    'delivery_challan_id' => $canFillRates
                        ? ($vRow['delivery_challan_id'] ?? null)
                        : ($line?->delivery_challan_id),
                    // Route/item description/trip plan are mirrored onto
                    // every vehicle-row too, purely so anything still
                    // reading a line's own route/trip-plan (e.g. historical
                    // reports) sees the job's real value rather than a
                    // null/default — the job header above is the source of
                    // truth. Charges are deliberately NOT mirrored (zeroed
                    // below) since they're no longer per-vehicle amounts —
                    // summing them would double (or N-times) count the
                    // job's actual charges.
                    'route_id'                    => $job->route_id,
                    'item_description'            => $job->item_description,
                    'trip_type'                   => $job->trip_type,
                    'pickup_port_id'              => $job->pickup_port_id,
                    'destination_location_id'     => $job->destination_location_id,
                    'dropoff_port_id'             => $job->dropoff_port_id,
                    'rent'                        => 0,
                    'labour_charges'              => 0,
                    'yard_charges'                => 0,
                    'kanta_charges'               => 0,
                    'retention_first_day_charges' => 0,
                    'retention_next_day_rate'     => 0,
                    'retention_extra_days'        => 0,
                    'retention_night_rate'        => 0,
                    'retention_total'             => 0,
                    'extra_port_charges_total'    => 0,
                    'line_total'                  => 0,
                    'updated_by'                  => auth()->id(),
                ];

                if ($line) {
                    $line->update($payload);
                } else {
                    $payload['daily_job_id'] = $job->id;
                    $payload['created_by']   = auth()->id();
                    $payload['is_legacy']    = false;
                    $line = DailyJobVehicle::create($payload);
                }

                $keptLineIds[] = $line->id;
            }

            // Rows removed from the form (vehicle taken off the job) are
            // dropped — cascades to their (now unused, going forward)
            // per-vehicle extra port charges automatically.
            $job->vehicles()->whereNotIn('id', $keptLineIds)->delete();

            if ($canFillRates) {
                $job->update(['status' => $request->boolean('mark_complete') ? 'complete' : 'incomplete']);
            }

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

        }  catch (\Throwable $e) {
            Log::error('[DailyJob] Store error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $job = DailyJob::with([
            'vehicles.deliveryChallan', 'route', 'pickupPort', 'dropoffPort',
            'destinationLocation', 'sharedExtraPortCharges',
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
            'vehicles.vehicle', 'vehicles.deliveryChallan',
            'route', 'pickupPort', 'dropoffPort', 'destinationLocation', 'sharedExtraPortCharges.port',
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

    // Print — a single job slip. Branches by job_type since Direct and
    // Party-to-Party jobs carry completely different data. Direct jobs now
    // list every vehicle-row (item 3).
    public function print($id)
    {
        $job = DailyJob::with([
            'customer', 'vendor',
            'route', 'pickupPort', 'dropoffPort', 'destinationLocation', 'sharedExtraPortCharges.port',
            'vehicles.vehicle', 'vehicles.deliveryChallan',
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

        $statusLabel = $job->job_type === 'direct'
            ? ($job->status === 'incomplete' ? ' (INCOMPLETE — pending rates)' : '')
            : '';

        $infoHtml = '
        <table cellpadding="3" cellspacing="0" width="100%">
            <tr>
                <td width="60%">
                    <b>' . e($job->customer->name ?? '') . '</b><br>
                    ' . e($job->customer->address ?? '') . '
                </td>
                <td width="40%">
                    <table border="1" cellpadding="4" cellspacing="0" style="font-size:10px;">
                        <tr><td width="40%"><b>Job No.</b></td><td width="60%">' . e($job->job_no) . e($statusLabel) . '</td></tr>
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
            // Route/trip plan/rates/retention/extra-port-charges are shared
            // across every vehicle on the job (multi-vehicle-form change) —
            // shown once here, followed by a simple list of the vehicles
            // (and their own Delivery Challan, which stays per-vehicle).
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'Trip & Charges', 0, 1, 'L');

            $tripHtml = '
            <table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:10px;">
                <tr><td width="20%"><b>Route</b></td><td width="30%">' . e($job->route->name ?? '—') . '</td>
                    <td width="20%"><b>Trip Type</b></td><td width="30%">' . ($job->trip_type === 'two_way' ? 'Two Way' : 'One Way') . '</td></tr>
                <tr><td><b>Pickup Port</b></td><td>' . e($job->pickupPort->name ?? '—') . '</td>
                    <td><b>Dropoff Port</b></td><td>' . e($job->dropoffPort->name ?? '—') . '</td></tr>
                ' . ($job->trip_type === 'two_way' ? '<tr><td><b>Destination</b></td><td colspan="3">' . e($job->destinationLocation->location_name ?? '—') . '</td></tr>' : '') . '
                <tr><td colspan="4"><b>Item Description:</b> ' . e($job->item_description ?? '') . '</td></tr>
            </table>';
            $pdf->writeHTML($tripHtml, true, false, true, false, '');
            $pdf->Ln(2);

            if ($job->sharedExtraPortCharges->count()) {
                $extraHtml = '<table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:10px;">
                    <tr style="background-color:#f5f5f5;font-weight:bold;"><th>Port</th><th>Charges</th></tr>';
                foreach ($job->sharedExtraPortCharges as $epc) {
                    $extraHtml .= '<tr><td>' . e($epc->port->name ?? '') . '</td><td align="right">' . number_format($epc->charges, 2) . '</td></tr>';
                }
                $extraHtml .= '</table>';
                $pdf->writeHTML($extraHtml, true, false, true, false, '');
                $pdf->Ln(2);
            }

            $sumHtml = '
            <table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="text-align:right;font-size:10px;">
                <tr><td width="80%" align="left">Rent</td><td width="20%">' . number_format($job->rent, 2) . '</td></tr>
                <tr><td align="left">Labour Charges</td><td>' . number_format($job->labour_charges, 2) . '</td></tr>
                <tr><td align="left">Yard Charges</td><td>' . number_format($job->yard_charges, 2) . '</td></tr>
                <tr><td align="left">Weight Bridge (Kanta)</td><td>' . number_format($job->kanta_charges, 2) . '</td></tr>
                <tr><td align="left">Extra Port Charges</td><td>' . number_format($job->extra_port_charges_total, 2) . '</td></tr>
                <tr><td align="left">Retention Charges</td><td>' . number_format($job->retention_total, 2) . '</td></tr>
                <tr style="background-color:#f5f5f5;font-weight:bold;"><td align="left">Job Grand Total</td><td>' . number_format($job->job_total, 2) . '</td></tr>
            </table>';
            $pdf->writeHTML($sumHtml, true, false, true, false, '');
            $pdf->Ln(4);

            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'Vehicles (' . $job->vehicles->count() . ')', 0, 1, 'L');

            $vehHtml = '<table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:10px;">
                <tr style="background-color:#f5f5f5;font-weight:bold;">
                    <th width="8%">#</th><th width="37%">Vehicle</th><th width="30%">Container #</th><th width="25%">DC #</th>
                </tr>';
            foreach ($job->vehicles as $i => $line) {
                $vehHtml .= '<tr>
                    <td>' . ($i + 1) . '</td>
                    <td>' . e($line->vehicle->name ?? '') . ' (' . e($line->vehicle->vehicle_no ?? '') . ')</td>
                    <td>' . e($line->container_no ?? '—') . '</td>
                    <td>' . e($line->deliveryChallan->dc_no ?? '—') . '</td>
                </tr>';
            }
            $vehHtml .= '</table>';
            $pdf->writeHTML($vehHtml, true, false, true, false, '');
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

        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 8, 25);
        }

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
}