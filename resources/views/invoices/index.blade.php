@extends('layouts.app')

@section('title', 'Invoices')

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
        <h2 class="card-title">Invoices</h2>
        @can('invoices.create')
          <a href="{{ route('invoices.create') }}" class="btn btn-primary">
            <i class="fas fa-plus"></i> Create Invoice
          </a>
        @endcan
      </header>

      <div class="card-body">
        <form method="GET" action="{{ route('invoices.index') }}" class="row form-group mb-3">
          <div class="col-lg-4 mb-2">
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
            <select name="status" class="form-control">
              <option value="all">All</option>
              <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
              <option value="partial" {{ request('status') == 'partial' ? 'selected' : '' }}>Partial</option>
              <option value="cleared" {{ request('status') == 'cleared' ? 'selected' : '' }}>Cleared</option>
            </select>
          </div>
          <div class="col-lg-1 mb-2 d-flex align-items-end">
            <button type="submit" class="btn btn-secondary w-100">Filter</button>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="invoices-datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>Invoice No.</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Containers</th>
                <th>Tax</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Balance</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($invoices as $index => $row)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td><code>{{ $row->invoice_no }}</code></td>
                  <td>{{ $row->customer->name ?? '—' }}</td>
                  <td>{{ $row->invoice_date->format('d-m-Y') }}</td>
                  <td class="text-center">{{ $row->total_containers }}</td>
                  <td>
                    @if($row->is_taxable)
                      <span class="badge bg-info">{{ rtrim(rtrim(number_format($row->tax_percent, 2), '0'), '.') }}%</span>
                    @else
                      <span class="badge bg-secondary">Non-Taxable</span>
                    @endif
                  </td>
                  <td class="text-end">{{ number_format($row->total_amount, 2) }}</td>
                  <td class="text-end">{{ number_format($row->paid_amount, 2) }}</td>
                  <td class="text-end">{{ number_format($row->balance_amount, 2) }}</td>
                  <td>
                    @php
                        $badge = ['pending' => 'bg-warning text-dark', 'partial' => 'bg-info', 'cleared' => 'bg-success'];
                    @endphp
                    <span class="badge {{ $badge[$row->status] ?? 'bg-secondary' }}">{{ ucfirst($row->status) }}</span>
                  </td>
                  <td class="actions">
                    <a class="text-success me-1" href="{{ route('invoices.print', $row->id) }}" target="_blank" title="Print">
                        <i class="fas fa-print"></i>
                    </a>
                    @can('invoices.delete')
                      @php
                          $confirmMsg = $row->paid_amount > 0
                              ? "Delete invoice {$row->invoice_no}? It has payments recorded — they will ALL be reversed (vouchers removed) and its bills released back to Pending. This cannot be undone."
                              : "Delete invoice {$row->invoice_no}? This releases its bills back to Pending.";
                      @endphp
                      <form action="{{ route('invoices.destroy', $row->id) }}" method="POST" style="display:inline;"
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
    $('#invoices-datatable').DataTable({ pageLength: 50, order: [] });
});
</script>
@endsection