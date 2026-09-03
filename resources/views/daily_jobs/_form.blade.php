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
    $existingVehiclesData = $isEdit ? $job->vehicles->map(function ($v) {
        return [
            'id' => $v->id,
            'vehicle_id' => $v->vehicle_id,
            'route_id' => $v->route_id,
            'container_no' => $v->container_no,
            'item_description' => $v->item_description,
            'trip_type' => $v->trip_type,
            'pickup_port_id' => $v->pickup_port_id,
            'destination_location_id' => $v->destination_location_id,
            'dropoff_port_id' => $v->dropoff_port_id,
            'rent' => $v->rent,
            'labour_charges' => $v->labour_charges,
            'yard_charges' => $v->yard_charges,
            'kanta_charges' => $v->kanta_charges,
            'retention_first_day_charges' => $v->retention_first_day_charges,
            'retention_next_day_rate' => $v->retention_next_day_rate,
            'retention_extra_days' => $v->retention_extra_days,
            'retention_night_rate' => $v->retention_night_rate,
            'delivery_challan_id' => $v->delivery_challan_id,
            'delivery_challan_label' => $v->deliveryChallan ? ($v->deliveryChallan->dc_no . ' — ' . $v->deliveryChallan->dc_date->format('d-m-Y')) : null,
            'extra_port' => $v->extraPortCharges->map(fn ($ep) => ['port_id' => $ep->port_id, 'charges' => $ep->charges])->values(),
        ];
    }) : [];
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

    {{-- ── Vehicles ─────────────────────────────────────────────── --}}
    <section class="card mb-3">
        <header class="card-header d-flex justify-content-between align-items-center">
            <h2 class="card-title">Vehicles</h2>
            <button type="button" class="btn btn-sm btn-primary" id="addVehicleRow"><i class="fas fa-plus"></i> Add Vehicle</button>
        </header>
        <div class="card-body">
            <div id="vehicleRows"></div>
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

var vIndex = 0;

function optionsHtml(master, selectedId, placeholder) {
    var html = '<option value="" disabled' + (selectedId ? '' : ' selected') + '>' + placeholder + '</option>';
    master.forEach(function(m) {
        html += '<option value="' + m.id + '"' + (selectedId == m.id ? ' selected' : '') + '>' + m.label + '</option>';
    });
    return html;
}

function fmt(n) { return (Math.round((parseFloat(n) || 0) * 100) / 100).toFixed(2); }

