<?php

namespace App\Http\Controllers;

use App\Exports\GenericTableExport;
use App\Models\ChartOfAccounts;
use App\Models\DailyJob;
use App\Models\Vehicle;
use App\Models\VehicleRoute;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class FleetReportController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // MAIN ENTRY POINT — mirrors AccountsReportController's tab pattern.
    // ─────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $from = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to   = $request->to_date   ?? Carbon::now()->endOfMonth()->toDateString();

        $vehicles  = Vehicle::orderBy('name')->get();
        $customers = ChartOfAccounts::customers()->orderBy('name')->get();
        $vendors   = ChartOfAccounts::vendors()->orderBy('name')->get();
        $routes    = VehicleRoute::orderBy('name')->get();

        $reports = [
            'vehicle_wise'    => $this->vehicleWise($from, $to),
            'customer_wise'   => $this->customerWise($from, $to),
            'vendor_wise'     => $this->vendorWise($from, $to),
            'route_wise'      => $this->routeWise($from, $to),
            'customer_routes' => $this->customerRoutes($from, $to),
            // Item 15
            'company_share'   => $this->companyShare($from, $to),
            'vehicle_pl'      => $this->vehiclePL($from, $to),
        ];

        return view('reports.fleet_reports', compact('reports', 'from', 'to', 'vehicles', 'customers', 'vendors', 'routes'));
    }

    // ─────────────────────────────────────────────────────────────
    // ITEM 12 — PDF & Excel export
    //
    // Same pattern as AccountsReportController's export — exports exactly
    // one tab (?report=<key>), rebuilt with the same ?from_date/?to_date
    // filters already applied on screen.
    // ─────────────────────────────────────────────────────────────

    private function reportHeaders(): array
    {
        return [
            'vehicle_wise'    => ['Vehicle', 'Vehicle No.', 'Trips', 'Distinct Routes', 'Total Amount'],
            'customer_wise'   => ['Customer', 'Jobs', 'Vehicles Used', 'Routes Used', 'Total Amount', 'Billed', 'Unbilled'],
            'vendor_wise'     => ['Vendor', 'Jobs', 'Vendor Cost', 'Sale to Customer', 'Profit', 'Advance Paid', 'Guarantee Held', 'Balance Payable'],
            'route_wise'      => ['Route', 'Trips', 'Distinct Vehicles', 'Distinct Customers', 'Total Amount'],
            'customer_routes' => ['Customer', 'Route', 'Trips', 'Total Amount'],
            // Item 15
            'company_share'   => ['Company', 'Revenue', '% of Total'],
            'vehicle_pl'      => ['Vehicle', 'Vehicle No.', 'Trips', 'Revenue', 'Cost', 'Profit'],
        ];
    }

    private function reportLabels(): array
    {
        return [
            'vehicle_wise'    => 'Vehicle Wise',
            'customer_wise'   => 'Customer Wise',
            'vendor_wise'     => 'Vendor Wise (Party-to-Party)',
            'route_wise'      => 'Route Wise',
            'customer_routes' => 'Customer x Route Usage',
            'company_share'   => 'Company Wise % Share',
            'vehicle_pl'      => 'Vehicle P&L (Revenue Only)',
        ];
    }

    private function buildReport(string $key, $from, $to)
    {
        return match ($key) {
            'vehicle_wise'    => $this->vehicleWise($from, $to),
            'customer_wise'   => $this->customerWise($from, $to),
            'vendor_wise'     => $this->vendorWise($from, $to),
            'route_wise'      => $this->routeWise($from, $to),
            'customer_routes' => $this->customerRoutes($from, $to),
            'company_share'   => $this->companyShare($from, $to),
            'vehicle_pl'      => $this->vehiclePL($from, $to),
            default           => null,
        };
    }

    // Each report here is an associative array (unlike AccountsReport's
    // plain numeric-indexed rows), so it needs an explicit key->column
    // mapping per report rather than a generic slice. customer_routes is
    // nested (one group per customer, each holding its own routes[]) —
    // it flattens to one export row per (customer, route) pair.
    private function flattenRows(string $key, $data): array
    {
        return match ($key) {
            'vehicle_wise' => collect($data)->map(fn ($r) => [
                $r['vehicle'], $r['vehicle_no'], $r['trip_count'], $r['routes_used'],
                number_format($r['total_amount'], 2),
            ])->all(),
            'customer_wise' => collect($data)->map(fn ($r) => [
                $r['customer'], $r['job_count'], $r['vehicles_used'], $r['routes_used'],
                number_format($r['total_amount'], 2), number_format($r['billed_amount'], 2), number_format($r['unbilled_amount'], 2),
            ])->all(),
            'vendor_wise' => collect($data)->map(fn ($r) => [
                $r['vendor'], $r['job_count'], number_format($r['total_cost'], 2), number_format($r['total_sale'], 2),
                number_format($r['total_profit'], 2), number_format($r['total_advance'], 2),
                number_format($r['total_guarantee'], 2), number_format($r['total_balance'], 2),
            ])->all(),
            'route_wise' => collect($data)->map(fn ($r) => [
                $r['route'], $r['trip_count'], $r['vehicles_used'], $r['customers'],
                number_format($r['total_amount'], 2),
            ])->all(),
            'customer_routes' => collect($data)->flatMap(fn ($group) =>
                collect($group['routes'])->map(fn ($r) => [
                    $group['customer'], $r['route'], $r['trip_count'], number_format($r['total_amount'], 2),
                ])
            )->all(),
            'company_share' => collect($data)->map(fn ($r) => [
                $r['company'], number_format($r['amount'], 2), number_format($r['percent'], 2) . '%',
            ])->all(),
            'vehicle_pl' => collect($data)->map(fn ($r) => [
                $r['vehicle'], $r['vehicle_no'], $r['trip_count'], number_format($r['revenue'], 2),
                $r['cost'] ?? 'N/A', $r['profit'] ?? 'N/A',
            ])->all(),
            default => [],
        };
    }

    private function prepareExport(Request $request): array
    {
        $headers = $this->reportHeaders();
        $key     = $request->get('report');

        if (!$key || !isset($headers[$key])) {
            abort(404, 'Unknown report.');
        }

        $from = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to   = $request->to_date   ?? Carbon::now()->endOfMonth()->toDateString();

        $data = $this->buildReport($key, $from, $to);
        $rows = $this->flattenRows($key, $data);

        return [
            'label'   => $this->reportLabels()[$key],
            'headers' => $headers[$key],
            'rows'    => $rows,
            'from'    => $from,
            'to'      => $to,
        ];
    }

    public function exportExcel(Request $request)
    {
        $export = $this->prepareExport($request);

        $filename = Str::slug($export['label']) . '_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(
            new GenericTableExport($export['headers'], $export['rows'], $export['label']),
            $filename
        );
    }

    public function exportPdf(Request $request)
    {
        $export = $this->prepareExport($request);

        $pdfContent = $this->renderReportPdf($export['label'], $export['from'], $export['to'], $export['headers'], $export['rows']);

        $filename = Str::slug($export['label']) . '_' . now()->format('Ymd_His') . '.pdf';

        return response($pdfContent, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    // Same shared-style PDF table renderer as AccountsReportController's —
    // duplicated rather than extracted into a shared base/trait, matching
    // how this app already duplicates its PDF boilerplate per controller.
    private function renderReportPdf(string $label, string $from, string $to, array $headers, array $rows): string
    {
        $pdf = new \TCPDF('L', 'mm', 'A4');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('MMMTS');
        $pdf->SetAuthor('Your Company');
        $pdf->SetTitle($label);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, $label, 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Period: ' . Carbon::parse($from)->format('d-m-Y') . ' to ' . Carbon::parse($to)->format('d-m-Y'), 0, 1, 'L');
        $pdf->Ln(3);

        $colWidth = round(100 / max(count($headers), 1), 2);

        $html = '<table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:9px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">';
        foreach ($headers as $h) {
            $html .= '<th width="' . $colWidth . '%">' . e($h) . '</th>';
        }
        $html .= '</tr>';

        if (empty($rows)) {
            $html .= '<tr><td colspan="' . count($headers) . '" align="center">No data found for the selected period.</td></tr>';
        }

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $col) {
                $isNum = $col !== '' && $col !== null && is_numeric(str_replace(',', '', (string) $col));
                $html .= '<td align="' . ($isNum ? 'right' : 'left') . '">' . e((string) $col) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output($label . '.pdf', 'S');
    }

    private function jobsInRange($from, $to)
    {
        // FIX — daily_jobs.vehicle_id is a legacy, pre-multi-vehicle column
        // that current jobs never populate (a job's real vehicle(s) live on
        // its daily_job_vehicles rows instead — see DailyJob::vehicles()).
        // Eager-load the real vehicles.vehicle chain so every report below
        // can read a job's actual vehicle(s). Also eager-loads each
        // vehicle's companies (item 15's companyShare() needs this).
        return DailyJob::with(['vehicles.vehicle.companies', 'customer', 'vendor', 'route'])
            ->whereBetween('date', [$from, $to]);
    }

    // ── Vehicle Wise: trips run + revenue per vehicle (Direct jobs only — party-to-party has no our-vehicle) ──
    //
    // FIX — grouping used to be by daily_jobs.vehicle_id, which is always
    // null for any job created since the multi-vehicle rewrite, so this
    // report showed nothing for current data. Now built from each job's
    // real vehicle-rows (job->vehicles) instead. A job's rent/labour/yard/
    // detention/etc. charges are shared once across the whole job (not
    // tracked per vehicle), so when a job has more than one vehicle its
    // job_total is split evenly across them for this report.
    private function vehicleWise($from, $to)
    {
        $lines = collect();

        $this->jobsInRange($from, $to)
            ->where('job_type', 'direct')
            ->get()
            ->each(function ($job) use (&$lines) {
                $vehicleLines = $job->vehicles->filter(fn ($l) => $l->vehicle_id);
                $count = $vehicleLines->count();
                if ($count === 0) {
                    return;
                }
                $share = round($job->job_total / $count, 2);

                foreach ($vehicleLines as $line) {
                    $lines->push([
                        'vehicle_id' => $line->vehicle_id,
                        'vehicle'    => $line->vehicle->name ?? '—',
                        'vehicle_no' => $line->vehicle->vehicle_no ?? '—',
                        'route'      => $job->route->name ?? null,
                        'amount'     => $share,
                    ]);
                }
            });

        return $lines
            ->groupBy('vehicle_id')
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'vehicle'      => $first['vehicle'],
                    'vehicle_no'   => $first['vehicle_no'],
                    'trip_count'   => $group->count(),
                    'routes_used'  => $group->pluck('route')->filter()->unique()->count(),
                    'total_amount' => round($group->sum('amount'), 2),
                ];
            })
            ->sortByDesc('total_amount')
            ->values();
    }

    // Distinct vehicle_ids actually used across a set of Direct jobs (reads
    // each job's real vehicles() rows — see vehicleWise()'s fix note above).
    private function distinctVehicleCount($jobs)
    {
        return $jobs->where('job_type', 'direct')
            ->flatMap(fn ($job) => $job->vehicles->pluck('vehicle_id'))
            ->filter()
            ->unique()
            ->count();
    }

    // ── Item 15a — Company Wise % Share ──
    //
    // "Our Companies" (see OurCompany/company_vehicle) aren't billing
    // parties — they're the umbrella entities a vehicle operates under. A
    // vehicle can be linked to more than one company (plain many-to-many,
    // no stored ownership percentage anywhere), so this uses the same
    // even-split convention as vehicleWise(): a job's total is split evenly
    // across its vehicles, and — when a vehicle itself is linked to more
    // than one company — that vehicle's share is split evenly again across
    // its companies. A vehicle linked to no company falls into an
    // "Unassigned" bucket rather than being silently dropped, so the
    // percentages below always add up to 100%.
    private function companyShare($from, $to)
    {
        $amounts = collect(); // company_id (or 'unassigned') => running total
        $names   = collect(); // company_id (or 'unassigned') => display name

        $this->jobsInRange($from, $to)
            ->where('job_type', 'direct')
            ->get()
            ->each(function ($job) use (&$amounts, &$names) {
                $vehicleLines = $job->vehicles->filter(fn ($l) => $l->vehicle_id);
                $vCount = $vehicleLines->count();
                if ($vCount === 0) {
                    return;
                }
                $vehicleShare = $job->job_total / $vCount;

                foreach ($vehicleLines as $line) {
                    $companies = $line->vehicle?->companies ?? collect();
                    $cCount    = $companies->count();

                    if ($cCount === 0) {
                        $amounts['unassigned'] = ($amounts['unassigned'] ?? 0) + $vehicleShare;
                        $names['unassigned']   = 'Unassigned (no company linked)';
                        continue;
                    }

                    $perCompany = $vehicleShare / $cCount;
                    foreach ($companies as $company) {
                        $amounts[$company->id] = ($amounts[$company->id] ?? 0) + $perCompany;
                        $names[$company->id]   = $company->name;
                    }
                }
            });

        $grandTotal = $amounts->sum();

        return $amounts
            ->map(function ($amount, $key) use ($names, $grandTotal) {
                return [
                    'company' => $names[$key],
                    'amount'  => round($amount, 2),
                    'percent' => $grandTotal > 0 ? round(($amount / $grandTotal) * 100, 2) : 0,
                ];
            })
            ->sortByDesc('amount')
            ->values();
    }

    // ── Item 15b — Vehicle P&L (revenue only) ──
    //
    // A genuine profit & loss needs a cost side, but nothing in this app
    // tracks vehicle-level costs today (no fuel, driver wage, or
    // maintenance records exist anywhere in the schema). Rather than
    // silently treating cost as 0 (which would make "profit" == revenue and
    // mislead), cost/profit are left null here and rendered as "N/A" — this
    // reuses vehicleWise()'s revenue-per-vehicle figures under P&L-shaped
    // column names so it's ready to gain a real cost column the moment
    // vehicle cost tracking exists.
    private function vehiclePL($from, $to)
    {
        return $this->vehicleWise($from, $to)->map(function ($row) {
            return [
                'vehicle'    => $row['vehicle'],
                'vehicle_no' => $row['vehicle_no'],
                'trip_count' => $row['trip_count'],
                'revenue'    => $row['total_amount'],
                'cost'       => null,
                'profit'     => null,
            ];
        })->values();
    }

    // ── Customer Wise: vehicles used + jobs + billed amount per customer (all job types) ──
    private function customerWise($from, $to)
    {
        return $this->jobsInRange($from, $to)
            ->get()
            ->groupBy('customer_id')
            ->map(function ($jobs, $customerId) {
                $customer = $jobs->first()->customer;
                return [
                    'customer'        => $customer->name ?? '—',
                    'job_count'       => $jobs->count(),
                    // FIX — was reading the legacy singular vehicle relation.
                    'vehicles_used'   => $this->distinctVehicleCount($jobs),
                    'routes_used'     => $jobs->where('job_type', 'direct')->pluck('route.name')->filter()->unique()->count(),
                    'total_amount'    => round($jobs->sum('job_total'), 2),
                    'billed_amount'   => round($jobs->whereNotNull('bill_id')->sum('job_total'), 2),
                    'unbilled_amount' => round($jobs->whereNull('bill_id')->sum('job_total'), 2),
                ];
            })
            ->sortByDesc('total_amount')
            ->values();
    }

    // ── Vendor Wise: Party-to-Party ledger — cost/sale/profit/advance/guarantee/balance per vendor ──
    private function vendorWise($from, $to)
    {
        return $this->jobsInRange($from, $to)
            ->where('job_type', 'party_to_party')
            ->whereNotNull('vendor_id')
            ->get()
            ->groupBy('vendor_id')
            ->map(function ($jobs, $vendorId) {
                $vendor = $jobs->first()->vendor;
                $totalCost = round($jobs->sum('pty_cost'), 2);
                $totalSale = round($jobs->sum('pty_sale_amount'), 2);
                return [
                    'vendor'          => $vendor->name ?? '—',
                    'job_count'       => $jobs->count(),
                    'total_cost'      => $totalCost,
                    'total_sale'      => $totalSale,
                    'total_profit'    => round($totalSale - $totalCost, 2),
                    'total_advance'   => round($jobs->sum('pty_advance'), 2),
                    'total_guarantee' => round($jobs->sum('pty_guarantee'), 2),
                    'total_balance'   => round($jobs->sum('pty_balance'), 2),
                ];
            })
            ->sortByDesc('total_sale')
            ->values();
    }

    // ── Route Wise: how much each route was used, by which vehicles, and revenue ──
    private function routeWise($from, $to)
    {
        return $this->jobsInRange($from, $to)
            ->where('job_type', 'direct')
            ->whereNotNull('route_id')
            ->get()
            ->groupBy('route_id')
            ->map(function ($jobs, $routeId) {
                $route = $jobs->first()->route;
                return [
                    'route'        => $route->name ?? '—',
                    'trip_count'   => $jobs->count(),
                    // FIX — was reading the legacy singular vehicle relation.
                    'vehicles_used' => $this->distinctVehicleCount($jobs),
                    'customers'    => $jobs->pluck('customer.name')->filter()->unique()->count(),
                    'total_amount' => round($jobs->sum('job_total'), 2),
                ];
            })
            ->sortByDesc('trip_count')
            ->values();
    }

    // ── Customer × Route usage matrix ──
    private function customerRoutes($from, $to)
    {
        return $this->jobsInRange($from, $to)
            ->where('job_type', 'direct')
            ->whereNotNull('route_id')
            ->get()
            ->groupBy('customer_id')
            ->map(function ($jobs, $customerId) {
                $customer = $jobs->first()->customer;
                $byRoute = $jobs->groupBy('route_id')->map(function ($routeJobs) {
                    $route = $routeJobs->first()->route;
                    return [
                        'route'      => $route->name ?? '—',
                        'trip_count' => $routeJobs->count(),
                        'total_amount' => round($routeJobs->sum('job_total'), 2),
                    ];
                })->sortByDesc('trip_count')->values();

                return [
                    'customer' => $customer->name ?? '—',
                    'routes'   => $byRoute,
                ];
            })
            ->values();
    }
}