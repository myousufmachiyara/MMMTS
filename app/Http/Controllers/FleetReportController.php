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
        return DailyJob::with(['vehicle', 'customer', 'vendor', 'route'])
            ->whereBetween('date', [$from, $to]);
    }

    // ── Vehicle Wise: trips run + revenue per vehicle (Direct jobs only — party-to-party has no our-vehicle) ──
    private function vehicleWise($from, $to)
    {
        return $this->jobsInRange($from, $to)
            ->where('job_type', 'direct')
            ->whereNotNull('vehicle_id')
            ->get()
            ->groupBy('vehicle_id')
            ->map(function ($jobs, $vehicleId) {
                $vehicle = $jobs->first()->vehicle;
                return [
                    'vehicle'      => $vehicle->name ?? '—',
                    'vehicle_no'   => $vehicle->vehicle_no ?? '—',
                    'trip_count'   => $jobs->count(),
                    'routes_used'  => $jobs->pluck('route.name')->filter()->unique()->count(),
                    'total_amount' => round($jobs->sum('job_total'), 2),
                ];
            })
            ->sortByDesc('total_amount')
            ->values();
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
                    'vehicles_used'   => $jobs->where('job_type', 'direct')->pluck('vehicle.name')->filter()->unique()->count(),
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
                    'vehicles_used' => $jobs->pluck('vehicle.name')->filter()->unique()->count(),
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