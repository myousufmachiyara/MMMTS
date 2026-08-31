@php
    $isEdit = isset($job);
    $formAction = $isEdit ? route('daily-jobs.update', $job->id) : route('daily-jobs.store');
@endphp

<form method="POST" action="{{ $formAction }}" id="jobForm">
    @csrf
    @if($isEdit) @method('PUT') @endif

    {{-- ── Job Info ─────────────────────────────────────────────── --}}
    <section class="card mb-3">
        <header class="card-header"><h2 class="card-title">Job Info</h2></header>
        <div class="card-body">
            <div class="row form-group">
                <div class="col-lg-3 mb-2">
                    <label>Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="date" value="{{ $isEdit ? $job->date->format('Y-m-d') : date('Y-m-d') }}" required>
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Vehicle <span class="text-danger">*</span></label>
                    <select class="form-control select2-js" name="vehicle_id" required>
                        <option value="" disabled {{ !$isEdit ? 'selected' : '' }}>Select Vehicle</option>
                        @foreach($vehicles as $v)
                            <option value="{{ $v->id }}" {{ $isEdit && $job->vehicle_id == $v->id ? 'selected' : '' }}>{{ $v->name }} ({{ $v->vehicle_no }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Customer <span class="text-danger">*</span></label>
                    <select class="form-control select2-js" id="customer_id" name="customer_id" required>
                        <option value="" disabled {{ !$isEdit ? 'selected' : '' }}>Select Customer</option>
                        @foreach($customers as $c)
                            <option value="{{ $c->id }}" {{ $isEdit && $job->customer_id == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Route <span class="text-danger">*</span></label>
                    <select class="form-control select2-js" id="route_id" name="route_id" required>
                        <option value="" disabled {{ !$isEdit ? 'selected' : '' }}>Select Route</option>
                        @foreach($routes as $r)
                            <option value="{{ $r->id }}" {{ $isEdit && $job->route_id == $r->id ? 'selected' : '' }}>{{ $r->code }} — {{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Container #</label>
                    <input type="text" class="form-control" name="container_no" value="{{ $isEdit ? $job->container_no : '' }}">
                </div>
                <div class="col-lg-9 mb-2">
                    <label>Item Description</label>
                    <input type="text" class="form-control" name="item_description" value="{{ $isEdit ? $job->item_description : '' }}">
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Rent</label>
                    <input type="number" step="any" class="form-control job-calc" name="rent" value="{{ $isEdit ? $job->rent : 0 }}">
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Labour Charges</label>
                    <input type="number" step="any" class="form-control job-calc" id="labour_charges" name="labour_charges" value="{{ $isEdit ? $job->labour_charges : 0 }}">
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Yard</label>
                    <input type="number" step="any" class="form-control job-calc" name="yard_charges" value="{{ $isEdit ? $job->yard_charges : 0 }}">
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Weight Bridge (Kanta)</label>
                    <input type="number" step="any" class="form-control job-calc" name="kanta_charges" value="{{ $isEdit ? $job->kanta_charges : 0 }}">
                </div>
            </div>
        </div>
    </section>

    {{-- ── Trip Plan ────────────────────────────────────────────── --}}
    <section class="card mb-3">
        <header class="card-header"><h2 class="card-title">Trip Plan</h2></header>
        <div class="card-body">
            <div class="row form-group">
                <div class="col-lg-3 mb-2">
                    <label>Trip Type <span class="text-danger">*</span></label>
                    <select class="form-control" id="trip_type" name="trip_type" required>
                        <option value="one_way" {{ $isEdit && $job->trip_type == 'one_way' ? 'selected' : '' }}>One Way (Pickup + Dropoff)</option>
                        <option value="two_way" {{ $isEdit && $job->trip_type == 'two_way' ? 'selected' : '' }}>Two Way (Pickup + Destination + Dropoff)</option>
                    </select>
                </div>
            </div>
            <div class="row form-group">
                <div class="col-lg-3 mb-2">
                    <label>Pickup (Port) <span class="text-danger">*</span></label>
                    <select class="form-control select2-js" name="pickup_port_id" required>
                        <option value="" disabled {{ !$isEdit ? 'selected' : '' }}>Select Port</option>
                        @foreach($ports as $p)
                            <option value="{{ $p->id }}" {{ $isEdit && $job->pickup_port_id == $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Pickup Charges</label>
                    <input type="number" step="any" class="form-control trip-calc" name="pickup_charges" value="{{ $isEdit ? $job->pickup_charges : 0 }}">
                </div>
                <div class="col-lg-3 mb-2" id="destination_wrap">
                    <label>Destination (Customer Location)</label>
                    <select class="form-control select2-js" id="destination_location_id" name="destination_location_id">
                        <option value="">Select Customer Location</option>
                        @if($isEdit && $job->destinationLocation)
                            <option value="{{ $job->destinationLocation->id }}" selected>{{ $job->destinationLocation->location_name }}</option>
                        @endif
                    </select>
                </div>
                <div class="col-lg-3 mb-2" id="destination_charges_wrap">
                    <label>Destination Charges</label>
                    <input type="number" step="any" class="form-control trip-calc" name="destination_charges" value="{{ $isEdit ? $job->destination_charges : 0 }}">
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Dropoff (Port) <span class="text-danger">*</span></label>
                    <select class="form-control select2-js" name="dropoff_port_id" required>
                        <option value="" disabled {{ !$isEdit ? 'selected' : '' }}>Select Port</option>
                        @foreach($ports as $p)
                            <option value="{{ $p->id }}" {{ $isEdit && $job->dropoff_port_id == $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Dropoff Charges</label>
                    <input type="number" step="any" class="form-control trip-calc" name="dropoff_charges" value="{{ $isEdit ? $job->dropoff_charges : 0 }}">
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Trip Plan Total</label>
                    <input type="text" class="form-control" id="trip_plan_total" readonly value="0.00">
                </div>
            </div>
        </div>
    </section>

    {{-- ── Extra Port Charges ──────────────────────────────────────── --}}
    <section class="card mb-3">
        <header class="card-header d-flex justify-content-between align-items-center">
            <h2 class="card-title">Extra Port Charges</h2>
            <button type="button" class="btn btn-sm btn-primary" id="addExtraRow"><i class="fas fa-plus"></i> Add Row</button>
        </header>
        <div class="card-body">
            <table class="table table-bordered mb-0" id="extraPortTable">
                <thead>
                    <tr>
                        <th style="width:35%">From Port</th>
                        <th style="width:35%">To Port</th>
                        <th style="width:20%">Charges</th>
                        <th style="width:10%"></th>
                    </tr>
                </thead>
                <tbody id="extraPortRows"></tbody>
            </table>
        </div>
    </section>

    {{-- ── Per Day Charges ─────────────────────────────────────────── --}}
    <section class="card mb-3">
        <header class="card-header"><h2 class="card-title">Per Day Charges</h2></header>
        <div class="card-body">
            <div class="row form-group">
                <div class="col-lg-3 mb-2">
                    <label>Per Day Charges</label>
                    <input type="number" step="any" class="form-control day-calc" id="per_day_first_charges" name="per_day_first_charges" value="{{ $isEdit ? $job->per_day_first_charges : 0 }}">
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Per Day Charges (Next Day)</label>
                    <input type="number" step="any" class="form-control day-calc" id="per_day_next_rate" name="per_day_next_rate" value="{{ $isEdit ? $job->per_day_next_rate : 0 }}">
                </div>
                <div class="col-lg-3 mb-2">
                    <label>No. of Days</label>
                    <input type="number" step="1" min="0" class="form-control day-calc" id="per_day_extra_days" name="per_day_extra_days" value="{{ $isEdit ? $job->per_day_extra_days : 0 }}">
                </div>
                <div class="col-lg-3 mb-2">
                    <label>Per Day Total</label>
                    <input type="text" class="form-control" id="per_day_total" readonly value="0.00">
                </div>
            </div>
        </div>
    </section>

    <section class="card mb-3">
        <div class="card-body">
            <div class="row form-group">
                <div class="col-lg-9 mb-2">
                    <label>Remarks</label>
                    <textarea class="form-control" rows="2" name="remarks">{{ $isEdit ? $job->remarks : '' }}</textarea>
                </div>
                <div class="col-lg-3 mb-2">
                    <label><strong>Job Grand Total</strong></label>
                    <input type="text" class="form-control fw-bold" id="job_total" readonly value="0.00">
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
var customerLocations = @json($customerLocations);
var routeDefaults = @json($routes->keyBy('id'));
var ports = @json($ports->values());
var existingExtraRows = @json($isEdit ? $job->extraPortCharges : []);
var existingCustomerId = {{ $isEdit ? (int) $job->customer_id : 'null' }};
var existingDestinationId = {{ $isEdit && $job->destination_location_id ? (int) $job->destination_location_id : 'null' }};

function portOptionsHtml(selectedId) {
    var html = '<option value="" disabled' + (selectedId ? '' : ' selected') + '>Select Port</option>';
    ports.forEach(function(p) {
        html += '<option value="' + p.id + '"' + (selectedId == p.id ? ' selected' : '') + '>' + p.name + '</option>';
    });
    return html;
}

function addExtraRow(row) {
    row = row || {};
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td><select class="form-control select2-js" name="extra_from_port_id[]">' + portOptionsHtml(row.from_port_id) + '</select></td>' +
        '<td><select class="form-control select2-js" name="extra_to_port_id[]">' + portOptionsHtml(row.to_port_id) + '</select></td>' +
        '<td><input type="number" step="any" class="form-control extra-calc" name="extra_charges[]" value="' + (row.charges || 0) + '"></td>' +
        '<td class="text-center"><button type="button" class="btn btn-link text-danger remove-extra-row"><i class="fas fa-trash-alt"></i></button></td>';
    document.getElementById('extraPortRows').appendChild(tr);
    $(tr).find('.select2-js').select2({ width: '100%' });
}

document.getElementById('addExtraRow').addEventListener('click', function() { addExtraRow(); recalc(); });
document.getElementById('extraPortRows').addEventListener('click', function(e) {
    if (e.target.closest('.remove-extra-row')) {
        $(e.target.closest('tr')).remove();
        recalc();
    }
});

function filterDestinationOptions(customerId, selectedId) {
    var $sel = $('#destination_location_id');
    $sel.empty().append('<option value="">Select Customer Location</option>');
    customerLocations.filter(function(l) { return l.customer_id == customerId; }).forEach(function(l) {
        $sel.append('<option value="' + l.id + '"' + (selectedId == l.id ? ' selected' : '') + '>' + l.location_name + '</option>');
    });
    $sel.trigger('change');
}

function toggleTripType() {
    var isTwoWay = document.getElementById('trip_type').value === 'two_way';
    document.getElementById('destination_wrap').style.display = isTwoWay ? '' : 'none';
    document.getElementById('destination_charges_wrap').style.display = isTwoWay ? '' : 'none';
    if (!isTwoWay) {
        $('#destination_location_id').val('').trigger('change');
        document.querySelector('[name="destination_charges"]').value = 0;
    }
    recalc();
}

function recalc() {
    var num = function(sel) { var v = parseFloat(document.querySelector(sel) ? document.querySelector(sel).value : 0); return isNaN(v) ? 0 : v; };

    var isTwoWay = document.getElementById('trip_type').value === 'two_way';
    var pickup = num('[name="pickup_charges"]');
    var destination = isTwoWay ? num('[name="destination_charges"]') : 0;
    var dropoff = num('[name="dropoff_charges"]');
    var tripPlanTotal = pickup + destination + dropoff;
    document.getElementById('trip_plan_total').value = tripPlanTotal.toFixed(2);

    var perDayFirst = num('#per_day_first_charges');
    var perDayNext = num('#per_day_next_rate');
    var extraDays = parseInt(document.getElementById('per_day_extra_days').value || 0, 10);
    var perDayTotal = perDayFirst + (perDayNext * (isNaN(extraDays) ? 0 : extraDays));
    document.getElementById('per_day_total').value = perDayTotal.toFixed(2);

    var extraPortTotal = 0;
    document.querySelectorAll('.extra-calc').forEach(function(el) { extraPortTotal += (parseFloat(el.value) || 0); });

    var rent = num('[name="rent"]');
    var labour = num('#labour_charges');
    var yard = num('[name="yard_charges"]');
    var kanta = num('[name="kanta_charges"]');

    var jobTotal = rent + labour + yard + kanta + tripPlanTotal + extraPortTotal + perDayTotal;
    document.getElementById('job_total').value = jobTotal.toFixed(2);
}

$(document).ready(function() {
    // Seed existing extra port charge rows (edit) or one empty row (create)
    if (existingExtraRows.length) {
        existingExtraRows.forEach(function(r) { addExtraRow(r); });
    } else {
        addExtraRow();
    }

    if (existingCustomerId) {
        filterDestinationOptions(existingCustomerId, existingDestinationId);
    }

    $('#customer_id').on('change', function() { filterDestinationOptions(this.value, null); });
    $('#route_id').on('change', function() {
        var r = routeDefaults[this.value];
        if (r && (!document.getElementById('labour_charges').value || document.getElementById('labour_charges').value == 0)) {
            document.getElementById('labour_charges').value = r.labour_charges;
            recalc();
        }
    });

    document.getElementById('trip_type').addEventListener('change', toggleTripType);
    document.body.addEventListener('input', function(e) {
        if (e.target.matches('.job-calc, .trip-calc, .day-calc, .extra-calc')) recalc();
    });
    $(document).on('change', '.extra-calc', recalc);

    toggleTripType();
    recalc();
});
</script>
