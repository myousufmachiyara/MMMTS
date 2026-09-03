@extends('layouts.app')

@section('title', 'Payments | Add Payment')

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

<section class="card">
  <header class="card-header"><h2 class="card-title">Add Payment</h2></header>
  <form method="POST" action="{{ route('payments.store') }}" id="paymentForm">
    @csrf
    <div class="card-body">
      <div class="row form-group">
        <div class="col-lg-4 mb-2">
          <label>Invoice <span class="text-danger">*</span></label>
          <select class="form-control select2-js" id="invoice_id" name="invoice_id" required>
            <option value="" disabled selected>Select Invoice</option>
            @foreach($invoices as $inv)
              @php
                  $badge = ['pending' => 'Pending', 'partial' => 'Partial', 'cleared' => 'Cleared'];
              @endphp
              <option value="{{ $inv->id }}" data-balance="{{ $inv->balance_amount }}">
                {{ $inv->invoice_no }} — {{ $inv->customer->name ?? '—' }} — Balance: {{ number_format($inv->balance_amount, 2) }} ({{ $badge[$inv->status] ?? $inv->status }})
              </option>
            @endforeach
          </select>
          <small class="text-muted">Outstanding balance: <span id="balanceDisplay">—</span></small>
        </div>
        <div class="col-lg-2 mb-2">
          <label>Payment Date <span class="text-danger">*</span></label>
          <input type="date" class="form-control" name="payment_date" value="{{ date('Y-m-d') }}" required>
        </div>
        <div class="col-lg-2 mb-2">
          <label>Total Amount <span class="text-danger">*</span></label>
          <input type="number" step="any" class="form-control" id="amount" name="amount" readonly>
        </div>
        <div class="col-lg-4 mb-2">
          <label>Remarks</label>
          <input type="text" class="form-control" name="remarks" placeholder="Remarks">
        </div>
      </div>

      <hr>
      <h5>Payment Methods <small class="text-muted">(split across cash / cheque / online transfer / benefit as needed — must total the amount above)</small></h5>
      <div class="table-responsive">
        <table class="table table-bordered mb-2" id="linesTable">
          <thead>
            <tr>
              <th style="width:16%">Method</th>
              <th>Account</th>
              <th style="width:14%">Amount</th>
              <th style="width:14%">Cheque No.</th>
              <th style="width:14%">Cheque Date</th>
              <th style="width:16%">Reference</th>
              <th style="width:3%"></th>
            </tr>
          </thead>
          <tbody id="linesBody"></tbody>
        </table>
        <button type="button" class="btn btn-sm btn-secondary" id="addLineBtn"><i class="fas fa-plus"></i> Add Method</button>
        <span class="float-end">Lines Total: <strong id="linesTotal">0.00</strong></span>
      </div>
    </div>
    <footer class="card-footer text-end">
      <button type="submit" class="btn btn-primary">Save Payment</button>
      <a href="{{ route('payments.index') }}" class="btn btn-default">Cancel</a>
    </footer>
  </form>
</section>

@include('layouts.partials.modal-scripts')

@php
    // Computed here rather than inline inside @json() below — Blade's @json()
    // splits its raw argument text on every comma looking for an optional
    // encoding-options argument, with no awareness of nesting, so a
    // multi-key array-map expression can be silently mis-split. A bare
    // variable has no top-level comma and is always safe.
    $accountsData = $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name]);
@endphp

<script>
var accounts = @json($accountsData);
var lineIndex = 0;

function accountOptionsHtml() {
    var html = '<option value="" disabled selected>Select Account</option>';
    accounts.forEach(function(a) { html += '<option value="' + a.id + '">' + a.name + '</option>'; });
    return html;
}

function addLine() {
    var i = lineIndex++;
    var tr = document.createElement('tr');
    tr.dataset.index = i;
    tr.innerHTML =
        '<td><select class="form-control line-method" name="lines[' + i + '][method]" required>' +
            '<option value="cash">Cash</option>' +
            '<option value="cheque">Cheque</option>' +
            '<option value="online_transfer">Online Transfer</option>' +
            '<option value="benefit">Benefit / In-Kind</option>' +
        '</select></td>' +
        '<td><select class="form-control select2-js line-account" name="lines[' + i + '][account_id]" required>' + accountOptionsHtml() + '</select></td>' +
        '<td><input type="number" step="any" class="form-control line-amount" name="lines[' + i + '][amount]" required></td>' +
        '<td><input type="text" class="form-control line-cheque-no" name="lines[' + i + '][cheque_no]" disabled></td>' +
        '<td><input type="date" class="form-control line-cheque-date" name="lines[' + i + '][cheque_date]" disabled></td>' +
        '<td><input type="text" class="form-control line-reference" name="lines[' + i + '][reference]" placeholder="e.g. Fuel Card #1234"></td>' +
        '<td><button type="button" class="btn btn-link text-danger p-0 remove-line"><i class="fas fa-times"></i></button></td>';
    document.getElementById('linesBody').appendChild(tr);
    initSelect2(tr);
}

document.getElementById('addLineBtn').addEventListener('click', addLine);

document.getElementById('linesBody').addEventListener('change', function(e) {
    if (e.target.classList.contains('line-method')) {
        var tr = e.target.closest('tr');
        var isCheque = e.target.value === 'cheque';
        tr.querySelector('.line-cheque-no').disabled = !isCheque;
        tr.querySelector('.line-cheque-date').disabled = !isCheque;
    }
    if (e.target.classList.contains('line-amount')) recalcLines();
});

document.getElementById('linesBody').addEventListener('click', function(e) {
    if (e.target.closest('.remove-line')) {
        e.target.closest('tr').remove();
        recalcLines();
    }
});

function recalcLines() {
    var total = 0;
    document.querySelectorAll('.line-amount').forEach(function(el) { total += parseFloat(el.value) || 0; });
    document.getElementById('linesTotal').textContent = total.toFixed(2);
    document.getElementById('amount').value = total.toFixed(2);
}

document.getElementById('invoice_id').addEventListener('change', function() {
    var balance = $(this).find(':selected').data('balance') || 0;
    document.getElementById('balanceDisplay').textContent = parseFloat(balance).toFixed(2);
});

document.getElementById('paymentForm').addEventListener('submit', function(e) {
    if (!document.querySelectorAll('#linesBody tr').length) {
        e.preventDefault();
        alert('Add at least one payment method line.');
        return;
    }
    var total = parseFloat(document.getElementById('amount').value) || 0;
    if (total <= 0) {
        e.preventDefault();
        alert('Enter an amount greater than zero on at least one line.');
    }
});

// Start with one line pre-added
addLine();
</script>
@endsection