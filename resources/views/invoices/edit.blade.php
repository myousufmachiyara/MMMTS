@extends('layouts.app')

@section('title', 'Invoices | Edit Invoice')

@section('content')

@if(session('error'))
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('error') }}
    </div>
@endif
@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<section class="card mb-3">
  <header class="card-header"><h2 class="card-title">Edit Invoice {{ $invoice->invoice_no }}</h2></header>
  <div class="card-body">
    <div class="row form-group">
      <div class="col-lg-3 mb-2">
        <label>Customer</label>
        <input type="text" class="form-control" value="{{ $invoice->customer->name ?? '' }}" disabled>
        <input type="hidden" id="customer_id" value="{{ $invoice->customer_id }}">
      </div>
      <div class="col-lg-2 mb-2">
        <label>From Date <span class="text-danger">*</span></label>
        <input type="date" id="from_date" class="form-control" value="{{ $invoice->from_date->format('Y-m-d') }}">
      </div>
      <div class="col-lg-2 mb-2">
        <label>To Date <span class="text-danger">*</span></label>
        <input type="date" id="to_date" class="form-control" value="{{ $invoice->to_date->format('Y-m-d') }}">
      </div>
      <div class="col-lg-2 mb-2 d-flex align-items-end">
        <button type="button" id="getBillsBtn" class="btn btn-secondary w-100">Reload Bills</button>
      </div>
    </div>
  </div>
</section>

<form method="POST" action="{{ route('invoices.update', $invoice->id) }}" id="invoiceForm">
  @csrf
  @method('PUT')
  <input type="hidden" name="from_date" id="form_from_date" value="{{ $invoice->from_date->format('Y-m-d') }}">
  <input type="hidden" name="to_date" id="form_to_date" value="{{ $invoice->to_date->format('Y-m-d') }}">

  <section class="card mb-3" id="billsCard">
    <header class="card-header"><h2 class="card-title">Bills (on this invoice + pending)</h2></header>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered mb-0" id="billsPickTable">
          <thead>
            <tr>
              <th style="width:3%"><input type="checkbox" id="checkAll"></th>
              <th>Bill No.</th>
              <th>Bill Date</th>
              <th class="text-center">Containers</th>
              <th class="text-end">Trip Plan</th>
              <th class="text-end">Amount</th>
            </tr>
          </thead>
          <tbody id="billsPickBody"></tbody>
        </table>
        <p class="text-muted mb-0" id="noBillsMsg" style="display:none;">No bills found.</p>
      </div>
    </div>
  </section>

  <section class="card mb-3" id="invoiceDetailsCard">
    <header class="card-header"><h2 class="card-title">Invoice Details</h2></header>
    <div class="card-body">
      <div class="row form-group">
        <div class="col-lg-3 mb-2">
          <label>Invoice Date <span class="text-danger">*</span></label>
          <input type="date" name="invoice_date" class="form-control" value="{{ old('invoice_date', $invoice->invoice_date->format('Y-m-d')) }}" required>
        </div>
        <div class="col-lg-3 mb-2">
          <label>Taxable?</label>
          <select name="is_taxable" id="is_taxable" class="form-control">
            <option value="0" {{ !old('is_taxable', $invoice->is_taxable) ? 'selected' : '' }}>Non-Taxable</option>
            <option value="1" {{ old('is_taxable', $invoice->is_taxable) ? 'selected' : '' }}>Taxable</option>
          </select>
        </div>
        <div class="col-lg-2 mb-2" id="taxPercentWrap" style="display:none;">
          <label>Tax %</label>
          <input type="number" step="any" name="tax_percent" id="tax_percent" class="form-control" value="{{ old('tax_percent', $invoice->tax_percent ?? 18) }}">
        </div>
        <div class="col-lg-2 mb-2" id="customerTaxShareWrap" style="display:none;">
          <label>Customer's Share of Tax %</label>
          <input type="number" step="any" min="0" max="100" name="customer_tax_share_percent" id="customer_tax_share_percent" class="form-control" value="{{ old('customer_tax_share_percent', $invoice->customer_tax_share_percent ?? 20) }}">
        </div>
      </div>
      <table class="table table-borderless w-auto ms-auto mt-2 mb-0">
        <tr><td class="text-end pe-3">Bills Subtotal (Grand Total, tax base):</td><td class="text-end" id="sumBills">0.00</td></tr>
        <tr><td class="text-end pe-3">Sales Tax (100%):</td><td class="text-end" id="sumTax">0.00</td></tr>
        <tr class="fw-bold"><td class="text-end pe-3">Total Invoice Amount:</td><td class="text-end" id="sumTotal">0.00</td></tr>
        <tr><td class="text-end pe-3">Total Containers:</td><td class="text-end" id="sumContainers">0</td></tr>
      </table>
      <p class="text-muted small mt-1 mb-0" id="taxNote"></p>
      <div class="row form-group mt-2">
        <div class="col-lg-9 mb-2">
          <label>Remarks</label>
          <textarea class="form-control" name="remarks" rows="2">{{ old('remarks', $invoice->remarks) }}</textarea>
        </div>
      </div>
    </div>
    <footer class="card-footer text-end">
      <button type="submit" class="btn btn-primary">Update Invoice</button>
      <a href="{{ route('invoices.index') }}" class="btn btn-default">Cancel</a>
    </footer>
  </section>
