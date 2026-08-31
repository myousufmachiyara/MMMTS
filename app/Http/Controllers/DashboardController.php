<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\DailyJob;
use App\Models\Invoice;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->toDateString();

        $stats = [
            // Distinct "Our Companies" vehicles out on a Direct job today
            'vehicles_on_route_today' => DailyJob::where('job_type', 'direct')
                ->where('date', $today)
                ->whereNotNull('vehicle_id')
                ->distinct('vehicle_id')
                ->count('vehicle_id'),

            // Jobs done but not yet aggregated into a bill
            'non_billed_jobs' => DailyJob::whereNull('bill_id')->count(),

            // Bills created but not yet rolled into a customer invoice
            'pending_bills' => Bill::whereNull('invoice_id')->count(),

            // Invoices not fully paid
            'pending_invoices' => Invoice::where('status', '!=', 'cleared')->count(),

            // Total outstanding across all invoices (what customers owe us)
            'total_receivables' => round(Invoice::sum('total_amount') - Invoice::sum('paid_amount'), 2),

            // Total outstanding to vendors from the Party-to-Party ledger (what we owe vendors)
            'total_payables' => round(DailyJob::where('job_type', 'party_to_party')->sum('pty_balance'), 2),
        ];

        return view('home', compact('stats'));
    }
}
