@extends('layouts.app')

@section('title', 'Fleet | Vehicles')

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
        <h2 class="card-title">Vehicles</h2>
        @can('vehicles.create')
          <button type="button" class="modal-with-form btn btn-primary" href="#addModal">
            <i class="fas fa-plus"></i> Add Vehicle
          </button>
        @endcan
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="vehicles-datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>Code</th>
                <th>Name</th>
                <th>Companies</th>
                <th>Vehicle No.</th>
                <th>Remarks</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($vehicles as $index => $row)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td><code>{{ $row->code }}</code></td>
                  <td><strong>{{ $row->name }}</strong></td>
                  <td>{{ $row->companies->pluck('name')->implode(', ') ?: '—' }}</td>
                  <td>{{ $row->vehicle_no ?? '—' }}</td>
                  <td>{{ $row->remarks ?? '—' }}</td>
                  <td>
                    <span class="badge {{ $row->is_active ? 'bg-success' : 'bg-secondary' }}">
                        {{ $row->is_active ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td class="actions">
                    @can('vehicles.edit')
                      <a class="text-primary me-1" href="javascript:void(0)"
                         onclick="openEditModal({{ $row->id }})" title="Edit">
                          <i class="fas fa-edit"></i>
                      </a>
                      <form action="{{ route('vehicles.toggleActive', $row->id) }}" method="POST" style="display:inline;">
                          @csrf
                          @method('PUT')
                          <button type="submit" class="btn btn-link p-0 text-{{ $row->is_active ? 'danger' : 'success' }} me-1"
                                  title="{{ $row->is_active ? 'Deactivate' : 'Activate' }}">
                              <i class="fa fa-toggle-{{ $row->is_active ? 'on' : 'off' }}"></i>
                          </button>
                      </form>
                    @endcan
                    @can('vehicles.delete')
                      <a class="text-danger" href="javascript:void(0)"
                         onclick="openDeleteModal({{ $row->id }})" title="Delete">
                          <i class="fas fa-trash-alt"></i>
                      </a>
                    @endcan
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>

    {{-- ADD MODAL --}}
    @can('vehicles.create')
    <div id="addModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" action="{{ route('vehicles.store') }}" onkeydown="return event.key != 'Enter';">
          @csrf
          <header class="card-header">
            <h2 class="card-title">Add Vehicle</h2>
          </header>
          <div class="card-body">
            <div class="row form-group">
              <div class="col-lg-12 mb-2">
                <small class="text-muted">Code will be generated automatically (e.g. V-00001).</small>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" placeholder="Vehicle Name" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Companies</label>
                <select data-plugin-selecttwo class="form-control select2-js" name="company_ids[]" multiple>
                  @foreach($companies as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Vehicle No.</label>
                <input type="text" class="form-control" name="vehicle_no" placeholder="Registration No.">
              </div>
              <div class="col-lg-6 mb-2">
                <label>Remarks</label>
                <input type="text" class="form-control" name="remarks" placeholder="Remarks">
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Add Vehicle</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- EDIT MODAL --}}
    @can('vehicles.edit')
    <div id="editModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" id="editForm" onkeydown="return event.key != 'Enter';">
          @csrf
          @method('PUT')
          <header class="card-header">
            <h2 class="card-title">Edit Vehicle</h2>
          </header>
          <div class="card-body">
            <div class="row form-group">
              <div class="col-lg-6 mb-2">
                <label>Code</label>
                <input type="text" id="edit_code" class="form-control" readonly disabled>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Name <span class="text-danger">*</span></label>
                <input type="text" id="edit_name" class="form-control" name="name" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Companies</label>
                <select id="edit_company_ids" class="form-control select2-js" name="company_ids[]" multiple>
                  @foreach($companies as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Vehicle No.</label>
                <input type="text" id="edit_vehicle_no" class="form-control" name="vehicle_no">
              </div>
              <div class="col-lg-6 mb-2">
                <label>Remarks</label>
                <input type="text" id="edit_remarks" class="form-control" name="remarks">
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Update Vehicle</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- DELETE MODAL --}}
    @can('vehicles.delete')
    <div id="deleteModal" class="modal-block modal-block-warning mfp-hide">
      <section class="card">
        <form method="POST" id="deleteForm">
          @csrf
          @method('DELETE')
          <header class="card-header">
            <h2 class="card-title">Delete Vehicle</h2>
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
var vehiclesData = @json($vehicles->keyBy('id'));

function openEditModal(id) {
    var row = vehiclesData[id];
    document.getElementById('editForm').action = '/vehicles/' + id;
    document.getElementById('edit_code').value = row.code;
    document.getElementById('edit_name').value = row.name;
    document.getElementById('edit_vehicle_no').value = row.vehicle_no || '';
    document.getElementById('edit_remarks').value = row.remarks || '';
    var companyIds = (row.companies || []).map(function(c) { return c.id; });
    $('#edit_company_ids').val(companyIds).trigger('change');
    openMfpModal('#editModal');
}

function openDeleteModal(id) {
    var row = vehiclesData[id];
    document.getElementById('deleteForm').action = '/vehicles/' + id;
    document.getElementById('delete_label').textContent = row.name;
    openMfpModal('#deleteModal');
}

$(document).ready(function() {
    $('#vehicles-datatable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
    });
});
</script>
@endsection