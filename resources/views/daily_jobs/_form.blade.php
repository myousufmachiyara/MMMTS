@php
    $isEdit = isset($job);
    $formAction = $isEdit ? route('daily-jobs.update', $job->id) : route('daily-jobs.store');

    // Computed here (rather than inline inside @json() below) because Blade's
    // @json() directive splits its raw argument text on EVERY comma to look
    // for an optional encoding-options argument — it has no idea which
    // commas are "inside" a nested array/closure. An array-map expression
    // with more than a couple of keys silently gets truncated by that split
    // (each extra top-level comma drops another chunk of the expression).
    // Assigning to a plain variable first means @json() only ever sees a
    // bare variable name (zero commas) and can never be misparsed this way.
    $vehiclesMasterData = $vehicles->map(fn ($v) => [
        'id'    => $v->id,
        'label' => $v->name . ' (' . ($v->vehicle_no ?? '—') . ')',
    ]);
    $routesMasterData = $routes->map(fn ($r) => [
        'id'    => $r->id,
        'label' => $r->code . ' — ' . $r->name,
    ]);
    $portsMasterData = $ports->map(fn ($p) => [
        'id'    => $p->id,
        'label' => $p->name,
    ]);

    // Everything except Vehicle and Container # is the SAME for every
    // vehicle on a multi-vehicle Direct job, so a vehicle-row now carries
    // only what genuinely differs per vehicle: the vehicle itself, its
    // container #, and its own Delivery Challan link (a DC is issued per
    // truck even though everything else is shared).
    $existingVehiclesData = $isEdit ? $job->vehicles->map(function ($v) {
        return [
            'id'                     => $v->id,
            'vehicle_id'             => $v->vehicle_id,
            'container_no'           => $v->container_no,
            'delivery_challan_id'    => $v->delivery_challan_id,
            'delivery_challan_label' => $v->deliveryChallan ? ($v->deliveryChallan->dc_no . ' — ' . $v->deliveryChallan->dc_date->format('d-m-Y')) : null,
        ];
    })->values() : [];

    // Route/trip-plan/rate/retention fields are shared across the whole job
    // and live on the job header — that's the source of truth going
    // forward. A job saved under the OLD per-vehicle-rates design (before
    // this change) never had its job-level fields populated, so as a
    // one-time transitional fallback, an empty/zero job-level value falls
    // back to the first vehicle-row's own value (which is where such a job
    // actually stored it). Saving the form afterwards normalizes it onto
    // the job header for good.
    $firstLine = $isEdit ? $job->vehicles->first() : null;
    $sharedSeed = $isEdit ? [
        'route_id'                    => $job->route_id ?? $firstLine?->route_id,
        'item_description'            => $job->item_description ?? $firstLine?->item_description,
        'trip_type'                   => $job->trip_type ?? $firstLine?->trip_type ?? 'one_way',
        'pickup_port_id'              => $job->pickup_port_id ?? $firstLine?->pickup_port_id,
        'destination_location_id'     => $job->destination_location_id ?? $firstLine?->destination_location_id,
        'dropoff_port_id'             => $job->dropoff_port_id ?? $firstLine?->dropoff_port_id,
        'rent'                        => $job->rent ?: ($firstLine->rent ?? 0),
        'labour_charges'              => $job->labour_charges ?: ($firstLine->labour_charges ?? 0),
        'yard_charges'                => $job->yard_charges ?: ($firstLine->yard_charges ?? 0),
        'kanta_charges'               => $job->kanta_charges ?: ($firstLine->kanta_charges ?? 0),
        'retention_first_day_charges' => $job->retention_first_day_charges ?: ($firstLine->retention_first_day_charges ?? 0),
        'retention_next_day_rate'     => $job->retention_next_day_rate ?: ($firstLine->retention_next_day_rate ?? 0),
        'retention_extra_days'        => $job->retention_extra_days ?: ($firstLine->retention_extra_days ?? 0),
        'retention_night_rate'        => $job->retention_night_rate ?: ($firstLine->retention_night_rate ?? 0),
        'extra_port'                  => $job->sharedExtraPortCharges->map(fn ($ep) => ['port_id' => $ep->port_id, 'charges' => $ep->charges])->values(),
    ] : [
        'route_id' => null, 'item_description' => null, 'trip_type' => 'one_way',
        'pickup_port_id' => null, 'destination_location_id' => null, 'dropoff_port_id' => null,
        'rent' => 0, 'labour_charges' => 0, 'yard_charges' => 0, 'kanta_charges' => 0,
        'retention_first_day_charges' => 0, 'retention_next_day_rate' => 0,
        'retention_extra_days' => 0, 'retention_night_rate' => 0,
        'extra_port' => [],
    ];
