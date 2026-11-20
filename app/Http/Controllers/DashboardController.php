<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\DailyJob;
use App\Models\DailyJobMadqamLine;
use App\Models\DailyJobVehicle;
use App\Models\Invoice;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->toDateString();

        // Vehicles out today: Direct jobs dated today, plus Muqadum vehicles
        // whose start–end dates cover today. (daily_jobs.vehicle_id is never
        // filled by current jobs, so the real vehicle rows are read instead.)
        $directIds = DailyJobVehicle::whereIn('daily_job_id',
                DailyJob::where('job_type', 'direct')->where('date', $today)->select('id'))
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id');

        $muqadumIds = DailyJobMadqamLine::whereIn('daily_job_id',
                DailyJob::where('job_type', 'madqam')->select('id'))
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id');

        $stats = [
            'vehicles_on_route_today' => $directIds->merge($muqadumIds)->unique()->count(),

            // Jobs done but not yet aggregated into a bill
            'non_billed_jobs' => DailyJob::whereNull('bill_id')->count(),

            // Bills created but not yet rolled into a customer invoice
            'pending_bills' => Bill::whereNull('invoice_id')->count(),

            // Invoices not fully paid
            'pending_invoices' => Invoice::where('status', '!=', 'cleared')->count(),

            // Total outstanding across all invoices (what customers owe us)
            'total_receivables' => round(Invoice::sum('total_amount') - Invoice::sum('paid_amount'), 2),

            // What we owe vendors: the balance is stored per vehicle, so use
            // the ×vehicle-count total (the duplicate plain-sum key is gone).
            'total_payables' => round(
                DailyJob::with('ptyVehicles')->where('job_type', 'party_to_party')->get()->sum('pty_total_balance'),
                2
            ),
        ];

        return view('home', compact('stats'));
    }
}