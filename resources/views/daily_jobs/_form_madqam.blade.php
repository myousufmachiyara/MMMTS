@php
    $isEdit = isset($job);

    // Grid rows: what was typed after a validation error; otherwise the job's
    // saved lines; otherwise one blank row.
    if (is_array(old('vehicles'))) {
        $lineRows = array_values(old('vehicles'));
    } elseif ($isEdit && $job->madqamLines->isNotEmpty()) {
        $lineRows = $job->madqamLines->map(fn ($l) => [
            'vehicle_id' => $l->vehicle_id, 'rate_per_day' => $l->rate_per_day, 'days' => $l->days,
        ])->all();
    } else {
        $lineRows = [['vehicle_id' => '', 'rate_per_day' => '', 'days' => '']];
    }
@endphp

<form method="POST" action="{{ $isEdit ? route('daily-jobs.update', $job->id) : route('daily-jobs.store') }}">
  @csrf
  @if($isEdit) @method('PUT') @endif
  <input type="hidden" name="job_type" value="madqam">

  {{-- 1. Job info --}}
  <section class="card mb-3">
    <header class="card-header"><h2 class="card-title">Madqam Job</h2></header>
    <div class="card-body">
      <div class="row form-group">
        <div class="col-lg-3 mb-2">
          <label>Date <span class="text-danger">*</span></label>
          <input type="date" class="form-control" name="date" value="{{ old('date', $isEdit ? $job->date->format('Y-m-d') : date('Y-m-d')) }}" required>
        </div>
        <div class="col-lg-9 mb-2">
          <label>Customer <span class="text-danger">*</span></label>
          <select class="form-control select2-js" name="customer_id" required>
            <option value="" disabled {{ $isEdit ? '' : 'selected' }}>Select Customer</option>
            @foreach($customers as $c)
              <option value="{{ $c->id }}" {{ old('customer_id', $isEdit ? $job->customer_id : '') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-lg-12 mb-2">
          <label>Remarks</label>
          <textarea class="form-control" rows="2" name="remarks">{{ old('remarks', $isEdit ? $job->remarks : '') }}</textarea>
        </div>
      </div>
    </div>
  </section>

  {{-- 2. Vehicle details grid --}}
  <section class="card mb-3">
    <header class="card-header">
      <h2 class="card-title">Vehicle Details <span class="badge bg-secondary" id="mqVehicleBadge">1</span></h2>
    </header>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered align-middle mb-2">
          <thead>
            <tr>
              <th style="width:50px;">#</th>
              <th>Vehicle <span class="text-danger">*</span></th>
              <th style="width:180px;">Rate / Day <span class="text-danger">*</span></th>
              <th style="width:150px;">No. of Days <span class="text-danger">*</span></th>
              <th style="width:180px;">Total</th>
              <th style="width:60px;"></th>
            </tr>
          </thead>
          <tbody id="mqRows">
            @foreach($lineRows as $i => $row)
              <tr class="mq-row">
                <td class="mq-no text-center">{{ $i + 1 }}</td>
                <td>
                  <select class="form-control mq-vehicle" name="vehicles[{{ $i }}][vehicle_id]">
                    <option value="">Select Vehicle</option>
                    @foreach($vehicles as $v)
                      <option value="{{ $v->id }}" {{ ($row['vehicle_id'] ?? '') == $v->id ? 'selected' : '' }}>
                        {{ $v->name }}{{ $v->vehicle_no ? ' (' . $v->vehicle_no . ')' : '' }}
                      </option>
                    @endforeach
                  </select>
                </td>
                <td><input type="number" step="any" min="0" class="form-control text-end mq-rate" name="vehicles[{{ $i }}][rate_per_day]" value="{{ $row['rate_per_day'] ?? '' }}" placeholder="0.00"></td>
                <td><input type="number" step="any" min="0" class="form-control text-end mq-days" name="vehicles[{{ $i }}][days]" value="{{ $row['days'] ?? '' }}" placeholder="0"></td>
                <td><input type="text" class="form-control text-end fw-bold mq-total" readonly value="0.00"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-danger mq-remove" title="Remove vehicle"><i class="fas fa-times"></i></button></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-success" id="mqAddRow"><i class="fas fa-plus"></i> Add Vehicle</button>
      @error('vehicles') <div class="text-danger mt-1">{{ $message }}</div> @enderror
      @error('vehicles.*.vehicle_id') <div class="text-danger mt-1">Pick a vehicle on every row.</div> @enderror
      @error('vehicles.*.rate_per_day') <div class="text-danger mt-1">Enter a rate per day on every row.</div> @enderror
      @error('vehicles.*.days') <div class="text-danger mt-1">Enter the number of days (more than 0) on every row.</div> @enderror
    </div>
  </section>

  {{-- 3. Summary --}}
  <section class="card mb-3">
    <header class="card-header"><h2 class="card-title">Summary</h2></header>
    <div class="card-body">
      <div class="row form-group">
        <div class="col-lg-3 mb-2">
          <label>Vehicles</label>
          <input type="text" class="form-control" id="mqSummaryCount" readonly value="0">
        </div>
        <div class="col-lg-3 mb-2">
          <label>Total Amount</label>
          <input type="text" class="form-control fw-bold" id="mqSummaryTotal" readonly value="0.00">
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
    var tbody = document.getElementById('mqRows');

    function fmt(n) { return (Math.round(n * 100) / 100).toFixed(2); }

    // Recalculate every line (rate × days) and the summary.
    function recalc() {
        var grand = 0, count = 0;
        tbody.querySelectorAll('.mq-row').forEach(function (tr) {
            var rate = parseFloat(tr.querySelector('.mq-rate').value) || 0;
            var days = parseFloat(tr.querySelector('.mq-days').value) || 0;
            var line = Math.round(rate * days * 100) / 100;
            tr.querySelector('.mq-total').value = fmt(line);
            grand += line;
            if (tr.querySelector('.mq-vehicle').value) count++;
        });
        document.getElementById('mqSummaryTotal').value = fmt(grand);
        document.getElementById('mqSummaryCount').value = count;
        document.getElementById('mqVehicleBadge').textContent = Math.max(count, 1);
    }

    function renumber() {
        tbody.querySelectorAll('.mq-row').forEach(function (tr, i) {
            tr.querySelector('.mq-no').textContent = i + 1;
            tr.querySelectorAll('select, input').forEach(function (el) {
                if (el.name) el.name = el.name.replace(/vehicles\[\d+\]/, 'vehicles[' + i + ']');
            });
        });
        recalc();
    }

    document.getElementById('mqAddRow').addEventListener('click', function () {
        var rows  = tbody.querySelectorAll('.mq-row');
        var clone = rows[rows.length - 1].cloneNode(true);
        clone.querySelector('.mq-vehicle').value = '';
        clone.querySelector('.mq-rate').value = '';
        clone.querySelector('.mq-days').value = '';
        tbody.appendChild(clone);
        renumber();
        clone.querySelector('.mq-vehicle').focus();
    });

    tbody.addEventListener('click', function (e) {
        var btn = e.target.closest('.mq-remove');
        if (!btn) return;
        var rows = tbody.querySelectorAll('.mq-row');
        if (rows.length === 1) {             // keep one row — just clear it
            rows[0].querySelector('.mq-vehicle').value = '';
            rows[0].querySelector('.mq-rate').value = '';
            rows[0].querySelector('.mq-days').value = '';
        } else {
            btn.closest('tr').remove();
        }
        renumber();
    });

    tbody.addEventListener('input', recalc);
    tbody.addEventListener('change', recalc);
    recalc();
})();
</script>