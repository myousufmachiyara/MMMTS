@extends('layouts.app')

@section('title', 'Delivery Challans')

@section('content')

@if(session('success'))
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('error') }}
    </div>
@endif

<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Delivery Challans</h2>
        @can('delivery_challans.create')
          <button type="button" class="modal-with-form btn btn-primary" href="#addModal">
            <i class="fas fa-plus"></i> Create DC
          </button>
        @endcan
      </header>

      <div class="card-body">
        <p class="text-muted small">
            Create a Delivery Challan here before a job exists. When a Daily Job is created or edited later,
            it can be linked to this DC by selecting its DC # (only unlinked DCs are offered there).
        </p>

        <form method="GET" action="{{ route('delivery-challans.index') }}" class="row form-group mb-3">
          <div class="col-lg-3 mb-2">
            <label>Customer</label>
            <select name="customer_id" class="form-control select2-js">
              <option value="all">All</option>
              @foreach($customers as $c)
                <option value="{{ $c->id }}" {{ request('customer_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-lg-3 mb-2">
            <label>Status</label>
            <select name="linked" class="form-control">
              <option value="all">All</option>
              <option value="linked" {{ request('linked') == 'linked' ? 'selected' : '' }}>Linked to a Job</option>
              <option value="unlinked" {{ request('linked') == 'unlinked' ? 'selected' : '' }}>Not Linked Yet</option>
            </select>
          </div>
          <div class="col-lg-1 mb-2 d-flex align-items-end">
            <button type="submit" class="btn btn-secondary w-100">Filter</button>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="dc-datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>DC No.</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Port</th>
                <th>Container #</th>
                <th>Linked Job</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($challans as $index => $row)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td><code>{{ $row->dc_no }}</code></td>
                  <td>{{ $row->dc_date->format('d-m-Y') }}</td>
                  <td>{{ $row->customer->name ?? '—' }}</td>
                  <td>{{ $row->port->name ?? '—' }}</td>
                  <td>{{ $row->container_no ?? '—' }}</td>
                  <td>
                    @if($row->vehicleLine)
                      <span class="badge bg-success">{{ $row->vehicleLine->dailyJob->job_no ?? '—' }}</span>
                    @else
                      <span class="badge bg-warning text-dark">Not Linked</span>
                    @endif
                  </td>
                  <td class="actions">
                    @can('delivery_challans.edit')
                      <a class="text-primary me-1" href="javascript:void(0)"
                         onclick="openEditModal({{ $row->id }})" title="Edit">
                          <i class="fas fa-edit"></i>
                      </a>
                    @endcan
                    @can('delivery_challans.print')
                      <a class="text-success me-1" href="{{ route('delivery-challans.print', $row->id) }}" target="_blank" title="Print (2 copies)">
                          <i class="fas fa-print"></i>
                      </a>
                    @endcan
                    @can('delivery_challans.delete')
                      @if(!$row->vehicleLine)
                        <a class="text-danger" href="javascript:void(0)"
                           onclick="openDeleteModal({{ $row->id }})" title="Delete">
                            <i class="fas fa-trash-alt"></i>
                        </a>
                      @endif
                    @endcan
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>

    @php
        $formFields = [
            'clearing_agent'   => 'Clearing Agent',
            'unit'             => 'Unit',
            'bl_no'            => 'BL #',
            'container_no'     => 'Container No.',
            'quantity'         => 'Quantity',
            'truck_no'         => 'Truck No.',
        ];
    @endphp

    {{-- ADD MODAL --}}
    @can('delivery_challans.create')
    <div id="addModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" action="{{ route('delivery-challans.store') }}" onkeydown="return event.key != 'Enter';">
          @csrf
          <header class="card-header">
            <h2 class="card-title">Create Delivery Challan</h2>
          </header>
          <div class="card-body">
            <div class="row form-group">
              <div class="col-lg-12 mb-2">
                <small class="text-muted">DC # will be generated automatically (e.g. DC-000001).</small>
              </div>
              <div class="col-lg-4 mb-2">
                <label>DC Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="dc_date" value="{{ date('Y-m-d') }}" required>
              </div>
              <div class="col-lg-4 mb-2">
                <label>Customer <span class="text-danger">*</span></label>
                <select class="form-control select2-js" name="customer_id" required>
                  <option value="" disabled selected>Select Customer</option>
                  @foreach($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-4 mb-2">
                <label>Port</label>
                <select class="form-control select2-js" name="port_id">
                  <option value="">Select Port</option>
                  @foreach($ports as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                  @endforeach
                </select>
              </div>
              @foreach($formFields as $field => $label)
                <div class="col-lg-4 mb-2">
                  <label>{{ $label }}</label>
                  <input type="text" class="form-control" name="{{ $field }}">
                </div>
              @endforeach
              <div class="col-lg-12 mb-2">
                <label>Item Description</label>
                <textarea class="form-control" rows="2" name="item_description"></textarea>
              </div>
              <div class="col-lg-12 mb-2">
                <label>Remarks</label>
                <textarea class="form-control" rows="2" name="remarks"></textarea>
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Create DC</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- EDIT MODAL --}}
    @can('delivery_challans.edit')
    <div id="editModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" id="editForm" onkeydown="return event.key != 'Enter';">
          @csrf
          @method('PUT')
          <header class="card-header">
            <h2 class="card-title">Edit Delivery Challan — <span id="edit_dc_no"></span></h2>
          </header>
          <div class="card-body">
            <div class="row form-group">
              <div class="col-lg-4 mb-2">
                <label>DC Date <span class="text-danger">*</span></label>
                <input type="date" id="edit_dc_date" class="form-control" name="dc_date" required>
              </div>
              <div class="col-lg-4 mb-2">
                <label>Customer <span class="text-danger">*</span></label>
                <select id="edit_customer_id" class="form-control select2-js" name="customer_id" required>
                  <option value="" disabled>Select Customer</option>
                  @foreach($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-4 mb-2">
                <label>Port</label>
                <select id="edit_port_id" class="form-control select2-js" name="port_id">
                  <option value="">Select Port</option>
                  @foreach($ports as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                  @endforeach
                </select>
              </div>
              @foreach($formFields as $field => $label)
                <div class="col-lg-4 mb-2">
                  <label>{{ $label }}</label>
                  <input type="text" id="edit_{{ $field }}" class="form-control" name="{{ $field }}">
                </div>
              @endforeach
              <div class="col-lg-12 mb-2">
                <label>Item Description</label>
                <textarea id="edit_item_description" class="form-control" rows="2" name="item_description"></textarea>
              </div>
              <div class="col-lg-12 mb-2">
                <label>Remarks</label>
                <textarea id="edit_remarks" class="form-control" rows="2" name="remarks"></textarea>
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Update DC</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- DELETE MODAL --}}
    @can('delivery_challans.delete')
    <div id="deleteModal" class="modal-block modal-block-warning mfp-hide">
      <section class="card">
        <form method="POST" id="deleteForm">
          @csrf
          @method('DELETE')
          <header class="card-header">
            <h2 class="card-title">Delete Delivery Challan</h2>
          </header>
          <div class="card-body">
            <p class="mb-0">Are you sure you want to delete <strong id="delete_label"></strong>? This cannot be undone.</p>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-danger">Yes, Delete</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

  </div>
</div>

@include('layouts.partials.modal-scripts')

<script>
var dcData = @json($challans->keyBy('id'));
var dcFormFields = @json(array_keys($formFields));

function openEditModal(id) {
    var row = dcData[id];
    document.getElementById('editForm').action = '/delivery-challans/' + id;
    document.getElementById('edit_dc_no').textContent = row.dc_no;
    document.getElementById('edit_dc_date').value = row.dc_date.split('T')[0];
    $('#edit_customer_id').val(row.customer_id).trigger('change');
    $('#edit_port_id').val(row.port_id || '').trigger('change');
    dcFormFields.forEach(function(field) {
        document.getElementById('edit_' + field).value = row[field] || '';
    });
    document.getElementById('edit_item_description').value = row.item_description || '';
    document.getElementById('edit_remarks').value = row.remarks || '';
    openMfpModal('#editModal');
}

function openDeleteModal(id) {
    var row = dcData[id];
    document.getElementById('deleteForm').action = '/delivery-challans/' + id;
    document.getElementById('delete_label').textContent = row.dc_no;
    openMfpModal('#deleteModal');
}

$(document).ready(function() {
    $('#dc-datatable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
    });
});
</script>
@endsection