@endphp

<form method="POST" action="{{ $formAction }}" id="jobForm">
    @csrf
    @if($isEdit) @method('PUT') @endif
    <input type="hidden" name="job_type" value="direct">

    @if($isEdit && $job->status === 'incomplete')
        <div class="alert alert-warning">
            This job is <strong>Incomplete</strong> — only basic details have been entered so far.
            @if($canFillRates)
                Fill in the rate fields below and check "Mark Job Complete" when done.
            @else
                An admin still needs to fill in the rates before it can be billed.
            @endif
        </div>
    @endif

    {{-- ── Job Info ─────────────────────────────────────────────── --}}
    <section class="card mb-3">
        <header class="card-header"><h2 class="card-title">Job Info</h2></header>
        <div class="card-body">
            <div class="row form-group">
                <div class="col-lg-3 mb-2">
                    <label>Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="date" id="job_date" value="{{ old('date', $isEdit ? $job->date->format('Y-m-d') : date('Y-m-d')) }}" required>
                </div>
                <div class="col-lg-5 mb-2">
                    <label>Customer <span class="text-danger">*</span></label>
                    <select class="form-control select2-js" id="customer_id" name="customer_id" required>
                        <option value="" disabled {{ $isEdit ? '' : 'selected' }}>Select Customer</option>
                        @foreach($customers as $c)
                            <option value="{{ $c->id }}" {{ old('customer_id', $isEdit ? $job->customer_id : '') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-4 mb-2">
                    <label>Remarks</label>
                    <input type="text" class="form-control" name="remarks" value="{{ old('remarks', $isEdit ? $job->remarks : '') }}">
                </div>
                @if($canFillRates)
                    <div class="col-lg-4 mb-2 d-flex align-items-center">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="mark_complete" name="mark_complete" value="1"
                                   {{ old('mark_complete', $isEdit && $job->status === 'complete' ? '1' : '') ? 'checked' : '' }}>
                            <label class="form-check-label" for="mark_complete"><strong>Mark Job Complete</strong></label>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- ── Trip & Charges — shared across every vehicle on this job ────
         Only Vehicle and Container # differ per vehicle (see the Vehicles
         card below); everything here is entered once for the whole job. --}}
    <section class="card mb-3">
        <header class="card-header"><h2 class="card-title">Trip & Charges</h2></header>
        <div class="card-body">
            <div class="row form-group">
                <div class="col-lg-4 mb-2">
                    <label>Route <span class="text-danger">*</span></label>
                    <select class="form-control select2-js" id="route_id" name="route_id" required></select>
                </div>
                <div class="col-lg-8 mb-2">
                    <label>Item Description</label>
                    <input type="text" class="form-control" id="item_description" name="item_description" value="{{ old('item_description', $sharedSeed['item_description']) }}">
                </div>
            </div>

            <div id="rateFieldsWrap"></div>
        </div>
    </section>

    {{-- ── Vehicles ─────────────────────────────────────────────── --}}
    <section class="card mb-3">
        <header class="card-header d-flex justify-content-between align-items-center">
            <h2 class="card-title">Vehicles</h2>
            <button type="button" class="btn btn-sm btn-primary" id="addVehicleRow"><i class="fas fa-plus"></i> Add Vehicle</button>
        </header>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered mb-0" id="vehiclesTable">
                    <thead>
                        <tr>
                            <th style="width:5%">#</th>
                            <th>Vehicle</th>
                            <th>Container #</th>
                            @if($canFillRates)
                                <th>Delivery Challan <small class="text-muted">(optional)</small></th>
                            @endif
                            <th style="width:5%"></th>
                        </tr>
                    </thead>
                    <tbody id="vehicleRows"></tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card mb-3">
        <div class="card-body">
            <div class="row form-group">
                <div class="col-lg-9"></div>
                <div class="col-lg-3 mb-2">
                    <label><strong>Job Grand Total</strong></label>
                    <input type="text" class="form-control fw-bold" id="job_total" readonly value="0.00">
                    <small class="text-muted">Tax (if any) is applied on this grand total at Invoice stage.</small>
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
var canFillRates = @json($canFillRates);
var vehiclesMaster = @json($vehiclesMasterData);
var routesMaster = @json($routesMasterData);
var portsMaster = @json($portsMasterData);
var customerLocations = @json($customerLocations);
var existingVehicles = @json($existingVehiclesData);
var sharedSeed = @json($sharedSeed);

