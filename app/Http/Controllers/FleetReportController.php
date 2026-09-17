<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\DailyJob;
use App\Models\Vehicle;
use App\Models\VehicleRoute;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
        ];

        return view('reports.fleet_reports', compact('reports', 'from', 'to', 'vehicles', 'customers', 'vendors', 'routes'));
    }

    private function jobsInRange($from, $to)
    {
        // FIX — daily_jobs.vehicle_id is a legacy, pre-multi-vehicle column
        // that current jobs never populate (a job's real vehicle(s) live on
        // its daily_job_vehicles rows instead — see DailyJob::vehicles()).
        // Eager-load the real vehicles.vehicle chain so every report below
        // can read a job's actual vehicle(s).
        return DailyJob::with(['vehicles.vehicle', 'customer', 'vendor', 'route'])
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