</form>

@include('layouts.partials.modal-scripts')

<script>
function fmt(n) { return (Math.round(n * 100) / 100).toFixed(2); }

// Bills currently selected (starts with the invoice's own bills).
var selectedIds = new Set(@json($invoice->bills->pluck('id')->map(fn ($v) => (int) $v)->values()));

function loadBills() {
    var customerId = document.getElementById('customer_id').value;
    var fromDate = document.getElementById('from_date').value;
    var toDate = document.getElementById('to_date').value;

    if (!fromDate || !toDate) {
        alert('Please select from date and to date first.');
        return;
    }

    // Remember what is ticked right now so a reload doesn't lose the selection.
    var rows = document.querySelectorAll('.bill-check');
    if (rows.length) {
        selectedIds = new Set();
        document.querySelectorAll('.bill-check:checked').forEach(function(cb) { selectedIds.add(parseInt(cb.value, 10)); });
    }

    fetch('{{ route('invoices.getBills') }}?customer_id=' + customerId + '&from_date=' + fromDate + '&to_date=' + toDate + '&invoice_id={{ $invoice->id }}', {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) { return res.json(); })
    .then(function(bills) {
        document.getElementById('form_from_date').value = fromDate;
        document.getElementById('form_to_date').value = toDate;

        var tbody = document.getElementById('billsPickBody');
        tbody.innerHTML = '';
        bills.forEach(function(b) {
            var tr = document.createElement('tr');
            var checked = selectedIds.has(b.id) ? 'checked' : '';
            tr.innerHTML =
                '<td><input type="checkbox" class="bill-check" name="bill_ids[]" value="' + b.id + '" data-amount="' + b.total_amount + '" data-jobs="' + b.container_count + '" ' + checked + '></td>' +
                '<td>' + b.bill_no + '</td>' +
                '<td>' + b.bill_date + '</td>' +
                '<td class="text-center">' + b.container_count + '</td>' +
                '<td class="text-end">' + fmt(b.trip_plan_subtotal) + '</td>' +
                '<td class="text-end">' + fmt(b.total_amount) + '</td>';
            tbody.appendChild(tr);
        });

        document.getElementById('noBillsMsg').style.display = bills.length ? 'none' : '';
        recalcTotal();
    })
    .catch(function() { alert('Could not load bills. Please try again.'); });
}

document.getElementById('getBillsBtn').addEventListener('click', loadBills);

document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('.bill-check').forEach(function(cb) { cb.checked = this.checked; }.bind(this));
    recalcTotal();
});
document.getElementById('billsPickBody').addEventListener('change', function(e) {
    if (e.target.classList.contains('bill-check')) recalcTotal();
});

function toggleTaxField() {
    var isTaxable = document.getElementById('is_taxable').value === '1';
    document.getElementById('taxPercentWrap').style.display = isTaxable ? '' : 'none';
    document.getElementById('customerTaxShareWrap').style.display = isTaxable ? '' : 'none';
    recalcTotal();
}
document.getElementById('is_taxable').addEventListener('change', toggleTaxField);
document.getElementById('tax_percent').addEventListener('input', recalcTotal);
document.getElementById('customer_tax_share_percent').addEventListener('input', recalcTotal);

function recalcTotal() {
    var billsSum = 0, containers = 0;
    document.querySelectorAll('.bill-check:checked').forEach(function(cb) {
        billsSum += parseFloat(cb.dataset.amount);
        containers += parseInt(cb.dataset.jobs, 10) || 0;
    });
    var isTaxable = document.getElementById('is_taxable').value === '1';
    var taxPct = parseFloat(document.getElementById('tax_percent').value) || 0;
    var taxAmount = isTaxable ? (billsSum * taxPct / 100) : 0;
    var total = billsSum + taxAmount; // 100% of the tax is receivable

    document.getElementById('sumBills').textContent = fmt(billsSum);
    document.getElementById('sumTax').textContent = fmt(taxAmount);
    document.getElementById('sumTotal').textContent = fmt(total);
    document.getElementById('sumContainers').textContent = containers;
    document.getElementById('taxNote').textContent = isTaxable
        ? ('Sales Tax of ' + taxPct + '% is calculated on the grand total and included in full in the Total Invoice Amount.')
        : 'This invoice will not include Sales Tax.';
}

document.getElementById('invoiceForm').addEventListener('submit', function(e) {
    if (!document.querySelectorAll('.bill-check:checked').length) {
        e.preventDefault();
        alert('Select at least one bill for this invoice.');
    }
});

toggleTaxField();
loadBills();
</script>
@endsection