var vIndex = 0;
var epIndex = 0;

function optionsHtml(master, selectedId, placeholder) {
    var html = '<option value="" disabled' + (selectedId ? '' : ' selected') + '>' + placeholder + '</option>';
    master.forEach(function(m) {
        html += '<option value="' + m.id + '"' + (selectedId == m.id ? ' selected' : '') + '>' + m.label + '</option>';
    });
    return html;
}

function fmt(n) { return (Math.round((parseFloat(n) || 0) * 100) / 100).toFixed(2); }

// ── Extra Port Charges — shared once for the whole job (item 2's port+
// charges shape), not per vehicle any more. ──
function addExtraPortRow(row) {
    row = row || {};
    var ei = epIndex++;
    var tbody = document.getElementById('extraPortBody');
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td><select class="form-control select2-js" name="extra_port[' + ei + '][port_id]">' + optionsHtml(portsMaster, row.port_id, 'Select Port') + '</select></td>' +
        '<td><input type="number" step="any" class="form-control extra-port-calc" name="extra_port[' + ei + '][charges]" value="' + (row.charges || 0) + '"></td>' +
        '<td class="text-center"><button type="button" class="btn btn-link text-danger remove-extra-port-row"><i class="fas fa-trash-alt"></i></button></td>';
    tbody.appendChild(tr);
    initSelect2(tr);
}

function toggleTripType() {
    var tripType = document.getElementById('trip_type');
    if (!tripType) return;
    var wrap = document.getElementById('destination_wrap');
    if (wrap) wrap.style.display = tripType.value === 'two_way' ? '' : 'none';
}

function filterDestinationOptions(customerId, selectedId) {
    var $sel = $('#destination_location_id');
    if (!$sel.length) return;
    $sel.empty().append('<option value="">Select Customer Location</option>');
    customerLocations.filter(function(l) { return l.customer_id == customerId; }).forEach(function(l) {
        $sel.append('<option value="' + l.id + '"' + (selectedId == l.id ? ' selected' : '') + '>' + l.location_name + '</option>');
    });
    $sel.trigger('change');
}

function recalcTotal() {
    var num = function(id) { var el = document.getElementById(id); return el ? (parseFloat(el.value) || 0) : 0; };

    var first = num('retention_first');
    var nextRate = num('retention_next');
    var extraDays = parseInt(document.getElementById('retention_days') ? document.getElementById('retention_days').value || 0 : 0, 10) || 0;
    var nightRate = num('retention_night');
    var retentionTotal = first + (nextRate * extraDays) + (nightRate * extraDays);
    var retentionTotalEl = document.getElementById('retention_total_display');
    if (retentionTotalEl) retentionTotalEl.value = fmt(retentionTotal);

    var extraPortTotal = 0;
    document.querySelectorAll('.extra-port-calc').forEach(function(el) { extraPortTotal += (parseFloat(el.value) || 0); });

    var rent = num('rent'), labour = num('labour'), yard = num('yard'), kanta = num('kanta');
    var jobTotal = rent + labour + yard + kanta + retentionTotal + extraPortTotal;
    document.getElementById('job_total').value = fmt(jobTotal);
}

