@php
    $isEdit = isset($job);

    // Vehicle rows (all free text — these are the vendor's vehicles, not our
    // masters). Re-show what was typed after a validation error; otherwise
    // the job's saved rows; for a job saved before multi-vehicle existed, its
    // single legacy vehicle; otherwise one blank row.
    if (is_array(old('vehicles'))) {
        $vehicleRows = array_values(old('vehicles'));
    } elseif ($isEdit && $job->ptyVehicles->isNotEmpty()) {
        $vehicleRows = $job->ptyVehicles->map(fn ($v) => [
            'vehicle_no' => $v->vehicle_no, 'route' => $v->route, 'size' => $v->size,
        ])->all();
    } elseif ($isEdit && $job->pty_vehicle_no) {
        $vehicleRows = [['vehicle_no' => $job->pty_vehicle_no, 'route' => '', 'size' => $job->pty_size]];
    } else {
        $vehicleRows = [['vehicle_no' => '', 'route' => '', 'size' => '']];
    }
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

        <div class="col-lg-12 mb-2">
          <label>Destination <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="pty_destination" placeholder="e.g. TPX TO KORANGI TO SAPT / DETAIN"
                 value="{{ old('pty_destination', $isEdit ? $job->pty_destination : '') }}" required>
        </div>
      </div>
    </div>
  </section>

  <section class="card mb-3">
    <header class="card-header">
      <h2 class="card-title">Vehicles <span class="badge bg-secondary" id="ptyVehicleBadge">1</span></h2>
    </header>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered align-middle mb-2">
          <thead>
            <tr>
              <th style="width:50px;">#</th>
              <th>Vehicle # <span class="text-danger">*</span></th>
              <th>Route</th>
              <th style="width:160px;">Size</th>
              <th style="width:60px;"></th>
            </tr>
          </thead>
          <tbody id="ptyVehicleRows">
            @foreach($vehicleRows as $i => $row)
              <tr class="pty-vehicle-row">
                <td class="pty-row-no text-center">{{ $i + 1 }}</td>
                <td><input type="text" class="form-control" name="vehicles[{{ $i }}][vehicle_no]" placeholder="e.g. TLR-820" maxlength="50" value="{{ $row['vehicle_no'] ?? '' }}"></td>
                <td><input type="text" class="form-control" name="vehicles[{{ $i }}][route]" placeholder="e.g. Port to Korangi" maxlength="255" value="{{ $row['route'] ?? '' }}"></td>
                <td><input type="text" class="form-control" name="vehicles[{{ $i }}][size]" placeholder="e.g. 1X40" maxlength="50" value="{{ $row['size'] ?? '' }}"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-danger pty-remove-vehicle" title="Remove vehicle"><i class="fas fa-times"></i></button></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-success" id="ptyAddVehicle"><i class="fas fa-plus"></i> Add Vehicle</button>
      @error('vehicles') <div class="text-danger mt-1">{{ $message }}</div> @enderror
      @error('vehicles.*.vehicle_no') <div class="text-danger mt-1">Every vehicle needs a vehicle number.</div> @enderror
    </div>
  </section>

  <section class="card mb-3">
    <header class="card-header"><h2 class="card-title">Amounts <small class="text-muted">— entered per vehicle, multiplied by the number of vehicles</small></h2></header>
    <div class="card-body">
      <div class="row form-group">
        <div class="col-lg-3 mb-2">
          <label>Vendor Cost (per vehicle) <span class="text-danger">*</span></label>
          <input type="number" step="any" class="form-control pty-calc" id="pty_cost" name="pty_cost"
                 value="{{ old('pty_cost', $isEdit ? $job->pty_cost : 0) }}" required>
          <small class="text-muted">What we owe the vendor for each vehicle.</small>
        </div>
        <div class="col-lg-3 mb-2">
          <label>Sale Amount (per vehicle) <span class="text-danger">*</span></label>
          <input type="number" step="any" class="form-control pty-calc" id="pty_sale_amount" name="pty_sale_amount"
                 value="{{ old('pty_sale_amount', $isEdit ? $job->pty_sale_amount : 0) }}" required>
          <small class="text-muted">What we bill the customer for each vehicle — feeds the Bill/Invoice.</small>
        </div>
        <div class="col-lg-3 mb-2">
          <label>Advance (per vehicle)</label>
          <input type="number" step="any" class="form-control pty-calc" id="pty_advance" name="pty_advance"
                 value="{{ old('pty_advance', $isEdit ? $job->pty_advance : 0) }}">
        </div>
        <div class="col-lg-3 mb-2">
          <label>Guarantee (per vehicle)</label>
          <input type="number" step="any" class="form-control pty-calc" id="pty_guarantee" name="pty_guarantee"
                 value="{{ old('pty_guarantee', $isEdit ? $job->pty_guarantee : 0) }}">
        </div>
        <div class="col-lg-3 mb-2">
          <label>Balance Payable (per vehicle)</label>
          <input type="text" class="form-control" id="pty_balance" readonly
                 value="{{ number_format($isEdit ? $job->pty_balance : 0, 2) }}">
        </div>
        <div class="col-lg-3 mb-2">
          <label>Profit (per vehicle)</label>
          <input type="text" class="form-control fw-bold" id="pty_profit" readonly
                 value="{{ number_format($isEdit ? ($job->pty_sale_amount - $job->pty_cost) : 0, 2) }}">
        </div>
        <div class="col-lg-3 mb-2">
          <label>Total Bill Amount (<span class="pty-count">1</span> vehicle<span class="pty-plural"></span>)</label>
          <input type="text" class="form-control fw-bold" id="pty_total_sale" readonly value="0.00">
        </div>
        <div class="col-lg-3 mb-2">
          <label>Total Balance to Vendor</label>
          <input type="text" class="form-control fw-bold" id="pty_total_balance" readonly value="0.00">
        </div>
        <div class="col-lg-3 mb-2">
          <label>Total Vendor Cost</label>
          <input type="text" class="form-control" id="pty_total_cost" readonly value="0.00">
        </div>
        <div class="col-lg-3 mb-2">
          <label>Total Profit</label>
          <input type="text" class="form-control fw-bold" id="pty_total_profit" readonly value="0.00">
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
(function () {
    var tbody = document.getElementById('ptyVehicleRows');

    // Vehicles that count: a row with anything typed in it (the server drops
    // fully blank rows too), minimum 1 so the preview never multiplies by 0.
    function vehicleCount() {
        var n = 0;
        tbody.querySelectorAll('.pty-vehicle-row').forEach(function (tr) {
            var filled = false;
            tr.querySelectorAll('input[type=text]').forEach(function (i) { if (i.value.trim() !== '') filled = true; });
            if (filled) n++;
        });
        return Math.max(n, 1);
    }

    function fmt(n) { return n.toFixed(2); }

    window.ptyRecalc = function () {
        var cost      = parseFloat(document.getElementById('pty_cost').value) || 0;
        var sale      = parseFloat(document.getElementById('pty_sale_amount').value) || 0;
        var advance   = parseFloat(document.getElementById('pty_advance').value) || 0;
        var guarantee = parseFloat(document.getElementById('pty_guarantee').value) || 0;
        var balance   = cost - advance - guarantee;
        var profit    = sale - cost;
        var n         = vehicleCount();

        document.getElementById('pty_balance').value = fmt(balance);
        document.getElementById('pty_profit').value  = fmt(profit);
        document.getElementById('pty_total_sale').value    = fmt(sale * n);
        document.getElementById('pty_total_cost').value    = fmt(cost * n);
        document.getElementById('pty_total_balance').value = fmt(balance * n);
        document.getElementById('pty_total_profit').value  = fmt(profit * n);
        document.querySelector('.pty-count').textContent = n;
        document.querySelector('.pty-plural').textContent = n === 1 ? '' : 's';
        document.getElementById('ptyVehicleBadge').textContent = n;
    };

    function renumber() {
        tbody.querySelectorAll('.pty-vehicle-row').forEach(function (tr, i) {
            tr.querySelector('.pty-row-no').textContent = i + 1;
            tr.querySelectorAll('input').forEach(function (inp) {
                inp.name = inp.name.replace(/vehicles\[\d+\]/, 'vehicles[' + i + ']');
            });
        });
        window.ptyRecalc();
    }

    document.getElementById('ptyAddVehicle').addEventListener('click', function () {
        var rows = tbody.querySelectorAll('.pty-vehicle-row');
        var clone = rows[rows.length - 1].cloneNode(true);
        clone.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
        tbody.appendChild(clone);
        renumber();
        clone.querySelector('input').focus();
    });

    tbody.addEventListener('click', function (e) {
        var btn = e.target.closest('.pty-remove-vehicle');
        if (!btn) return;
        var rows = tbody.querySelectorAll('.pty-vehicle-row');
        if (rows.length === 1) {            // keep at least one row — just clear it
            rows[0].querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
        } else {
            btn.closest('tr').remove();
        }
        renumber();
    });

    tbody.addEventListener('input', window.ptyRecalc);
    document.querySelectorAll('.pty-calc').forEach(function (el) {
        el.addEventListener('input', window.ptyRecalc);
    });
    window.ptyRecalc();
})();
</script>