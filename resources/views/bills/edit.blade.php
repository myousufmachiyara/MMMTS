@extends('layouts.app')

@section('title', 'Bills | Edit Bill')

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
  <header class="card-header"><h2 class="card-title">Edit Bill {{ $bill->bill_no }}</h2></header>
  <div class="card-body">
    <div class="row form-group">
      <div class="col-lg-3 mb-2">
        <label>Customer</label>
        <input type="text" class="form-control" value="{{ $bill->customer->name ?? '' }}" disabled>
        <input type="hidden" id="customer_id" value="{{ $bill->customer_id }}">
      </div>
      <div class="col-lg-2 mb-2">
        <label>From Date <span class="text-danger">*</span></label>
        <input type="date" id="from_date" class="form-control" value="{{ $bill->from_date->format('Y-m-d') }}">
      </div>
      <div class="col-lg-2 mb-2">
        <label>To Date <span class="text-danger">*</span></label>
        <input type="date" id="to_date" class="form-control" value="{{ $bill->to_date->format('Y-m-d') }}">
      </div>
      <div class="col-lg-2 mb-2 d-flex align-items-end">
        <button type="button" id="getJobsBtn" class="btn btn-secondary w-100">Reload Jobs</button>
      </div>
    </div>
  </div>
</section>

<form method="POST" action="{{ route('bills.update', $bill->id) }}" id="billForm">
  @csrf
  @method('PUT')
  <input type="hidden" name="from_date" id="form_from_date" value="{{ $bill->from_date->format('Y-m-d') }}">
  <input type="hidden" name="to_date" id="form_to_date" value="{{ $bill->to_date->format('Y-m-d') }}">

  <section class="card mb-3" id="jobsCard">
    <header class="card-header"><h2 class="card-title">Jobs (on this bill + non-billed)</h2></header>
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
              <th class="text-end">Detention Charges</th>
              <th class="text-end">Other Charges</th>
              <th class="text-end">Job Total</th>
            </tr>
          </thead>
          <tbody id="jobsPickBody"></tbody>
        </table>
        <p class="text-muted mb-0" id="noJobsMsg" style="display:none;">No jobs found.</p>
      </div>
    </div>
  </section>

  <section class="card mb-3" id="billDetailsCard">
    <header class="card-header"><h2 class="card-title">Bill Details</h2></header>
    <div class="card-body">
      <div class="row form-group">
        <div class="col-lg-3 mb-2">
          <label>Bill Date <span class="text-danger">*</span></label>
          <input type="date" name="bill_date" id="bill_date" class="form-control" value="{{ old('bill_date', $bill->bill_date->format('Y-m-d')) }}" required>
        </div>
        <div class="col-lg-3 mb-2">
          <label>Company <span class="text-danger">*</span></label>
          <select class="form-control select2-js" name="company_id" required>
            <option value="" disabled {{ old('company_id', $bill->company_id) ? '' : 'selected' }}>Select Company</option>
            @foreach($companies as $co)
              <option value="{{ $co->id }}" {{ old('company_id', $bill->company_id) == $co->id ? 'selected' : '' }}>{{ $co->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-lg-3 mb-2 d-flex align-items-end">
          <span class="text-muted">Containers (vehicles) selected: <strong id="containerCount">0</strong></span>
        </div>
      </div>
      <table class="table table-borderless w-auto ms-auto mt-2 mb-0">
        <tr><td class="text-end pe-3">Detention Charges Subtotal:</td><td class="text-end" id="sumDetention">0.00</td></tr>
        <tr><td class="text-end pe-3">Other Charges Subtotal:</td><td class="text-end" id="sumOther">0.00</td></tr>
        <tr class="fw-bold"><td class="text-end pe-3">Total Bill Amount (Grand Total):</td><td class="text-end" id="sumTotal">0.00</td></tr>
      </table>
      <p class="text-muted small mt-1 mb-0">Tax (if applicable) is applied later, at Invoice stage, on the combined grand total of the bills selected.</p>
      <div class="row form-group mt-2">
        <div class="col-lg-9 mb-2">
          <label>Remarks</label>
          <textarea class="form-control" name="remarks" rows="2">{{ old('remarks', $bill->remarks) }}</textarea>
        </div>
      </div>
    </div>
    <footer class="card-footer text-end">
      <button type="submit" class="btn btn-primary">Update Bill</button>
      <a href="{{ route('bills.index') }}" class="btn btn-default">Cancel</a>
    </footer>
  </section>
</form>

@include('layouts.partials.modal-scripts')

<script>
function fmt(n) { return (Math.round(n * 100) / 100).toFixed(2); }

// Jobs currently selected (starts with the bill's own jobs).
var selectedIds = new Set(@json($bill->jobs->pluck('id')->map(fn ($v) => (int) $v)->values()));

function loadJobs() {
    var customerId = document.getElementById('customer_id').value;
    var fromDate = document.getElementById('from_date').value;
    var toDate = document.getElementById('to_date').value;

    if (!fromDate || !toDate) {
        alert('Please select from date and to date first.');
        return;
    }

    // Remember what is ticked so a reload doesn't lose the selection.
    if (document.querySelectorAll('.job-check').length) {
        selectedIds = new Set();
        document.querySelectorAll('.job-check:checked').forEach(function(cb) { selectedIds.add(parseInt(cb.value, 10)); });
    }

    fetch('{{ route('bills.getJobs') }}?customer_id=' + customerId + '&from_date=' + fromDate + '&to_date=' + toDate + '&bill_id={{ $bill->id }}', {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) { return res.json(); })
    .then(function(jobs) {
        document.getElementById('form_from_date').value = fromDate;
        document.getElementById('form_to_date').value = toDate;

        var tbody = document.getElementById('jobsPickBody');
        tbody.innerHTML = '';
        jobs.forEach(function(j) {
            var tr = document.createElement('tr');
            tr.dataset.trip = j.trip_plan_total;
            var checked = selectedIds.has(j.id) ? 'checked' : '';
            tr.innerHTML =
                '<td><input type="checkbox" class="job-check" name="job_ids[]" value="' + j.id + '" data-vehicles="' + (j.container_count !== undefined ? j.container_count : (j.vehicle_count || 1)) + '" ' + checked + '></td>' +
                '<td>' + j.job_no + '</td>' +
                '<td>' + j.date + '</td>' +
                '<td>' + j.vehicle + '</td>' +
                '<td>' + j.route + '</td>' +
                '<td class="text-end" data-detention="' + j.detention_charges_total + '">' + fmt(j.detention_charges_total) + '</td>' +
                '<td class="text-end" data-other="' + j.other_charges_total + '">' + fmt(j.other_charges_total) + '</td>' +
                '<td class="text-end">' + fmt(j.job_total) + (j.vehicle_count > 1 ? ' <small class="text-muted">(&times;' + j.vehicle_count + ')</small>' : '') + '</td>';
            tbody.appendChild(tr);
        });

        document.getElementById('noJobsMsg').style.display = jobs.length ? 'none' : '';
        recalcTotals();
    })
    .catch(function() { alert('Could not load jobs. Please try again.'); });
}

document.getElementById('getJobsBtn').addEventListener('click', loadJobs);

document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('.job-check').forEach(function(cb) { cb.checked = this.checked; }.bind(this));
    recalcTotals();
});

