@php
    $isEdit = isset($job);

    if (is_array(old('vehicles'))) {
        $lineRows = array_values(old('vehicles'));
    } elseif ($isEdit && $job->madqamLines->isNotEmpty()) {
        $lineRows = $job->madqamLines->map(fn ($l) => [
            'vehicle_id'  => $l->vehicle_id,
            'start_date'  => optional($l->start_date)->format('Y-m-d') ?? $job->date->format('Y-m-d'),
            'end_date'    => optional($l->end_date)->format('Y-m-d') ?? $job->date->format('Y-m-d'),
            'destination' => $l->destination,
            'rent'        => $l->amount,
            'advance'     => $l->advance,
            'guarantee'   => $l->guarantee,
            'kanta'       => $l->kanta,
        ])->all();
    } else {
        $lineRows = [['vehicle_id' => '', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d'),
                      'destination' => '', 'rent' => '', 'advance' => '', 'guarantee' => '', 'kanta' => '']];
    }

    $accountVal = old('advance_account_id', $isEdit ? $job->mq_advance_account_id : '');
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
        <div class="col-lg-5 mb-2">
          <label>Customer <span class="text-danger">*</span></label>
          <select class="form-control select2-js" name="customer_id" required>
            <option value="" disabled {{ $isEdit ? '' : 'selected' }}>Select Customer</option>
            @foreach($customers as $c)
              <option value="{{ $c->id }}" {{ old('customer_id', $isEdit ? $job->customer_id : '') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-lg-4 mb-2">
          <label>Advance Received In <small class="text-muted">(cash / bank — needed when there is an advance)</small></label>
          <select class="form-control select2-js" name="advance_account_id">
            <option value="">Select cash / bank account</option>
            @foreach($cashBankAccounts as $acc)
              <option value="{{ $acc->id }}" {{ $accountVal == $acc->id ? 'selected' : '' }}>{{ $acc->name }} ({{ ucfirst($acc->account_type) }})</option>
            @endforeach
          </select>
          @error('advance_account_id') <div class="text-danger small">{{ $message }}</div> @enderror
        </div>
        <div class="col-lg-12 mb-2">
          <label>Remarks</label>
          <textarea class="form-control" rows="2" name="remarks">{{ old('remarks', $isEdit ? $job->remarks : '') }}</textarea>
        </div>
      </div>
    </div>
  </section>

  {{-- 2. Vehicle details --}}
  <section class="card mb-3">
    <header class="card-header">
      <h2 class="card-title">Vehicle Details <span class="badge bg-secondary" id="mqVehicleBadge">1</span>
        <small class="text-muted">— rent, guarantee and kanta are per day; Total = (Rent + Guarantee + Kanta) × days</small></h2>
    </header>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered align-middle mb-2" style="min-width:1500px;">
          <thead>
            <tr>
              <th style="width:40px;">#</th>
              <th style="width:200px;">Vehicle <span class="text-danger">*</span></th>
              <th style="width:100px;">Size</th>
              <th style="width:140px;">Start Date <span class="text-danger">*</span></th>
              <th style="width:140px;">End Date <span class="text-danger">*</span></th>
              <th style="width:60px;">Days</th>
              <th style="width:180px;">Destination</th>
              <th style="width:110px;">Rent / day <span class="text-danger">*</span></th>
              <th style="width:110px;">Advance</th>
              <th style="width:110px;">Guarantee / day</th>
              <th style="width:110px;">Kanta / day</th>
              <th style="width:120px;">Total</th>
              <th style="width:120px;">Remaining</th>
              <th style="width:50px;"></th>
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
                      <option value="{{ $v->id }}" data-size="{{ $v->remarks }}" {{ ($row['vehicle_id'] ?? '') == $v->id ? 'selected' : '' }}>
                        {{ $v->name }}{{ $v->vehicle_no ? ' (' . $v->vehicle_no . ')' : '' }}
                      </option>
                    @endforeach
                  </select>
                </td>
                <td><input type="text" class="form-control mq-size" readonly></td>
                <td><input type="date" class="form-control mq-start" name="vehicles[{{ $i }}][start_date]" value="{{ $row['start_date'] ?? '' }}"></td>
                <td><input type="date" class="form-control mq-end" name="vehicles[{{ $i }}][end_date]" value="{{ $row['end_date'] ?? '' }}"></td>
                <td><input type="text" class="form-control text-center mq-days" readonly value="1"></td>
                <td><input type="text" class="form-control mq-destination" name="vehicles[{{ $i }}][destination]" value="{{ $row['destination'] ?? '' }}"></td>
                <td><input type="number" step="any" min="0" class="form-control text-end mq-rent" name="vehicles[{{ $i }}][rent]" value="{{ $row['rent'] ?? '' }}" placeholder="0.00"></td>
                <td><input type="number" step="any" min="0" class="form-control text-end mq-advance" name="vehicles[{{ $i }}][advance]" value="{{ $row['advance'] ?? '' }}" placeholder="0.00"></td>
                <td><input type="number" step="any" min="0" class="form-control text-end mq-guarantee" name="vehicles[{{ $i }}][guarantee]" value="{{ $row['guarantee'] ?? '' }}" placeholder="0.00"></td>
                <td><input type="number" step="any" min="0" class="form-control text-end mq-kanta" name="vehicles[{{ $i }}][kanta]" value="{{ $row['kanta'] ?? '' }}" placeholder="0.00"></td>
                <td><input type="text" class="form-control text-end fw-bold mq-total" readonly value="0.00"></td>
                <td><input type="text" class="form-control text-end mq-remaining" readonly value="0.00"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-danger mq-remove" title="Remove vehicle"><i class="fas fa-times"></i></button></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-success" id="mqAddRow"><i class="fas fa-plus"></i> Add Vehicle</button>
      @error('vehicles') <div class="text-danger mt-1">{{ $message }}</div> @enderror
      @foreach($errors->get('vehicles.*') as $msgs)
        @foreach($msgs as $m) <div class="text-danger small">{{ $m }}</div> @endforeach
      @endforeach
    </div>
  </section>

  {{-- 3. Summary --}}
  <section class="card mb-3">
    <header class="card-header"><h2 class="card-title">Summary</h2></header>
    <div class="card-body">
      <div class="row form-group">
        <div class="col-lg-2 mb-2"><label>Vehicles</label><input type="text" class="form-control text-end" id="mqSummaryCount" readonly value="0"></div>
        <div class="col-lg-2 mb-2"><label>Total Rent</label><input type="text" class="form-control text-end" id="mqSummaryRent" readonly value="0.00"></div>
        <div class="col-lg-2 mb-2"><label>Total Guarantee</label><input type="text" class="form-control text-end" id="mqSummaryGuarantee" readonly value="0.00"></div>
        <div class="col-lg-2 mb-2"><label>Total Kanta</label><input type="text" class="form-control text-end" id="mqSummaryKanta" readonly value="0.00"></div>
        <div class="col-lg-2 mb-2"><label>Total</label><input type="text" class="form-control text-end fw-bold" id="mqSummaryTotal" readonly value="0.00"></div>
        <div class="col-lg-2 mb-2"><label>Total Advance</label><input type="text" class="form-control text-end" id="mqSummaryAdvance" readonly value="0.00"></div>
        <div class="col-lg-2 mb-2"><label>Remaining Receivable</label><input type="text" class="form-control text-end fw-bold" id="mqSummaryBalance" readonly value="0.00"></div>
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
    function num(tr, cls) { return parseFloat(tr.querySelector(cls).value) || 0; }

    function daysOf(tr) {
        var s = tr.querySelector('.mq-start').value, e = tr.querySelector('.mq-end').value;
        if (!s || !e) return 1;
        return Math.max(1, Math.round((new Date(e) - new Date(s)) / 86400000));
    }

    function syncSize(tr) {
        var opt = tr.querySelector('.mq-vehicle').selectedOptions[0];
        tr.querySelector('.mq-size').value = (opt && opt.value) ? (opt.dataset.size || '') : '';
    }

    function recalc() {
        var count = 0, rent = 0, gua = 0, kan = 0, total = 0, adv = 0;
        tbody.querySelectorAll('.mq-row').forEach(function (tr) {
            var days = daysOf(tr);
            var r = num(tr, '.mq-rent'), g = num(tr, '.mq-guarantee'), k = num(tr, '.mq-kanta'), a = num(tr, '.mq-advance');
            var lineTotal = (r + g + k) * days;

            tr.querySelector('.mq-days').value = days;
            tr.querySelector('.mq-total').value = fmt(lineTotal);
            tr.querySelector('.mq-remaining').value = fmt(lineTotal - a);

            if (tr.querySelector('.mq-vehicle').value) {
                count++;
                rent += r * days; gua += g * days; kan += k * days; total += lineTotal; adv += a;
            }
        });
        el('mqSummaryCount').value     = count;
        el('mqSummaryRent').value      = fmt(rent);
        el('mqSummaryGuarantee').value = fmt(gua);
        el('mqSummaryKanta').value     = fmt(kan);
        el('mqSummaryTotal').value     = fmt(total);
        el('mqSummaryAdvance').value   = fmt(adv);
        el('mqSummaryBalance').value   = fmt(total - adv);
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

    // End date can never be before the start date.
    function fixDates(tr) {
        var s = tr.querySelector('.mq-start'), e = tr.querySelector('.mq-end');
        e.min = s.value || '';
        if (s.value && (!e.value || e.value < s.value)) e.value = s.value;
    }

    // New row copies the previous row (dates, destination, amounts) — only the vehicle is cleared.
    el('mqAddRow').addEventListener('click', function () {
        var rows  = tbody.querySelectorAll('.mq-row');
        var clone = rows[rows.length - 1].cloneNode(true);
        clone.querySelector('.mq-vehicle').value = '';
        clone.querySelector('.mq-size').value = '';
        tbody.appendChild(clone);
        renumber();
        clone.querySelector('.mq-vehicle').focus();
    });

    tbody.addEventListener('click', function (e) {
        var btn = e.target.closest('.mq-remove');
        if (!btn) return;
        var rows = tbody.querySelectorAll('.mq-row');
        if (rows.length === 1) {   // keep one row — just clear it
            var tr = rows[0];
            tr.querySelector('.mq-vehicle').value = '';
            tr.querySelector('.mq-size').value = '';
            ['.mq-destination', '.mq-rent', '.mq-advance', '.mq-guarantee', '.mq-kanta'].forEach(function (c) { tr.querySelector(c).value = ''; });
        } else {
            btn.closest('tr').remove();
        }
        renumber();
    });

    tbody.addEventListener('change', function (e) {
        var tr = e.target.closest('.mq-row');
        if (!tr) return;
        if (e.target.classList.contains('mq-vehicle')) syncSize(tr);
        if (e.target.classList.contains('mq-start') || e.target.classList.contains('mq-end')) fixDates(tr);
        recalc();
    });
    tbody.addEventListener('input', recalc);

    tbody.querySelectorAll('.mq-row').forEach(function (tr) { syncSize(tr); fixDates(tr); });
    recalc();
})();
</script>