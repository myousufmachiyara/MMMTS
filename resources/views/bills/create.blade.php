@extends('layouts.app')

@section('title', 'Bills | Create Bill')

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
  <header class="card-header"><h2 class="card-title">Create Bill</h2></header>
  <div class="card-body">
    <div class="row form-group">
      <div class="col-lg-3 mb-2">
        <label>Customer <span class="text-danger">*</span></label>
        <select id="customer_id" class="form-control select2-js">
          <option value="" disabled selected>Select Customer</option>
          @foreach($customers as $c)
            <option value="{{ $c->id }}">{{ $c->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-lg-2 mb-2">
        <label>From Date <span class="text-danger">*</span></label>
        <input type="date" id="from_date" class="form-control">
      </div>
      <div class="col-lg-2 mb-2">
        <label>To Date <span class="text-danger">*</span></label>
        <input type="date" id="to_date" class="form-control" value="{{ date('Y-m-d') }}">
      </div>
      <div class="col-lg-2 mb-2 d-flex align-items-end">
        <button type="button" id="getJobsBtn" class="btn btn-secondary w-100">Get Jobs</button>
      </div>
    </div>
  </div>
</section>

<form method="POST" action="{{ route('bills.store') }}" id="billForm">
  @csrf
  <input type="hidden" name="customer_id" id="form_customer_id">
  <input type="hidden" name="from_date" id="form_from_date">
  <input type="hidden" name="to_date" id="form_to_date">

  <section class="card mb-3" id="jobsCard" style="display:none;">
    <header class="card-header"><h2 class="card-title">Non-Billed Jobs</h2></header>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered mb-0" id="jobsPickTable">
          <thead>
            <tr>
              <th style="width:3%"><input type="checkbox" id="checkAll"></th>
              <th>Job No.</th>
              <th>Date</th>
              <th>Vehicle(s)</th>
              <th>Route(s)</th>
              <th class="text-end">Retention Charges</th>
              <th class="text-end">Other Charges</th>
              <th class="text-end">Job Total</th>
            </tr>
          </thead>
          <tbody id="jobsPickBody"></tbody>
        </table>
        <p class="text-muted mb-0" id="noJobsMsg" style="display:none;">No non-billed jobs found for this customer in the selected date range.</p>
      </div>
    </div>
  </section>

  <section class="card mb-3" id="billDetailsCard" style="display:none;">
    <header class="card-header"><h2 class="card-title">Bill Details</h2></header>
    <div class="card-body">
      <div class="row form-group">
        <div class="col-lg-3 mb-2">
          <label>Bill Date <span class="text-danger">*</span></label>
          <input type="date" name="bill_date" id="bill_date" class="form-control" value="{{ date('Y-m-d') }}" required>
        </div>
        <div class="col-lg-3 mb-2 d-flex align-items-end">
          <span class="text-muted">Containers/Jobs selected: <strong id="containerCount">0</strong></span>
        </div>
      </div>
      <table class="table table-borderless w-auto ms-auto mt-2 mb-0">
        <tr><td class="text-end pe-3">Retention Charges Subtotal:</td><td class="text-end" id="sumRetention">0.00</td></tr>
        <tr><td class="text-end pe-3">Other Charges Subtotal:</td><td class="text-end" id="sumOther">0.00</td></tr>
        <tr class="fw-bold"><td class="text-end pe-3">Total Bill Amount (Grand Total):</td><td class="text-end" id="sumTotal">0.00</td></tr>
      </table>
      <p class="text-muted small mt-1 mb-0">Tax (if applicable) is applied later, at Invoice stage, on the combined grand total of the bills selected.</p>
      <div class="row form-group mt-2">
        <div class="col-lg-9 mb-2">
          <label>Remarks</label>
          <textarea class="form-control" name="remarks" rows="2"></textarea>
        </div>
      </div>
    </div>
    <footer class="card-footer text-end">
      <button type="submit" class="btn btn-primary">Save Bill</button>
      <a href="{{ route('bills.index') }}" class="btn btn-default">Cancel</a>
    </footer>
  </section>
</form>

@include('layouts.partials.modal-scripts')

<script>
var lastJobs = [];

function fmt(n) { return (Math.round(n * 100) / 100).toFixed(2); }

document.getElementById('getJobsBtn').addEventListener('click', function() {
    var customerId = document.getElementById('customer_id').value;
    var fromDate = document.getElementById('from_date').value;
    var toDate = document.getElementById('to_date').value;

    if (!customerId || !fromDate || !toDate) {
        alert('Please select a customer, from date and to date first.');
        return;
    }

    fetch('{{ route('bills.getJobs') }}?customer_id=' + customerId + '&from_date=' + fromDate + '&to_date=' + toDate, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) { return res.json(); })
    .then(function(jobs) {
        lastJobs = jobs;
        document.getElementById('form_customer_id').value = customerId;
        document.getElementById('form_from_date').value = fromDate;
        document.getElementById('form_to_date').value = toDate;

        var tbody = document.getElementById('jobsPickBody');
        tbody.innerHTML = '';
        jobs.forEach(function(j) {
            var tr = document.createElement('tr');
            tr.dataset.trip = j.trip_plan_total;
            tr.innerHTML =
                '<td><input type="checkbox" class="job-check" name="job_ids[]" value="' + j.id + '"></td>' +
                '<td>' + j.job_no + '</td>' +
                '<td>' + j.date + '</td>' +
                '<td>' + j.vehicle + '</td>' +
                '<td>' + j.route + '</td>' +
                '<td class="text-end" data-retention="' + j.retention_charges_total + '">' + fmt(j.retention_charges_total) + '</td>' +
                '<td class="text-end" data-other="' + j.other_charges_total + '">' + fmt(j.other_charges_total) + '</td>' +
                '<td class="text-end">' + fmt(j.job_total) + '</td>';
            tbody.appendChild(tr);
        });

        document.getElementById('jobsCard').style.display = jobs.length ? '' : 'none';
        document.getElementById('noJobsMsg').style.display = jobs.length ? 'none' : '';
        document.getElementById('billDetailsCard').style.display = jobs.length ? '' : 'none';
        recalcTotals();
    })
    .catch(function() { alert('Could not load jobs. Please try again.'); });
});

document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('.job-check').forEach(function(cb) { cb.checked = this.checked; }.bind(this));
    recalcTotals();
});

document.getElementById('jobsPickBody').addEventListener('change', function(e) {
    if (e.target.classList.contains('job-check')) recalcTotals();
});

function recalcTotals() {
    var tripSum = 0, retentionSum = 0, otherSum = 0, count = 0;
    document.querySelectorAll('.job-check:checked').forEach(function(cb) {
        var tr = cb.closest('tr');
        tripSum += parseFloat(tr.dataset.trip || 0);
        retentionSum += parseFloat(tr.querySelector('[data-retention]').dataset.retention);
        otherSum += parseFloat(tr.querySelector('[data-other]').dataset.other);
        count++;
    });
    var total = tripSum + otherSum;

    document.getElementById('sumRetention').textContent = fmt(retentionSum);
    document.getElementById('sumOther').textContent = fmt(otherSum);
    document.getElementById('sumTotal').textContent = fmt(total);
    document.getElementById('containerCount').textContent = count;
}

document.getElementById('billForm').addEventListener('submit', function(e) {
    if (!document.querySelectorAll('.job-check:checked').length) {
        e.preventDefault();
        alert('Select at least one job to bill.');
    }
});

recalcTotals();
</script>
@endsection