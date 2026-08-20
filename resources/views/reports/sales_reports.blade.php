@extends('layouts.app')
@section('title', 'Sales Reports')

@section('content')
<style>
@media print { .no-print { display: none !important; } }
.ref-link { text-decoration: none; font-weight: 500; }
.ref-link:hover { text-decoration: underline; }
</style>

<div class="tabs">
    <ul class="nav nav-tabs">
        <li class="nav-item">
            <a class="nav-link {{ $tab==='SR'   ? 'active' : '' }}"
               href="{{ route('reports.sale', ['tab'=>'SR',  'from_date'=>$from,'to_date'=>$to]) }}">
               Sales Register
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab==='SRET' ? 'active' : '' }}"
               href="{{ route('reports.sale', ['tab'=>'SRET','from_date'=>$from,'to_date'=>$to]) }}">
               Sales Return
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab==='CW'   ? 'active' : '' }}"
               href="{{ route('reports.sale', ['tab'=>'CW',  'from_date'=>$from,'to_date'=>$to]) }}">
               Customer Wise
            </a>
        </li>
    </ul>

    <div class="tab-content mt-3">

        {{-- ── SALES REGISTER ──────────────────────────────────── --}}
        <div id="SR" class="tab-pane fade {{ $tab==='SR' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.sale') }}" class="no-print">
                <input type="hidden" name="tab" value="SR">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" class="form-control" name="from_date" value="{{ $from }}">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" class="form-control" name="to_date" value="{{ $to }}">
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('sr-table', 'Sales Register', '{{ $from }} to {{ $to }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            <div id="sr-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th><th>Invoice</th><th>Customer</th>
                            <th class="text-end">Total</th>
                            <th class="no-print text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($sales as $row)
                        <tr>
                            <td>{{ $row->date }}</td>
                            <td>
                                {{-- Clickable invoice number --}}
                                <a href="{{ route('sale_invoices.print', $row->id ?? 0) }}"
                                   target="_blank" class="ref-link text-primary">
                                    SI-{{ str_pad($row->invoice, 6, '0', STR_PAD_LEFT) }}
                                </a>
                            </td>
                            <td>{{ $row->customer }}</td>
                            <td class="text-end fw-bold">{{ number_format($row->revenue ?? $row->total, 2) }}</td>
                            <td class="text-center no-print">
                                <a href="{{ route('sale_invoices.print', $row->id ?? 0) }}"
                                   target="_blank" class="btn btn-outline-success btn-sm" title="Print">
                                    <i class="fas fa-print"></i>
                                </a>
                                <a href="{{ route('sale_invoices.edit', $row->id ?? 0) }}"
                                   class="btn btn-outline-primary btn-sm ms-1" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted">No sales found.</td></tr>
                    @endforelse
                    </tbody>
                    @if($sales->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="3" class="text-end">Grand Total:</td>
                            <td class="text-end">{{ number_format($sales->sum(fn($r) => $r->revenue ?? $r->total), 2) }}</td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── SALES RETURN ─────────────────────────────────────── --}}
        <div id="SRET" class="tab-pane fade {{ $tab==='SRET' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.sale') }}" class="no-print">
                <input type="hidden" name="tab" value="SRET">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" class="form-control" name="from_date" value="{{ $from }}">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" class="form-control" name="to_date" value="{{ $to }}">
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('sret-table', 'Sales Returns', '{{ $from }} to {{ $to }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            <div id="sret-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th><th>Return No</th><th>Customer</th>
                            <th class="text-end">Total Return</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($returns as $row)
                        <tr>
                            <td>{{ $row->date }}</td>
                            <td>SR-{{ $row->invoice }}</td>
                            <td>{{ $row->customer }}</td>
                            <td class="text-end fw-bold">{{ number_format($row->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">No returns found.</td></tr>
                    @endforelse
                    </tbody>
                    @if($returns->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="3" class="text-end">Grand Total:</td>
                            <td class="text-end">{{ number_format($returns->sum('total'), 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── CUSTOMER WISE ────────────────────────────────────── --}}
        <div id="CW" class="tab-pane fade {{ $tab==='CW' ? 'show active' : '' }}">
            <form method="GET" action="{{ route('reports.sale') }}" class="no-print">
                <input type="hidden" name="tab" value="CW">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label>From Date</label>
                        <input type="date" class="form-control" name="from_date" value="{{ $from }}">
                    </div>
                    <div class="col-md-3">
                        <label>To Date</label>
                        <input type="date" class="form-control" name="to_date" value="{{ $to }}">
                    </div>
                    <div class="col-md-3">
                        <label>Customer</label>
                        <select name="customer_id" class="form-control">
                            <option value="">All Customers</option>
                            @foreach($customers as $cust)
                                <option value="{{ $cust->id }}" {{ $customerId == $cust->id ? 'selected' : '' }}>
                                    {{ $cust->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <button type="button" class="btn btn-danger"
                                onclick="exportPDF('cw-table', 'Customer-wise Sales', '{{ $from }} to {{ $to }}')">
                            <i class="fas fa-file-pdf"></i>
                        </button>
                    </div>
                </div>
            </form>

            <div id="cw-table">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Customer Name</th>
                            <th class="text-center">No. of Invoices</th>
                            <th class="text-end">Total Revenue (Net)</th>
                        </tr>
                    </thead>
                    <tbody>
                    @php $grandTotal = 0; @endphp
                    @forelse($customerWise as $row)
                        <tr>
                            <td>{{ $row->customer }}</td>
                            <td class="text-center">{{ $row->count }}</td>
                            <td class="text-end fw-bold">{{ number_format($row->total, 2) }}</td>
                        </tr>
                        @php $grandTotal += $row->total; @endphp
                    @empty
                        <tr><td colspan="3" class="text-center text-muted">No sales data found.</td></tr>
                    @endforelse
                    </tbody>
                    @if($customerWise->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="2" class="text-end">Grand Total:</td>
                            <td class="text-end text-primary">{{ number_format($grandTotal, 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

    </div>
</div>

<script>
function exportPDF(tableId, title, period) {
    const el = document.getElementById(tableId);
    if (!el) return;
    const clone = el.cloneNode(true);
    clone.querySelectorAll('.no-print').forEach(e => e.remove());
    clone.querySelectorAll('a').forEach(a => {
        const span = document.createElement('span');
        span.textContent = a.textContent.trim();
        a.replaceWith(span);
    });
    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${title}</title>
    <style>
        body{font-family:Arial,sans-serif;font-size:11px;margin:20px}
        h2{font-size:14px;margin-bottom:4px}p{font-size:10px;color:#555;margin:0 0 10px}
        table{width:100%;border-collapse:collapse}
        th{background:#1a1a2e;color:#fff;padding:5px 7px;text-align:left}
        td{padding:4px 7px;border-bottom:0.5px solid #ddd}
        tr:nth-child(even) td{background:#f9f9f9}
        .text-end{text-align:right}.text-center{text-align:center}.fw-bold{font-weight:bold}
        tfoot td{background:#f0f0f0;font-weight:bold}
    </style></head><body>
    <h2>${title}</h2><p>${period}</p>
    ${clone.innerHTML}
    <script>window.onload=function(){window.print();}<\/script>
    </body></html>`;
    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(html);
    win.document.close();
}
</script>
@endsection