function renderRateFields() {
    var wrap = document.getElementById('rateFieldsWrap');
    if (!canFillRates) { wrap.innerHTML = ''; return; }

    var row = sharedSeed;
    var isTwoWay = row.trip_type === 'two_way';

    wrap.innerHTML = '' +
    '<div class="row form-group mt-2">' +
        '<div class="col-lg-3 mb-2">' +
            '<label>Trip Type</label>' +
            '<select class="form-control" id="trip_type" name="trip_type" onchange="toggleTripType()">' +
                '<option value="one_way"' + (row.trip_type !== 'two_way' ? ' selected' : '') + '>One Way</option>' +
                '<option value="two_way"' + (row.trip_type === 'two_way' ? ' selected' : '') + '>Two Way</option>' +
            '</select>' +
        '</div>' +
        '<div class="col-lg-3 mb-2">' +
            '<label>Pickup Port</label>' +
            '<select class="form-control select2-js" id="pickup_port_id" name="pickup_port_id">' + optionsHtml(portsMaster, row.pickup_port_id, 'Select Port') + '</select>' +
        '</div>' +
        '<div class="col-lg-3 mb-2" id="destination_wrap" style="' + (isTwoWay ? '' : 'display:none;') + '">' +
            '<label>Destination (Customer Location)</label>' +
            '<select class="form-control select2-js" id="destination_location_id" name="destination_location_id">' +
                '<option value="">Select Customer Location</option>' +
            '</select>' +
        '</div>' +
        '<div class="col-lg-3 mb-2">' +
            '<label>Dropoff Port</label>' +
            '<select class="form-control select2-js" id="dropoff_port_id" name="dropoff_port_id">' + optionsHtml(portsMaster, row.dropoff_port_id, 'Select Port') + '</select>' +
        '</div>' +
    '</div>' +
    '<div class="row form-group">' +
        '<div class="col-lg-3 mb-2"><label>Rent</label><input type="number" step="any" class="form-control" id="rent" name="rent" value="' + (row.rent || 0) + '" oninput="recalcTotal()"></div>' +
        '<div class="col-lg-3 mb-2"><label>Labour Charges</label><input type="number" step="any" class="form-control" id="labour" name="labour_charges" value="' + (row.labour_charges || 0) + '" oninput="recalcTotal()"></div>' +
        '<div class="col-lg-3 mb-2"><label>Yard</label><input type="number" step="any" class="form-control" id="yard" name="yard_charges" value="' + (row.yard_charges || 0) + '" oninput="recalcTotal()"></div>' +
        '<div class="col-lg-3 mb-2"><label>Weight Bridge (Kanta)</label><input type="number" step="any" class="form-control" id="kanta" name="kanta_charges" value="' + (row.kanta_charges || 0) + '" oninput="recalcTotal()"></div>' +
    '</div>' +
    '<div class="row form-group">' +
        '<div class="col-lg-12"><label class="mb-0"><strong>Retention Charges</strong> <small class="text-muted">(a.k.a. Per Day Charges — night rate applies once the job runs past day 1)</small></label></div>' +
        '<div class="col-lg-3 mb-2"><label>Day 1 Charges</label><input type="number" step="any" class="form-control" id="retention_first" name="retention_first_day_charges" value="' + (row.retention_first_day_charges || 0) + '" oninput="recalcTotal()"></div>' +
        '<div class="col-lg-2 mb-2"><label>Next Day Rate</label><input type="number" step="any" class="form-control" id="retention_next" name="retention_next_day_rate" value="' + (row.retention_next_day_rate || 0) + '" oninput="recalcTotal()"></div>' +
        '<div class="col-lg-2 mb-2"><label>Extra Days</label><input type="number" step="1" min="0" class="form-control" id="retention_days" name="retention_extra_days" value="' + (row.retention_extra_days || 0) + '" oninput="recalcTotal()"></div>' +
        '<div class="col-lg-2 mb-2"><label>Night Rate</label><input type="number" step="any" class="form-control" id="retention_night" name="retention_night_rate" value="' + (row.retention_night_rate || 0) + '" oninput="recalcTotal()"></div>' +
        '<div class="col-lg-3 mb-2"><label>Retention Total</label><input type="text" class="form-control" id="retention_total_display" readonly value="0.00"></div>' +
    '</div>' +
    '<div class="mb-2">' +
        '<div class="d-flex justify-content-between align-items-center">' +
            '<label class="mb-0">Extra Port Charges</label>' +
            '<button type="button" class="btn btn-sm btn-outline-primary" id="addExtraPortRow"><i class="fas fa-plus"></i> Add</button>' +
        '</div>' +
        '<table class="table table-bordered table-sm mt-1 mb-0">' +
            '<thead><tr><th style="width:60%">Port</th><th style="width:30%">Charges</th><th style="width:10%"></th></tr></thead>' +
            '<tbody id="extraPortBody"></tbody>' +
        '</table>' +
    '</div>';

    document.getElementById('addExtraPortRow').addEventListener('click', function() { addExtraPortRow(); });
    initSelect2(wrap);

    (row.extra_port || []).forEach(function(ep) { addExtraPortRow(ep); });

    var customerId = document.getElementById('customer_id').value;
    if (customerId) filterDestinationOptions(customerId, row.destination_location_id);

    recalcTotal();
}