document.getElementById('jobsPickBody').addEventListener('change', function(e) {
    if (e.target.classList.contains('job-check')) recalcTotals();
});

function recalcTotals() {
    var tripSum = 0, detentionSum = 0, otherSum = 0, count = 0;
    document.querySelectorAll('.job-check:checked').forEach(function(cb) {
        var tr = cb.closest('tr');
        tripSum += parseFloat(tr.dataset.trip || 0);
        detentionSum += parseFloat(tr.querySelector('[data-detention]').dataset.detention);
        otherSum += parseFloat(tr.querySelector('[data-other]').dataset.other);
        count += isNaN(parseInt(cb.dataset.vehicles, 10)) ? 1 : parseInt(cb.dataset.vehicles, 10);
    });
    var total = tripSum + otherSum;

    document.getElementById('sumDetention').textContent = fmt(detentionSum);
    document.getElementById('sumOther').textContent = fmt(otherSum);
    document.getElementById('sumTotal').textContent = fmt(total);
    document.getElementById('containerCount').textContent = count;
}

document.getElementById('billForm').addEventListener('submit', function(e) {
    if (!document.querySelectorAll('.job-check:checked').length) {
        e.preventDefault();
        alert('Select at least one job for this bill.');
    }
});

loadJobs();
</script>
@endsection