// ── Extra Port Charges (per vehicle-row) — port + charges only (item 2) ──
function addExtraPortRow(vi, row) {
    row = row || {};
    var tbody = document.getElementById('extraPortBody_' + vi);
    var ei = tbody.children.length;
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td><select class="form-control select2-js" name="vehicles[' + vi + '][extra_port][' + ei + '][port_id]">' + optionsHtml(portsMaster, row.port_id, 'Select Port') + '</select></td>' +
        '<td><input type="number" step="any" class="form-control extra-port-calc" data-vindex="' + vi + '" name="vehicles[' + vi + '][extra_port][' + ei + '][charges]" value="' + (row.charges || 0) + '"></td>' +
        '<td class="text-center"><button type="button" class="btn btn-link text-danger remove-extra-port-row"><i class="fas fa-trash-alt"></i></button></td>';
    tbody.appendChild(tr);
    initSelect2(tr);
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

function toggleRowTripType(vi) {
    var tripType = document.getElementById('trip_type_' + vi);
    if (!tripType) return;
    var isTwoWay = tripType.value === 'two_way';
    var wrap = document.getElementById('destination_wrap_' + vi);
    if (wrap) wrap.style.display = isTwoWay ? '' : 'none';
}

function filterDestinationOptions(vi, customerId, selectedId) {
    var $sel = $('#destination_location_id_' + vi);
    if (!$sel.length) return;
    $sel.empty().append('<option value="">Select Customer Location</option>');
    customerLocations.filter(function(l) { return l.customer_id == customerId; }).forEach(function(l) {
        $sel.append('<option value="' + l.id + '"' + (selectedId == l.id ? ' selected' : '') + '>' + l.location_name + '</option>');
    });
    $sel.trigger('change');
}

function recalcRow(vi) {
    var num = function(id) { var el = document.getElementById(id); return el ? (parseFloat(el.value) || 0) : 0; };

    var first = num('retention_first_' + vi);
    var nextRate = num('retention_next_' + vi);
    var extraDays = parseInt(document.getElementById('retention_days_' + vi) ? document.getElementById('retention_days_' + vi).value || 0 : 0, 10) || 0;
    var nightRate = num('retention_night_' + vi);
    var retentionTotal = first + (nextRate * extraDays) + (nightRate * extraDays);
    var retentionTotalEl = document.getElementById('retention_total_' + vi);
    if (retentionTotalEl) retentionTotalEl.value = fmt(retentionTotal);

    var extraPortTotal = 0;
    document.querySelectorAll('.extra-port-calc[data-vindex="' + vi + '"]').forEach(function(el) { extraPortTotal += (parseFloat(el.value) || 0); });

    var rent = num('rent_' + vi), labour = num('labour_' + vi), yard = num('yard_' + vi), kanta = num('kanta_' + vi);
    var lineTotal = rent + labour + yard + kanta + retentionTotal + extraPortTotal;
    var lineTotalEl = document.getElementById('line_total_' + vi);
    if (lineTotalEl) lineTotalEl.value = fmt(lineTotal);

    recalcJobTotal();
}

function recalcJobTotal() {
    var total = 0;
    document.querySelectorAll('[id^="line_total_"]').forEach(function(el) { total += parseFloat(el.value) || 0; });
    document.getElementById('job_total').value = fmt(total);
}

function rateFieldsHtml(vi, row) {
    if (!canFillRates) return '';

    var isTwoWay = row.trip_type === 'two_way';

    return '' +
    '<div class="row form-group mt-2">' +
        '<div class="col-lg-3 mb-2">' +
            '<label>Trip Type</label>' +
            '<select class="form-control" id="trip_type_' + vi + '" name="vehicles[' + vi + '][trip_type]" onchange="toggleRowTripType(' + vi + ')">' +
                '<option value="one_way"' + (row.trip_type !== 'two_way' ? ' selected' : '') + '>One Way</option>' +
                '<option value="two_way"' + (row.trip_type === 'two_way' ? ' selected' : '') + '>Two Way</option>' +
            '</select>' +
        '</div>' +
        '<div class="col-lg-3 mb-2">' +
            '<label>Pickup Port</label>' +
            '<select class="form-control select2-js" name="vehicles[' + vi + '][pickup_port_id]">' + optionsHtml(portsMaster, row.pickup_port_id, 'Select Port') + '</select>' +
        '</div>' +
        '<div class="col-lg-3 mb-2" id="destination_wrap_' + vi + '" style="' + (isTwoWay ? '' : 'display:none;') + '">' +
            '<label>Destination (Customer Location)</label>' +
            '<select class="form-control select2-js" id="destination_location_id_' + vi + '" name="vehicles[' + vi + '][destination_location_id]">' +
                '<option value="">Select Customer Location</option>' +
            '</select>' +
        '</div>' +
        '<div class="col-lg-3 mb-2">' +
            '<label>Dropoff Port</label>' +
            '<select class="form-control select2-js" name="vehicles[' + vi + '][dropoff_port_id]">' + optionsHtml(portsMaster, row.dropoff_port_id, 'Select Port') + '</select>' +
        '</div>' +
    '</div>' +
    '<div class="row form-group">' +
        '<div class="col-lg-3 mb-2"><label>Rent</label><input type="number" step="any" class="form-control" id="rent_' + vi + '" name="vehicles[' + vi + '][rent]" value="' + (row.rent || 0) + '" oninput="recalcRow(' + vi + ')"></div>' +
        '<div class="col-lg-3 mb-2"><label>Labour Charges</label><input type="number" step="any" class="form-control" id="labour_' + vi + '" name="vehicles[' + vi + '][labour_charges]" value="' + (row.labour_charges || 0) + '" oninput="recalcRow(' + vi + ')"></div>' +
        '<div class="col-lg-3 mb-2"><label>Yard</label><input type="number" step="any" class="form-control" id="yard_' + vi + '" name="vehicles[' + vi + '][yard_charges]" value="' + (row.yard_charges || 0) + '" oninput="recalcRow(' + vi + ')"></div>' +
        '<div class="col-lg-3 mb-2"><label>Weight Bridge (Kanta)</label><input type="number" step="any" class="form-control" id="kanta_' + vi + '" name="vehicles[' + vi + '][kanta_charges]" value="' + (row.kanta_charges || 0) + '" oninput="recalcRow(' + vi + ')"></div>' +
    '</div>' +
    '<div class="row form-group">' +
        '<div class="col-lg-12"><label class="mb-0"><strong>Retention Charges</strong> <small class="text-muted">(a.k.a. Per Day Charges — night rate applies once the job runs past day 1)</small></label></div>' +
        '<div class="col-lg-3 mb-2"><label>Day 1 Charges</label><input type="number" step="any" class="form-control" id="retention_first_' + vi + '" name="vehicles[' + vi + '][retention_first_day_charges]" value="' + (row.retention_first_day_charges || 0) + '" oninput="recalcRow(' + vi + ')"></div>' +
        '<div class="col-lg-2 mb-2"><label>Next Day Rate</label><input type="number" step="any" class="form-control" id="retention_next_' + vi + '" name="vehicles[' + vi + '][retention_next_day_rate]" value="' + (row.retention_next_day_rate || 0) + '" oninput="recalcRow(' + vi + ')"></div>' +
        '<div class="col-lg-2 mb-2"><label>Extra Days</label><input type="number" step="1" min="0" class="form-control" id="retention_days_' + vi + '" name="vehicles[' + vi + '][retention_extra_days]" value="' + (row.retention_extra_days || 0) + '" oninput="recalcRow(' + vi + ')"></div>' +
        '<div class="col-lg-2 mb-2"><label>Night Rate</label><input type="number" step="any" class="form-control" id="retention_night_' + vi + '" name="vehicles[' + vi + '][retention_night_rate]" value="' + (row.retention_night_rate || 0) + '" oninput="recalcRow(' + vi + ')"></div>' +
        '<div class="col-lg-3 mb-2"><label>Retention Total</label><input type="text" class="form-control" id="retention_total_' + vi + '" readonly value="0.00"></div>' +
    '</div>' +
    '<div class="row form-group">' +
        '<div class="col-lg-6 mb-2">' +
            '<label>Link Delivery Challan <small class="text-muted">(optional — only unlinked DCs shown)</small></label>' +
            '<select class="form-control select2-js" id="dc_select_' + vi + '" name="vehicles[' + vi + '][delivery_challan_id]">' +
                '<option value="">— Not linked —</option>' +
            '</select>' +
        '</div>' +
    '</div>' +
    '<div class="mb-2">' +
        '<div class="d-flex justify-content-between align-items-center">' +
            '<label class="mb-0">Extra Port Charges</label>' +
            '<button type="button" class="btn btn-sm btn-outline-primary" onclick="addExtraPortRow(' + vi + ')"><i class="fas fa-plus"></i> Add</button>' +
        '</div>' +
        '<table class="table table-bordered table-sm mt-1 mb-0">' +
            '<thead><tr><th style="width:60%">Port</th><th style="width:30%">Charges</th><th style="width:10%"></th></tr></thead>' +
            '<tbody id="extraPortBody_' + vi + '"></tbody>' +
        '</table>' +
    '</div>';
}

function addVehicleRow(row) {
    row = row || {};
    var vi = vIndex++;

    var card = document.createElement('div');
    card.className = 'card mb-3';
    card.id = 'vehicleCard_' + vi;
    card.innerHTML =
    '<div class="card-body">' +
        '<div class="d-flex justify-content-between align-items-center mb-2">' +
            '<h3 class="h6 mb-0">Vehicle Row</h3>' +
            '<button type="button" class="btn btn-sm btn-outline-danger remove-vehicle-row"><i class="fas fa-trash-alt"></i> Remove</button>' +
        '</div>' +
        (row.id ? '<input type="hidden" name="vehicles[' + vi + '][id]" value="' + row.id + '">' : '') +
        '<div class="row form-group">' +
            '<div class="col-lg-3 mb-2"><label>Vehicle <span class="text-danger">*</span></label>' +
                '<select class="form-control select2-js" name="vehicles[' + vi + '][vehicle_id]" required>' + optionsHtml(vehiclesMaster, row.vehicle_id, 'Select Vehicle') + '</select></div>' +
            '<div class="col-lg-3 mb-2"><label>Route <span class="text-danger">*</span></label>' +
                '<select class="form-control select2-js" name="vehicles[' + vi + '][route_id]" required>' + optionsHtml(routesMaster, row.route_id, 'Select Route') + '</select></div>' +
            '<div class="col-lg-3 mb-2"><label>Container #</label><input type="text" class="form-control" name="vehicles[' + vi + '][container_no]" value="' + (row.container_no || '') + '"></div>' +
            '<div class="col-lg-3 mb-2"><label>Item Description</label><input type="text" class="form-control" name="vehicles[' + vi + '][item_description]" value="' + (row.item_description || '') + '"></div>' +
        '</div>' +
        rateFieldsHtml(vi, row) +
        '<div class="row form-group mt-2">' +
            '<div class="col-lg-9"></div>' +
            '<div class="col-lg-3 mb-2"><label><strong>Vehicle Total</strong></label><input type="text" class="form-control fw-bold" id="line_total_' + vi + '" readonly value="0.00"></div>' +
        '</div>' +
    '</div>';

    document.getElementById('vehicleRows').appendChild(card);
    initSelect2(card);

    if (canFillRates) {
        (row.extra_port || []).forEach(function(ep) { addExtraPortRow(vi, ep); });
        refreshDcDropdown(vi, row.delivery_challan_id, row.delivery_challan_label);
        var customerId = document.getElementById('customer_id').value;
        if (customerId) filterDestinationOptions(vi, customerId, row.destination_location_id);
        recalcRow(vi);
    }
}

document.getElementById('addVehicleRow').addEventListener('click', function() { addVehicleRow(); });

document.getElementById('vehicleRows').addEventListener('click', function(e) {
    if (e.target.closest('.remove-vehicle-row')) {
        var rowsCount = document.querySelectorAll('#vehicleRows > .card').length;
        if (rowsCount <= 1) {
            alert('A job needs at least one vehicle.');
            return;
        }
        $(e.target.closest('.card')).remove();
        recalcJobTotal();
    }
    if (e.target.closest('.remove-extra-port-row')) {
        var tr = e.target.closest('tr');
        var vi = tr.closest('tbody').id.replace('extraPortBody_', '');
        $(tr).remove();
        recalcRow(vi);
    }
});

document.getElementById('vehicleRows').addEventListener('input', function(e) {
    if (e.target.classList.contains('extra-port-calc')) {
        recalcRow(e.target.dataset.vindex);
    }
});

document.getElementById('customer_id').addEventListener('change', function() {
    if (!canFillRates) return;
    var customerId = this.value;
    document.querySelectorAll('#vehicleRows > .card').forEach(function(card) {
        var vi = card.id.replace('vehicleCard_', '');
        filterDestinationOptions(vi, customerId, null);
        refreshDcDropdown(vi, null, null);
    });
});

$(document).ready(function() {
    if (existingVehicles.length) {
        existingVehicles.forEach(function(v) { addVehicleRow(v); });
    } else {
        addVehicleRow();
    }
    recalcJobTotal();
});
</script>