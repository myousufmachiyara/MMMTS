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
            'customer_wise'   => 'Customer Wise',
            'vendor_wise'     => 'Vendor Wise (Party-to-Party)',
            'route_wise'      => 'Route Wise',
            'customer_routes' => 'Customer × Route Usage',
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
        <form method="GET" action="{{ route('reports.fleet') }}" class="row g-2 mb-3 no-print">
            <div class="col-md-3">
                <label>From Date</label>
                <input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label>To Date</label>
                <input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control" required>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Filter</button>
            </div>
        </form>

        {{-- Vehicle Wise --}}
        <div class="tab-pane fade show active" id="vehicle_wise" role="tabpanel">
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

        {{-- Customer Wise --}}
        <div class="tab-pane fade" id="customer_wise" role="tabpanel">
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

    </div>
</div>
@endsection