// ── Delivery Challan link dropdown — only UNLINKED DCs offered (item 7) ──
function refreshDcDropdown(vi, selectedId, selectedLabel) {
    var customerId = document.getElementById('customer_id').value;
    var $sel = $('#dc_select_' + vi);
    if (!$sel.length) return;

    var url = '{{ route('delivery-challans.unlinked') }}' + (customerId ? ('?customer_id=' + customerId) : '');
    fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(function(res) { return res.json(); })
        .then(function(list) {
            var html = '<option value="">— Not linked —</option>';
            var found = false;
            list.forEach(function(dc) {
                if (String(dc.id) === String(selectedId)) found = true;
                html += '<option value="' + dc.id + '"' + (String(dc.id) === String(selectedId) ? ' selected' : '') + '>' + dc.dc_no + ' — ' + dc.customer + ' (' + dc.dc_date + ')</option>';
            });
            if (selectedId && !found && selectedLabel) {
                html += '<option value="' + selectedId + '" selected>' + selectedLabel + ' (currently linked)</option>';
            }
            $sel.html(html);
            if ($sel.hasClass('select2-hidden-accessible')) {
                $sel.trigger('change.select2');
            } else {
                initSelect2($sel);
            }
        })
        .catch(function() { /* leave dropdown as-is on failure */ });
}

function addVehicleRow(row) {
    row = row || {};
    var vi = vIndex++;

    var tr = document.createElement('tr');
    tr.id = 'vehicleRow_' + vi;
    tr.innerHTML =
        (row.id ? '<input type="hidden" name="vehicles[' + vi + '][id]" value="' + row.id + '">' : '') +
        '<td class="row-number"></td>' +
        '<td><select class="form-control select2-js" name="vehicles[' + vi + '][vehicle_id]" required>' + optionsHtml(vehiclesMaster, row.vehicle_id, 'Select Vehicle') + '</select></td>' +
        '<td><input type="text" class="form-control" name="vehicles[' + vi + '][container_no]" value="' + (row.container_no || '') + '"></td>' +
        (canFillRates ?
            '<td><select class="form-control select2-js" id="dc_select_' + vi + '" name="vehicles[' + vi + '][delivery_challan_id]"><option value="">— Not linked —</option></select></td>'
            : '') +
        '<td class="text-center"><button type="button" class="btn btn-link text-danger p-0 remove-vehicle-row"><i class="fas fa-trash-alt"></i></button></td>';

    document.getElementById('vehicleRows').appendChild(tr);
    initSelect2(tr);
    renumberVehicleRows();

    if (canFillRates) {
        refreshDcDropdown(vi, row.delivery_challan_id, row.delivery_challan_label);
    }
}

function renumberVehicleRows() {
    document.querySelectorAll('#vehicleRows > tr').forEach(function(tr, i) {
        var cell = tr.querySelector('.row-number');
        if (cell) cell.textContent = i + 1;
    });
}

document.getElementById('addVehicleRow').addEventListener('click', function() { addVehicleRow(); });

document.getElementById('vehicleRows').addEventListener('click', function(e) {
    if (e.target.closest('.remove-vehicle-row')) {
        var rowsCount = document.querySelectorAll('#vehicleRows > tr').length;
        if (rowsCount <= 1) {
            alert('A job needs at least one vehicle.');
            return;
        }
        $(e.target.closest('tr')).remove();
        renumberVehicleRows();
    }
});

document.getElementById('rateFieldsWrap').addEventListener('click', function(e) {
    if (e.target.closest('.remove-extra-port-row')) {
        $(e.target.closest('tr')).remove();
        recalcTotal();
    }
});
document.getElementById('rateFieldsWrap').addEventListener('input', function(e) {
    if (e.target.classList.contains('extra-port-calc')) recalcTotal();
});

document.getElementById('customer_id').addEventListener('change', function() {
    if (canFillRates) {
        filterDestinationOptions(this.value, null);
    }
    document.querySelectorAll('#vehicleRows > tr').forEach(function(tr) {
        var vi = tr.id.replace('vehicleRow_', '');
        if (canFillRates) refreshDcDropdown(vi, null, null);
    });
});

$(document).ready(function() {
    // Route select — a plain, single, always-visible select2 populated the
    // same way as the (now removed) per-vehicle one used to be.
    $('#route_id').html(optionsHtml(routesMaster, sharedSeed.route_id, 'Select Route'));
    initSelect2($('#jobForm'));

    renderRateFields();

    if (existingVehicles.length) {
        existingVehicles.forEach(function(v) { addVehicleRow(v); });
    } else {
        addVehicleRow();
    }
});
</script>