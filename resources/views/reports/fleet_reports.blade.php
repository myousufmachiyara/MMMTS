@extends('layouts.app')
@section('title', 'Fleet Reports')

@section('content')
<style>
@media print { .no-print { display: none !important; } }
</style>

<div class="tabs">

    <ul class="nav nav-tabs" id="fleetReportTabs" role="tablist">
        @foreach ([
            'vehicle_wise'    => 'Vehicle Wise',
            'vehicle_ledger'  => 'Vehicle Ledger',
            'customer_wise'   => 'Customer Wise',
            'vendor_wise'     => 'Vendor Wise (Party-to-Party)',
            'route_wise'      => 'Route Wise',
            'customer_routes' => 'Customer × Route Usage',
            'company_share'   => 'Company % Share',
            'vehicle_pl'      => 'Vehicle P&L',
        ] as $key => $label)
            <li class="nav-item">
                <a class="nav-link {{ $loop->first ? 'active' : '' }}"
                   id="{{ $key }}-tab" data-bs-toggle="tab"
                   href="#{{ $key }}" role="tab">{{ $label }}</a>
            </li>
        @endforeach
    </ul>

    <div class="tab-content mt-3" id="fleetReportTabsContent">

        {{-- Shared date filter --}}
        <form method="GET" action="{{ route('reports.fleet') }}" class="row g-2 mb-3 no-print" id="fleetFilterForm">
            {{-- Keeps the user on the tab they filtered from (filled in by the script below). --}}
            <input type="hidden" name="tab" id="fleetTabInput" value="{{ request('tab') }}">
            <div class="col-md-3">
                <label>From Date</label>
                <input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label>To Date</label>
                <input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label>Vehicle <small class="text-muted">(Vehicle Ledger tab)</small></label>
                <select name="vehicle_id" class="form-control">
                    <option value="">-- All Vehicles --</option>
                    @foreach($vehicles as $veh)
                        <option value="{{ $veh->id }}" {{ request('vehicle_id') == $veh->id ? 'selected' : '' }}>
                            {{ $veh->name }}{{ $veh->vehicle_no ? ' (' . $veh->vehicle_no . ')' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Filter</button>
            </div>
        </form>

        {{-- Vehicle Wise --}}
        <div class="tab-pane fade show active" id="vehicle_wise" role="tabpanel">
            <p class="text-muted small">For a job with more than one vehicle, its total amount is split evenly across those vehicles below (charges are recorded once per job, not per vehicle).</p>
            {{-- Item 12 — real server-generated PDF/Excel export of this tab, using the
                 same From/To filter already applied above. --}}
            <div class="mb-2 no-print">
                <a class="btn btn-danger btn-sm" href="{{ route('reports.fleet.exportPdf', ['report' => 'vehicle_wise', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-pdf"></i> PDF</a>
                <a class="btn btn-success btn-sm" href="{{ route('reports.fleet.exportExcel', ['report' => 'vehicle_wise', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-excel"></i> Excel</a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Vehicle</th>
                            <th>Vehicle No.</th>
                            <th class="text-center">Trips</th>
                            <th class="text-center">Distinct Routes</th>
                            <th class="text-end">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['vehicle_wise'] as $row)
                            <tr>
                                <td>{{ $row['vehicle'] }}</td>
                                <td>{{ $row['vehicle_no'] }}</td>
                                <td class="text-center">{{ $row['trip_count'] }}</td>
                                <td class="text-center">{{ $row['routes_used'] }}</td>
                                <td class="text-end">{{ number_format($row['total_amount'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted">No direct jobs in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Vehicle Ledger --}}
        <div class="tab-pane fade" id="vehicle_ledger" role="tabpanel">
            <p class="text-muted small">Every trip each vehicle ran in the period, oldest first, with a running total. A trip's amount is the job total (each vehicle on a job is billed as the full trip). Party-to-Party jobs aren't listed — they run on the vendor's vehicle. Vehicle expenses aren't shown because no cost or voucher is recorded against a vehicle.</p>
            <div class="mb-2 no-print">
                <a class="btn btn-danger btn-sm" href="{{ route('reports.fleet.exportPdf', ['report' => 'vehicle_ledger', 'from_date' => $from, 'to_date' => $to, 'vehicle_id' => request('vehicle_id')]) }}"><i class="fas fa-file-pdf"></i> PDF</a>
                <a class="btn btn-success btn-sm" href="{{ route('reports.fleet.exportExcel', ['report' => 'vehicle_ledger', 'from_date' => $from, 'to_date' => $to, 'vehicle_id' => request('vehicle_id')]) }}"><i class="fas fa-file-excel"></i> Excel</a>
            </div>
            @forelse($reports['vehicle_ledger'] as $block)
                <h6 class="mt-3">{{ $block['vehicle'] }} <span class="text-muted">({{ $block['vehicle_no'] }})</span></h6>
                <div class="table-responsive mb-3">
                    <table class="table table-bordered table-striped table-sm mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Job No.</th>
                                <th>Customer</th>
                                <th>Route</th>
                                <th>Container #</th>
                                <th>DC #</th>
                                <th>Bill #</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Running Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($block['lines'] as $l)
                                <tr>
                                    <td>{{ $l['date'] }}</td>
                                    <td>{{ $l['job_no'] }}</td>
                                    <td>{{ $l['customer'] }}</td>
                                    <td>{{ $l['route'] }}</td>
                                    <td>{{ $l['container_no'] }}</td>
                                    <td>{{ $l['dc_no'] }}</td>
                                    <td>{!! $l['bill_no'] === 'Unbilled' ? '<span class="text-muted">Unbilled</span>' : e($l['bill_no']) !!}</td>
                                    <td class="text-end">{{ number_format($l['amount'], 2) }}</td>
                                    <td class="text-end">{{ number_format($l['running'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="table-active fw-bold">
                                <td colspan="7" class="text-end">Total — {{ $block['trip_count'] }} trip(s)</td>
                                <td class="text-end">{{ number_format($block['total'], 2) }}</td>
                                <td class="text-end">{{ number_format($block['total'], 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @empty
                <p class="text-muted">No trips for {{ request('vehicle_id') ? 'this vehicle' : 'any vehicle' }} in this period.</p>
            @endforelse
        </div>

        {{-- Customer Wise --}}
        <div class="tab-pane fade" id="customer_wise" role="tabpanel">
            <div class="mb-2 no-print">
                <a class="btn btn-danger btn-sm" href="{{ route('reports.fleet.exportPdf', ['report' => 'customer_wise', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-pdf"></i> PDF</a>
                <a class="btn btn-success btn-sm" href="{{ route('reports.fleet.exportExcel', ['report' => 'customer_wise', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-excel"></i> Excel</a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Customer</th>
                            <th class="text-center">Jobs</th>
                            <th class="text-center">Vehicles Used</th>
                            <th class="text-center">Routes Used</th>
                            <th class="text-end">Total Amount</th>
                            <th class="text-end">Billed</th>
                            <th class="text-end">Unbilled</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['customer_wise'] as $row)
                            <tr>
                                <td>{{ $row['customer'] }}</td>
                                <td class="text-center">{{ $row['job_count'] }}</td>
                                <td class="text-center">{{ $row['vehicles_used'] }}</td>
                                <td class="text-center">{{ $row['routes_used'] }}</td>
                                <td class="text-end">{{ number_format($row['total_amount'], 2) }}</td>
                                <td class="text-end">{{ number_format($row['billed_amount'], 2) }}</td>
                                <td class="text-end">{{ number_format($row['unbilled_amount'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted">No jobs in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Vendor Wise --}}
        <div class="tab-pane fade" id="vendor_wise" role="tabpanel">
            <div class="mb-2 no-print">
                <a class="btn btn-danger btn-sm" href="{{ route('reports.fleet.exportPdf', ['report' => 'vendor_wise', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-pdf"></i> PDF</a>
                <a class="btn btn-success btn-sm" href="{{ route('reports.fleet.exportExcel', ['report' => 'vendor_wise', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-excel"></i> Excel</a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Vendor</th>
                            <th class="text-center">Jobs</th>
                            <th class="text-end">Vendor Cost</th>
                            <th class="text-end">Sale to Customer</th>
                            <th class="text-end">Profit</th>
                            <th class="text-end">Advance Paid</th>
                            <th class="text-end">Guarantee Held</th>
                            <th class="text-end">Balance Payable</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['vendor_wise'] as $row)
                            <tr>
                                <td>{{ $row['vendor'] }}</td>
                                <td class="text-center">{{ $row['job_count'] }}</td>
                                <td class="text-end">{{ number_format($row['total_cost'], 2) }}</td>
                                <td class="text-end">{{ number_format($row['total_sale'], 2) }}</td>
                                <td class="text-end {{ $row['total_profit'] < 0 ? 'text-danger' : 'text-success' }}"><b>{{ number_format($row['total_profit'], 2) }}</b></td>
                                <td class="text-end">{{ number_format($row['total_advance'], 2) }}</td>
                                <td class="text-end">{{ number_format($row['total_guarantee'], 2) }}</td>
                                <td class="text-end">{{ number_format($row['total_balance'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted">No party-to-party jobs in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Route Wise --}}
        <div class="tab-pane fade" id="route_wise" role="tabpanel">
            <div class="mb-2 no-print">
                <a class="btn btn-danger btn-sm" href="{{ route('reports.fleet.exportPdf', ['report' => 'route_wise', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-pdf"></i> PDF</a>
                <a class="btn btn-success btn-sm" href="{{ route('reports.fleet.exportExcel', ['report' => 'route_wise', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-excel"></i> Excel</a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Route</th>
                            <th class="text-center">Trips</th>
                            <th class="text-center">Distinct Vehicles</th>
                            <th class="text-center">Distinct Customers</th>
                            <th class="text-end">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['route_wise'] as $row)
                            <tr>
                                <td>{{ $row['route'] }}</td>
                                <td class="text-center">{{ $row['trip_count'] }}</td>
                                <td class="text-center">{{ $row['vehicles_used'] }}</td>
                                <td class="text-center">{{ $row['customers'] }}</td>
                                <td class="text-end">{{ number_format($row['total_amount'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted">No direct jobs in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Customer x Route Usage --}}
        <div class="tab-pane fade" id="customer_routes" role="tabpanel">
            <div class="mb-2 no-print">
                <a class="btn btn-danger btn-sm" href="{{ route('reports.fleet.exportPdf', ['report' => 'customer_routes', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-pdf"></i> PDF</a>
                <a class="btn btn-success btn-sm" href="{{ route('reports.fleet.exportExcel', ['report' => 'customer_routes', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-excel"></i> Excel</a>
            </div>
            @forelse($reports['customer_routes'] as $group)
                <h6 class="mt-3">{{ $group['customer'] }}</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-bordered table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Route</th>
                                <th class="text-center">Trips</th>
                                <th class="text-end">Total Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($group['routes'] as $r)
                                <tr>
                                    <td>{{ $r['route'] }}</td>
                                    <td class="text-center">{{ $r['trip_count'] }}</td>
                                    <td class="text-end">{{ number_format($r['total_amount'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @empty
                <p class="text-muted">No direct jobs in this period.</p>
            @endforelse
        </div>

        {{-- Company % Share (Item 15a) --}}
        <div class="tab-pane fade" id="company_share" role="tabpanel">
            <p class="text-muted small">A vehicle's (evenly-split) revenue is split evenly again across every company it's linked to. A vehicle linked to no company falls under "Unassigned" so the percentages below always total 100%.</p>
            <div class="mb-2 no-print">
                <a class="btn btn-danger btn-sm" href="{{ route('reports.fleet.exportPdf', ['report' => 'company_share', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-pdf"></i> PDF</a>
                <a class="btn btn-success btn-sm" href="{{ route('reports.fleet.exportExcel', ['report' => 'company_share', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-excel"></i> Excel</a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Company</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['company_share'] as $row)
                            <tr>
                                <td>{{ $row['company'] }}</td>
                                <td class="text-end">{{ number_format($row['amount'], 2) }}</td>
                                <td class="text-end">{{ number_format($row['percent'], 2) }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted">No direct jobs in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Vehicle P&L (Item 15b) --}}
        <div class="tab-pane fade" id="vehicle_pl" role="tabpanel">
            <p class="text-muted small">This app doesn't track vehicle-level costs (no fuel, driver wage, or maintenance records exist), so Cost and Profit show as N/A rather than assuming zero cost. This is a revenue-per-vehicle view, not a true P&amp;L, until cost tracking is added.</p>
            <div class="mb-2 no-print">
                <a class="btn btn-danger btn-sm" href="{{ route('reports.fleet.exportPdf', ['report' => 'vehicle_pl', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-pdf"></i> PDF</a>
                <a class="btn btn-success btn-sm" href="{{ route('reports.fleet.exportExcel', ['report' => 'vehicle_pl', 'from_date' => $from, 'to_date' => $to]) }}"><i class="fas fa-file-excel"></i> Excel</a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Vehicle</th>
                            <th>Vehicle No.</th>
                            <th class="text-center">Trips</th>
                            <th class="text-end">Revenue</th>
                            <th class="text-end">Cost</th>
                            <th class="text-end">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['vehicle_pl'] as $row)
                            <tr>
                                <td>{{ $row['vehicle'] }}</td>
                                <td>{{ $row['vehicle_no'] }}</td>
                                <td class="text-center">{{ $row['trip_count'] }}</td>
                                <td class="text-end">{{ number_format($row['revenue'], 2) }}</td>
                                <td class="text-end text-muted">{{ $row['cost'] ?? 'N/A' }}</td>
                                <td class="text-end text-muted">{{ $row['profit'] ?? 'N/A' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted">No direct jobs in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form  = document.getElementById('fleetFilterForm');
    var input = document.getElementById('fleetTabInput');

    // Re-open the tab the page was filtered from (?tab=…).
    try {
        var tab = new URLSearchParams(window.location.search).get('tab');
        var el  = tab ? document.querySelector('#fleetReportTabs .nav-link[href="#' + tab + '"]') : null;
        if (el && typeof bootstrap !== 'undefined') { new bootstrap.Tab(el).show(); }
    } catch (e) {}

    // Remember which tab is open when Filter is pressed.
    form.addEventListener('submit', function () {
        var active = document.querySelector('#fleetReportTabs .nav-link.active');
        if (active) { input.value = active.getAttribute('href').replace('#', ''); }
    });
});
</script>
@endsection