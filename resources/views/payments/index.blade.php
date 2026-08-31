@extends('layouts.app')

@section('title', 'Payments')

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
        <h2 class="card-title">Payments</h2>
        @can('payments.create')
          <a href="{{ route('payments.create') }}" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Payment
          </a>
        @endcan
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="payments-datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>Payment No.</th>
                <th>Invoice No.</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Amount</th>
                <th>Methods</th>
                <th>Accounts</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($payments as $index => $row)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td><code>{{ $row->payment_no }}</code></td>
                  <td>{{ $row->invoice->invoice_no ?? '—' }}</td>
                  <td>{{ $row->invoice->customer->name ?? '—' }}</td>
                  <td>{{ $row->payment_date->format('d-m-Y') }}</td>
                  <td class="text-end">{{ number_format($row->amount, 2) }}</td>
                  <td>
                    @foreach ($row->lines as $line)
                      <span class="badge bg-light text-dark border">{{ ucfirst(str_replace('_', ' ', $line->method)) }}: {{ number_format($line->amount, 2) }}</span>
                    @endforeach
                  </td>
                  <td>{{ $row->lines->pluck('account.name')->filter()->implode(', ') ?: '—' }}</td>
                  <td class="actions">
                    @can('payments.print')
                      <a class="text-success me-1" href="{{ route('payments.print', $row->id) }}" target="_blank" title="Print">
                          <i class="fas fa-print"></i>
                      </a>
                    @endcan
                    @can('payments.delete')
                      <form action="{{ route('payments.destroy', $row->id) }}" method="POST" style="display:inline;"
                            onsubmit="return confirm('Delete payment {{ $row->payment_no }}? This reverses it from the invoice.');">
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
    $('#payments-datatable').DataTable({ pageLength: 50, order: [] });
});
</script>
@endsection