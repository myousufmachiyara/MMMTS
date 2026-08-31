@extends('layouts.app')

@section('title', 'Bills')

@section('content')

@if(session('success'))
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('error') }}
    </div>
@endif

<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Bills</h2>
        @can('bills.create')
          <a href="{{ route('bills.create') }}" class="btn btn-primary">
            <i class="fas fa-plus"></i> Create Bill
          </a>
        @endcan
      </header>

      <div class="card-body">
        <form method="GET" action="{{ route('bills.index') }}" class="row form-group mb-3">
          <div class="col-lg-3 mb-2">
            <label>Customer</label>
            <select name="customer_id" class="form-control select2-js">
              <option value="all">All</option>
              @foreach($customers as $c)
                <option value="{{ $c->id }}" {{ request('customer_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-lg-3 mb-2">
            <label>Status</label>
            <select name="invoiced" class="form-control">
              <option value="all">All</option>
              <option value="invoiced" {{ request('invoiced') == 'invoiced' ? 'selected' : '' }}>Invoiced</option>
              <option value="pending" {{ request('invoiced') == 'pending' ? 'selected' : '' }}>Pending</option>
            </select>
          </div>
          <div class="col-lg-1 mb-2 d-flex align-items-end">
            <button type="submit" class="btn btn-secondary w-100">Filter</button>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="bills-datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>Bill No.</th>
                <th>Customer</th>
                <th>Period</th>
                <th>Bill Date</th>
                <th>Containers</th>
                <th>Total</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($bills as $index => $row)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td><code>{{ $row->bill_no }}</code></td>
                  <td>{{ $row->customer->name ?? '—' }}</td>
                  <td>{{ $row->from_date->format('d-m-Y') }} — {{ $row->to_date->format('d-m-Y') }}</td>
                  <td>{{ $row->bill_date->format('d-m-Y') }}</td>
                  <td class="text-center">{{ $row->jobs_count ?? $row->jobs->count() }}</td>
                  <td class="text-end">{{ number_format($row->total_amount, 2) }}</td>
                  <td>
                    <span class="badge {{ $row->invoice_id ? 'bg-success' : 'bg-warning text-dark' }}">
                        {{ $row->invoice_id ? 'Invoiced' : 'Pending' }}
                    </span>
                  </td>
                  <td class="actions">
                    @can('bills.print')
                      <a class="text-success me-1" href="{{ route('bills.print', $row->id) }}" target="_blank" title="Print">
                          <i class="fas fa-print"></i>
                      </a>
                    @endcan
                    @can('bills.delete')
                      @php
                          $confirmMsg = $row->invoice_id
                              ? "Delete bill {$row->bill_no}? It is on an invoice — that invoice AND any payments recorded against it will be reversed and voided too. This cannot be undone."
                              : "Delete bill {$row->bill_no}? This releases its jobs back to Non-Billed.";
                      @endphp
                      <form action="{{ route('bills.destroy', $row->id) }}" method="POST" style="display:inline;"
                            onsubmit="return confirm('{{ $confirmMsg }}');">
                          @csrf
                          @method('DELETE')
                          <button type="submit" class="btn btn-link p-0 text-danger" title="Delete">
                              <i class="fas fa-trash-alt"></i>
                          </button>
                      </form>
                    @endcan
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</div>

@include('layouts.partials.modal-scripts')

<script>
$(document).ready(function() {
    $('#bills-datatable').DataTable({ pageLength: 50, order: [] });
});
</script>
@endsection