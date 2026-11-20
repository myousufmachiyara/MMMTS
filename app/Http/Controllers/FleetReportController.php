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
            'company_share'   => $this->companyShare($from, $to),
            'vehicle_pl'      => $this->vehiclePL($from, $to),
            'vehicle_ledger'  => $this->vehicleLedger($from, $to, $request->vehicle_id),
        ];

        return view('reports.fleet_reports', compact('reports', 'from', 'to', 'vehicles', 'customers', 'vendors', 'routes'));
    }

    private function reportHeaders(): array
    {
        return [
            'vehicle_wise'    => ['Vehicle', 'Vehicle No.', 'Trips', 'Distinct Routes', 'Total Amount'],
            'customer_wise'   => ['Customer', 'Jobs', 'Vehicles Used', 'Routes Used', 'Total Amount', 'Billed', 'Unbilled'],
            'vendor_wise'     => ['Vendor', 'Jobs', 'Vendor Cost', 'Sale to Customer', 'Profit', 'Advance Paid', 'Guarantee Held', 'Balance Payable'],
            'route_wise'      => ['Route', 'Trips', 'Distinct Vehicles', 'Distinct Customers', 'Total Amount'],
            'customer_routes' => ['Customer', 'Route', 'Trips', 'Total Amount'],
            'company_share'   => ['Company', 'Revenue', '% of Total'],
            'vehicle_pl'      => ['Vehicle', 'Vehicle No.', 'Trips', 'Revenue', 'Cost', 'Profit'],
            'vehicle_ledger'  => ['Vehicle', 'Date', 'Job No.', 'Customer', 'Route', 'Container #', 'DC #', 'Bill #', 'Amount', 'Running Total'],
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
            'vehicle_ledger'  => 'Vehicle Ledger',
        ];
    }

    private function buildReport(string $key, $from, $to, $vehicleId = null)
    {
        return match ($key) {
            'vehicle_wise'    => $this->vehicleWise($from, $to),
            'customer_wise'   => $this->customerWise($from, $to),
            'vendor_wise'     => $this->vendorWise($from, $to),
            'route_wise'      => $this->routeWise($from, $to),
            'customer_routes' => $this->customerRoutes($from, $to),
            'company_share'   => $this->companyShare($from, $to),
            'vehicle_pl'      => $this->vehiclePL($from, $to),
            'vehicle_ledger'  => $this->vehicleLedger($from, $to, $vehicleId),
            default           => null,
        };
    }

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
            'vehicle_ledger' => collect($data)->flatMap(function ($block) {
                $rows = collect($block['lines'])->map(fn ($l) => [
                    $block['vehicle'] . ' (' . $block['vehicle_no'] . ')', $l['date'], $l['job_no'], $l['customer'], $l['route'],
                    $l['container_no'], $l['dc_no'], $l['bill_no'], number_format($l['amount'], 2), number_format($l['running'], 2),
                ]);
                return $rows->push([
                    $block['vehicle'] . ' (' . $block['vehicle_no'] . ')', 'TOTAL', '', '', '', '', '',
                    $block['trip_count'] . ' trip(s)', number_format($block['total'], 2), number_format($block['total'], 2),
                ]);
            })->all(),
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

        $data = $this->buildReport($key, $from, $to, $request->get('vehicle_id'));
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

        $widths = $request->get('report') === 'vehicle_ledger' ? [12, 9, 8, 13, 13, 8, 6, 13, 9, 9] : null;

        $pdfContent = $this->renderReportPdf($export['label'], $export['from'], $export['to'], $export['headers'], $export['rows'], $widths);

        $filename = Str::slug($export['label']) . '_' . now()->format('Ymd_His') . '.pdf';

        return response($pdfContent, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    private function renderReportPdf(string $label, string $from, string $to, array $headers, array $rows, ?array $widths = null): string
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
        foreach ($headers as $hi => $h) {
            $w = $widths[$hi] ?? $colWidth;
            $html .= '<th width="' . $w . '%">' . e($h) . '</th>';
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

    // ── Vehicle Ledger ──
    // Direct jobs (vehicle's own rent + shared charges) and Muqadum jobs
    // (each line's (rent + guarantee + kanta) x days). Party-to-Party runs on
    // the vendor's vehicle, so it is not part of any vehicle ledger.
    private function vehicleLedger($from, $to, $vehicleId = null)
    {
        $lines = collect();

        $this->jobsInRange($from, $to)
            ->with(['vehicles.deliveryChallan', 'bill'])
            ->whereIn('job_type', ['direct', 'madqam'])
            ->orderBy('date')->orderBy('id')
            ->get()
            ->each(function ($job) use (&$lines, $vehicleId) {
                if ($job->job_type === 'madqam') {
                    foreach ($job->madqamLines as $ml) {
                        if (!$ml->vehicle_id) {
                            continue;
                        }
                        if ($vehicleId && (int) $ml->vehicle_id !== (int) $vehicleId) {
                            continue;
                        }
                        $lines->push([
                            'vehicle_id'   => $ml->vehicle_id,
                            'vehicle'      => $ml->vehicle->name ?? '—',
                            'vehicle_no'   => $ml->vehicle->vehicle_no ?? '—',
                            'date'         => ($ml->start_date ?? $job->date)->format('d-m-Y'),
                            'job_no'       => $job->job_no,
                            'customer'     => $job->customer->name ?? '—',
                            'route'        => $ml->destination ?: 'Muqadum',
                            'container_no' => '—',
                            'dc_no'        => '—',
                            'bill_no'      => $job->bill->bill_no ?? 'Unbilled',
                            'amount'       => $ml->line_total,
                        ]);
                    }
                    return;
                }

                foreach ($job->vehicles as $line) {
                    if (!$line->vehicle_id) {
                        continue;
                    }
                    if ($vehicleId && (int) $line->vehicle_id !== (int) $vehicleId) {
                        continue;
                    }
                    $lines->push([
                        'vehicle_id'   => $line->vehicle_id,
                        'vehicle'      => $line->vehicle->name ?? '—',
                        'vehicle_no'   => $line->vehicle->vehicle_no ?? '—',
                        'date'         => $job->date->format('d-m-Y'),
                        'job_no'       => $job->job_no,
                        'customer'     => $job->customer->name ?? '—',
                        'route'        => $job->route->name ?? '—',
                        'container_no' => $line->container_no ?: '—',
                        'dc_no'        => $line->deliveryChallan->dc_no ?? '—',
                        'bill_no'      => $job->bill->bill_no ?? 'Unbilled',
                        'amount'       => $job->vehicleAmount($line),
                    ]);
                }
            });

        return $lines
            ->groupBy('vehicle_id')
            ->map(function ($group) {
                $first   = $group->first();
                $running = 0;
                $rows    = $group->map(function ($l) use (&$running) {
                    $running      = round($running + $l['amount'], 2);
                    $l['running'] = $running;
                    return $l;
                })->values()->all();

                return [
                    'vehicle'    => $first['vehicle'],
                    'vehicle_no' => $first['vehicle_no'],
                    'trip_count' => count($rows),
                    'total'      => $running,
                    'lines'      => $rows,
                ];
            })
            ->sortBy('vehicle')
            ->values();
    }

    private function jobsInRange($from, $to)
    {
        return DailyJob::with([
                'vehicles.vehicle.companies', 'ptyVehicles', 'madqamLines.vehicle.companies',
                'customer', 'vendor', 'route',
            ])
            ->whereBetween('date', [$from, $to]);
    }

    // ── Vehicle Wise: trips + revenue per vehicle (Direct and Muqadum) ──
    private function vehicleWise($from, $to)
    {
        $lines = collect();

        $this->jobsInRange($from, $to)
            ->whereIn('job_type', ['direct', 'madqam'])
            ->get()
            ->each(function ($job) use (&$lines) {
                if ($job->job_type === 'madqam') {
                    foreach ($job->madqamLines as $ml) {
                        if (!$ml->vehicle_id) {
                            continue;
                        }
                        $lines->push([
                            'vehicle_id' => $ml->vehicle_id,
                            'vehicle'    => $ml->vehicle->name ?? '—',
                            'vehicle_no' => $ml->vehicle->vehicle_no ?? '—',
                            'route'      => null, // Muqadum destinations are free text, not route masters
                            'amount'     => $ml->line_total,
                        ]);
                    }
                    return;
                }

                $vehicleLines = $job->vehicles->filter(fn ($l) => $l->vehicle_id);
                foreach ($vehicleLines as $line) {
                    $lines->push([
                        'vehicle_id' => $line->vehicle_id,
                        'vehicle'    => $line->vehicle->name ?? '—',
                        'vehicle_no' => $line->vehicle->vehicle_no ?? '—',
                        'route'      => $job->route->name ?? null,
                        'amount'     => $job->vehicleAmount($line),
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

    // Distinct vehicle_ids used across a set of jobs (Direct rows + Muqadum lines).
    private function distinctVehicleCount($jobs)
    {
        $direct = $jobs->where('job_type', 'direct')
            ->flatMap(fn ($job) => $job->vehicles->pluck('vehicle_id'));
        $muqadum = $jobs->where('job_type', 'madqam')
            ->flatMap(fn ($job) => $job->madqamLines->pluck('vehicle_id'));

        return $direct->merge($muqadum)->filter()->unique()->count();
    }

    // ── Company Wise % Share (Direct and Muqadum) ──
    private function companyShare($from, $to)
    {
        $amounts = collect();
        $names   = collect();

        $add = function ($vehicle, float $share) use (&$amounts, &$names) {
            $companies = $vehicle?->companies ?? collect();
            $cCount    = $companies->count();

            if ($cCount === 0) {
                $amounts['unassigned'] = ($amounts['unassigned'] ?? 0) + $share;
                $names['unassigned']   = 'Unassigned (no company linked)';
                return;
            }
            foreach ($companies as $company) {
                $amounts[$company->id] = ($amounts[$company->id] ?? 0) + $share / $cCount;
                $names[$company->id]   = $company->name;
            }
        };

        $this->jobsInRange($from, $to)
            ->whereIn('job_type', ['direct', 'madqam'])
            ->get()
            ->each(function ($job) use ($add) {
                if ($job->job_type === 'madqam') {
                    foreach ($job->madqamLines as $ml) {
                        if ($ml->vehicle_id) {
                            $add($ml->vehicle, (float) $ml->line_total);
                        }
                    }
                    return;
                }
                foreach ($job->vehicles->filter(fn ($l) => $l->vehicle_id) as $line) {
                    $add($line->vehicle, (float) $job->vehicleAmount($line));
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

    // ── Vehicle P&L (revenue only — no vehicle cost data exists yet) ──
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

    // ── Customer Wise (all job types) ──
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
                    'vehicles_used'   => $this->distinctVehicleCount($jobs),
                    'routes_used'     => $jobs->where('job_type', 'direct')->pluck('route.name')->filter()->unique()->count(),
                    'total_amount'    => round($jobs->sum('other_charges_total'), 2),
                    'billed_amount'   => round($jobs->whereNotNull('bill_id')->sum('other_charges_total'), 2),
                    'unbilled_amount' => round($jobs->whereNull('bill_id')->sum('other_charges_total'), 2),
                ];
            })
            ->sortByDesc('total_amount')
            ->values();
    }

    // ── Vendor Wise (Party-to-Party) ──
    private function vendorWise($from, $to)
    {
        return $this->jobsInRange($from, $to)
            ->where('job_type', 'party_to_party')
            ->whereNotNull('vendor_id')
            ->get()
            ->groupBy('vendor_id')
            ->map(function ($jobs, $vendorId) {
                $vendor = $jobs->first()->vendor;
                $totalCost = round($jobs->sum('pty_total_cost'), 2);
                $totalSale = round($jobs->sum('pty_total_sale'), 2);
                return [
                    'vendor'          => $vendor->name ?? '—',
                    'job_count'       => $jobs->count(),
                    'total_cost'      => $totalCost,
                    'total_sale'      => $totalSale,
                    'total_profit'    => round($totalSale - $totalCost, 2),
                    'total_advance'   => round($jobs->sum('pty_total_advance'), 2),
                    'total_guarantee' => round($jobs->sum('pty_total_guarantee'), 2),
                    'total_balance'   => round($jobs->sum('pty_total_balance'), 2),
                ];
            })
            ->sortByDesc('total_sale')
            ->values();
    }

    // ── Route Wise (Direct only — Muqadum has no route master) ──
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
                    'route'         => $route->name ?? '—',
                    'trip_count'    => $jobs->count(),
                    'vehicles_used' => $this->distinctVehicleCount($jobs),
                    'customers'     => $jobs->pluck('customer.name')->filter()->unique()->count(),
                    'total_amount'  => round($jobs->sum('other_charges_total'), 2),
                ];
            })
            ->sortByDesc('trip_count')
            ->values();
    }

    // ── Customer x Route usage ──
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
                        'route'        => $route->name ?? '—',
                        'trip_count'   => $routeJobs->count(),
                        'total_amount' => round($routeJobs->sum('other_charges_total'), 2),
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