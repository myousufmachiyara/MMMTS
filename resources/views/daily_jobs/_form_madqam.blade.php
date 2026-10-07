@php
    $isEdit = isset($job);

    // Grid rows: what was typed after a validation error; otherwise the job's
    // saved lines; otherwise one blank row.
    if (is_array(old('vehicles'))) {
        $lineRows = array_values(old('vehicles'));
    } elseif ($isEdit && $job->madqamLines->isNotEmpty()) {
        $lineRows = $job->madqamLines->map(fn ($l) => ['vehicle_id' => $l->vehicle_id, 'rent' => $l->amount])->all();
    } else {
        $lineRows = [['vehicle_id' => '', 'rent' => '']];
    }

    $advanceVal   = old('advance',   $isEdit ? $job->mq_advance   : '');
    $guaranteeVal = old('guarantee', $isEdit ? $job->mq_guarantee : '');
    $accountVal   = old('advance_account_id', $isEdit ? $job->mq_advance_account_id : '');
@endphp

<form method="POST" action="{{ $isEdit ? route('daily-jobs.update', $job->id) : route('daily-jobs.store') }}">
  @csrf
  @if($isEdit) @method('PUT') @endif
  <input type="hidden" name="job_type" value="madqam">

  {{-- 1. Job info --}}
  <section class="card mb-3">
    <header class="card-header"><h2 class="card-title">Muqadum Job</h2></header>
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
              <th style="width:220px;">Rent <span class="text-danger">*</span></th>
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
                <td><input type="number" step="any" min="0" class="form-control text-end mq-rent" name="vehicles[{{ $i }}][rent]" value="{{ $row['rent'] ?? '' }}" placeholder="0.00"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-danger mq-remove" title="Remove vehicle"><i class="fas fa-times"></i></button></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-success" id="mqAddRow"><i class="fas fa-plus"></i> Add Vehicle</button>
      @error('vehicles') <div class="text-danger mt-1">{{ $message }}</div> @enderror
      @error('vehicles.*.vehicle_id') <div class="text-danger mt-1">Pick a vehicle on every row.</div> @enderror
      @error('vehicles.*.rent') <div class="text-danger mt-1">Enter a rent on every row.</div> @enderror
    </div>
  </section>

  {{-- 3. Advance & guarantee (entered per vehicle) --}}
  <section class="card mb-3">
    <header class="card-header">
      <h2 class="card-title">Advance &amp; Guarantee <small class="text-muted">— entered per vehicle, multiplied by the number of vehicles</small></h2>
    </header>
    <div class="card-body">
      <div class="row form-group">
        <div class="col-lg-3 mb-2">
          <label>Advance (per vehicle)</label>
          <input type="number" step="any" min="0" class="form-control text-end" id="mqAdvance" name="advance" value="{{ $advanceVal }}" placeholder="0.00">
          @error('advance') <div class="text-danger small">{{ $message }}</div> @enderror
        </div>
        <div class="col-lg-3 mb-2">
          <label>Guarantee (per vehicle)</label>
          <input type="number" step="any" min="0" class="form-control text-end" id="mqGuarantee" name="guarantee" value="{{ $guaranteeVal }}" placeholder="0.00">
          <small class="text-muted">Included in the total receivable.</small>
          @error('guarantee') <div class="text-danger small">{{ $message }}</div> @enderror
        </div>
        <div class="col-lg-6 mb-2">
          <label>Advance Received In <small class="text-muted">(cash / bank account — needed when there is an advance)</small></label>
          <select class="form-control select2-js" id="mqAdvanceAccount" name="advance_account_id">
            <option value="">Select cash / bank account</option>
            @foreach($cashBankAccounts as $acc)
              <option value="{{ $acc->id }}" {{ $accountVal == $acc->id ? 'selected' : '' }}>{{ $acc->name }} ({{ ucfirst($acc->account_type) }})</option>
            @endforeach
          </select>
          @error('advance_account_id') <div class="text-danger small">{{ $message }}</div> @enderror
        </div>
      </div>
    </div>
  </section>

  {{-- 4. Summary --}}
  <section class="card mb-3">
    <header class="card-header"><h2 class="card-title">Summary</h2></header>
    <div class="card-body">
      <div class="row form-group">
        <div class="col-lg-2 mb-2">
          <label>Vehicles</label>
          <input type="text" class="form-control text-end" id="mqSummaryCount" readonly value="0">
        </div>
        <div class="col-lg-2 mb-2">
          <label>Total Rent</label>
          <input type="text" class="form-control text-end" id="mqSummaryRent" readonly value="0.00">
        </div>
        <div class="col-lg-2 mb-2">
          <label>Total Guarantee</label>
          <input type="text" class="form-control text-end" id="mqSummaryGuarantee" readonly value="0.00">
        </div>
        <div class="col-lg-2 mb-2">
          <label>Total (Rent + Guarantee)</label>
          <input type="text" class="form-control text-end fw-bold" id="mqSummaryTotal" readonly value="0.00">
        </div>
        <div class="col-lg-2 mb-2">
          <label>Total Advance</label>
          <input type="text" class="form-control text-end" id="mqSummaryAdvance" readonly value="0.00">
        </div>
        <div class="col-lg-2 mb-2">
          <label>Balance Receivable</label>
          <input type="text" class="form-control text-end fw-bold" id="mqSummaryBalance" readonly value="0.00">
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
    function el(id) { return document.getElementById(id); }

    // Totals: rent = sum of the vehicles' rents; advance and guarantee are
    // per vehicle × the number of vehicles picked.
    function recalc() {
        var rent = 0, count = 0;
        tbody.querySelectorAll('.mq-row').forEach(function (tr) {
            rent += parseFloat(tr.querySelector('.mq-rent').value) || 0;
            if (tr.querySelector('.mq-vehicle').value) count++;
        });
        var advPer = parseFloat(el('mqAdvance').value) || 0;
        var guaPer = parseFloat(el('mqGuarantee').value) || 0;
        var guarantee = guaPer * count;
        var advance   = advPer * count;
        var total     = rent + guarantee;

        el('mqSummaryCount').value     = count;
        el('mqSummaryRent').value      = fmt(rent);
        el('mqSummaryGuarantee').value = fmt(guarantee);
        el('mqSummaryTotal').value     = fmt(total);
        el('mqSummaryAdvance').value   = fmt(advance);
        el('mqSummaryBalance').value   = fmt(total - advance);
        el('mqVehicleBadge').textContent = Math.max(count, 1);
    }

    function renumber() {
        tbody.querySelectorAll('.mq-row').forEach(function (tr, i) {
            tr.querySelector('.mq-no').textContent = i + 1;
            tr.querySelectorAll('select, input').forEach(function (inp) {
                if (inp.name) inp.name = inp.name.replace(/vehicles\[\d+\]/, 'vehicles[' + i + ']');
            });
        });
        recalc();
    }

    el('mqAddRow').addEventListener('click', function () {
        var rows  = tbody.querySelectorAll('.mq-row');
        var clone = rows[rows.length - 1].cloneNode(true);
        clone.querySelector('.mq-vehicle').value = '';
        clone.querySelector('.mq-rent').value = '';
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
            rows[0].querySelector('.mq-rent').value = '';
        } else {
            btn.closest('tr').remove();
        }
        renumber();
    });

    tbody.addEventListener('input', recalc);
    tbody.addEventListener('change', recalc);
    el('mqAdvance').addEventListener('input', recalc);
    el('mqGuarantee').addEventListener('input', recalc);
    recalc();
})();
</script>