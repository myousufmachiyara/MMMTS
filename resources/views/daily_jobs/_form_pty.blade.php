@php
    $isEdit = isset($job);
@endphp

<form method="POST" action="{{ $isEdit ? route('daily-jobs.update', $job->id) : route('daily-jobs.store') }}">
  @csrf
  @if($isEdit) @method('PUT') @endif
  <input type="hidden" name="job_type" value="party_to_party">

  <section class="card mb-3">
    <header class="card-header"><h2 class="card-title">Party-to-Party Job — Vendor to Customer</h2></header>
    <div class="card-body">
      <div class="row form-group">
        <div class="col-lg-3 mb-2">
          <label>Date <span class="text-danger">*</span></label>
          <input type="date" class="form-control" name="date" value="{{ old('date', $isEdit ? $job->date->format('Y-m-d') : date('Y-m-d')) }}" required>
        </div>
        <div class="col-lg-4 mb-2">
          <label>Vendor <span class="text-danger">*</span></label>
          <select class="form-control select2-js" name="vendor_id" required>
            <option value="" disabled {{ $isEdit ? '' : 'selected' }}>Select Vendor</option>
            @foreach($vendors as $v)
              <option value="{{ $v->id }}" {{ old('vendor_id', $isEdit ? $job->vendor_id : '') == $v->id ? 'selected' : '' }}>{{ $v->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-lg-5 mb-2">
          <label>Customer <span class="text-danger">*</span></label>
          <select class="form-control select2-js" name="customer_id" required>
            <option value="" disabled {{ $isEdit ? '' : 'selected' }}>Select Customer</option>
            @foreach($customers as $c)
              <option value="{{ $c->id }}" {{ old('customer_id', $isEdit ? $job->customer_id : '') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
            @endforeach
          </select>
        </div>

        <div class="col-lg-3 mb-2">
          <label>Vehicle # <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="pty_vehicle_no" placeholder="e.g. TLR-820"
                 value="{{ old('pty_vehicle_no', $isEdit ? $job->pty_vehicle_no : '') }}" required>
        </div>
        <div class="col-lg-5 mb-2">
          <label>Destination <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="pty_destination" placeholder="e.g. TPX TO KORANGI TO SAPT / DETAIN"
                 value="{{ old('pty_destination', $isEdit ? $job->pty_destination : '') }}" required>
        </div>
        <div class="col-lg-4 mb-2">
          <label>Size</label>
          <input type="text" class="form-control" name="pty_size" placeholder="e.g. 1X40"
                 value="{{ old('pty_size', $isEdit ? $job->pty_size : '') }}">
        </div>

        <div class="col-lg-3 mb-2">
          <label>Vendor Cost <span class="text-danger">*</span></label>
          <input type="number" step="any" class="form-control pty-calc" id="pty_cost" name="pty_cost"
                 value="{{ old('pty_cost', $isEdit ? $job->pty_cost : 0) }}" required>
          <small class="text-muted">What we owe the vendor for this job.</small>
        </div>
        <div class="col-lg-3 mb-2">
          <label>Sale Amount (to Customer) <span class="text-danger">*</span></label>
          <input type="number" step="any" class="form-control pty-calc" id="pty_sale_amount" name="pty_sale_amount"
                 value="{{ old('pty_sale_amount', $isEdit ? $job->pty_sale_amount : 0) }}" required>
          <small class="text-muted">What we bill the customer — feeds the Bill/Invoice.</small>
        </div>
        <div class="col-lg-3 mb-2">
          <label>Advance (to Vendor)</label>
          <input type="number" step="any" class="form-control pty-calc" id="pty_advance" name="pty_advance"
                 value="{{ old('pty_advance', $isEdit ? $job->pty_advance : 0) }}">
        </div>
        <div class="col-lg-3 mb-2">
          <label>Guarantee (Held)</label>
          <input type="number" step="any" class="form-control pty-calc" id="pty_guarantee" name="pty_guarantee"
                 value="{{ old('pty_guarantee', $isEdit ? $job->pty_guarantee : 0) }}">
        </div>
        <div class="col-lg-3 mb-2">
          <label>Balance Payable to Vendor</label>
          <input type="text" class="form-control" id="pty_balance" readonly
                 value="{{ number_format($isEdit ? $job->pty_balance : 0, 2) }}">
        </div>
        <div class="col-lg-3 mb-2">
          <label>Profit (Sale − Cost)</label>
          <input type="text" class="form-control fw-bold" id="pty_profit" readonly
                 value="{{ number_format($isEdit ? ($job->pty_sale_amount - $job->pty_cost) : 0, 2) }}">
        </div>

        <div class="col-lg-12 mb-2">
          <label>Remarks</label>
          <textarea class="form-control" rows="2" name="remarks">{{ old('remarks', $isEdit ? $job->remarks : '') }}</textarea>
        </div>
      </div>
    </div>
    <footer class="card-footer text-end">
      <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Update Job' : 'Save Job' }}</button>
      <a href="{{ route('daily-jobs.index') }}" class="btn btn-default">Cancel</a>
    </footer>
  </section>
</form>

<script>
function ptyRecalc() {
    var cost      = parseFloat(document.getElementById('pty_cost').value) || 0;
    var sale      = parseFloat(document.getElementById('pty_sale_amount').value) || 0;
    var advance   = parseFloat(document.getElementById('pty_advance').value) || 0;
    var guarantee = parseFloat(document.getElementById('pty_guarantee').value) || 0;
    var balance   = cost - advance - guarantee;
    var profit    = sale - cost;
    document.getElementById('pty_balance').value = balance.toFixed(2);
    document.getElementById('pty_profit').value = profit.toFixed(2);
}
document.querySelectorAll('.pty-calc').forEach(function(el) {
    el.addEventListener('input', ptyRecalc);
});
ptyRecalc();
</script>