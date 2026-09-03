@extends('layouts.app')

@section('title', 'Fleet | Vehicle Routes')

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
        <h2 class="card-title">Vehicle Routes</h2>
        @can('vehicle_routes.create')
          <button type="button" class="modal-with-form btn btn-primary" href="#addModal">
            <i class="fas fa-plus"></i> Add Route
          </button>
        @endcan
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="routes-datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>Code</th>
                <th>Route</th>
                <th>Dimension</th>
                <th>Union Rent</th>
                <th>Day Det.</th>
                <th>Night Det.</th>
                <th>Labour</th>
                <th>Maripur</th>
                <th>H.Bay</th>
                <th>Extra N.</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($routes as $index => $row)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td><code>{{ $row->code }}</code></td>
                  <td><strong>{{ $row->name }}</strong></td>
                  <td>{{ $row->dimension ?? '—' }}</td>
                  <td>{{ number_format($row->union_rent, 2) }}</td>
                  <td>{{ number_format($row->day_detention, 2) }}</td>
                  <td>{{ number_format($row->night_detention, 2) }}</td>
                  <td>{{ number_format($row->labour_charges, 2) }}</td>
                  <td>{{ number_format($row->maripur_charges, 2) }}</td>
                  <td>{{ number_format($row->h_bay_charges, 2) }}</td>
                  <td>{{ number_format($row->extra_northern, 2) }}</td>
                  <td>
                    <span class="badge {{ $row->is_active ? 'bg-success' : 'bg-secondary' }}">
                        {{ $row->is_active ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td class="actions">
                    @can('vehicle_routes.edit')
                      <a class="text-primary me-1" href="javascript:void(0)"
                         onclick="openEditModal({{ $row->id }})" title="Edit">
                          <i class="fas fa-edit"></i>
                      </a>
                      <form action="{{ route('vehicle-routes.toggleActive', $row->id) }}" method="POST" style="display:inline;">
                          @csrf
                          @method('PUT')
                          <button type="submit" class="btn btn-link p-0 text-{{ $row->is_active ? 'danger' : 'success' }} me-1"
                                  title="{{ $row->is_active ? 'Deactivate' : 'Activate' }}">
                              <i class="fa fa-toggle-{{ $row->is_active ? 'on' : 'off' }}"></i>
                          </button>
                      </form>
                    @endcan
                    @can('vehicle_routes.delete')
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

    @php
        $rateFields = [
            'union_rent'      => 'Union Rent',
            'day_detention'   => 'Day Detention',
            'night_detention' => 'Night Detention',
            'labour_charges'  => 'Labour Charges',
            'maripur_charges' => 'Maripur Chg.',
            'h_bay_charges'   => 'H.Bay Charges',
            'extra_northern'  => 'Extra Northern',
        ];
    @endphp

    {{-- ADD MODAL --}}
    @can('vehicle_routes.create')
    <div id="addModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" action="{{ route('vehicle-routes.store') }}" onkeydown="return event.key != 'Enter';">
          @csrf
          <header class="card-header">
            <h2 class="card-title">Add Vehicle Route</h2>
          </header>
          <div class="card-body">
            <div class="row form-group">
              <div class="col-lg-12 mb-2">
                <small class="text-muted">Code will be generated automatically (e.g. R-00001).</small>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Vehicle Route <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" placeholder="e.g. KICT TO EPZ TO TPX" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Dimension</label>
                <input type="text" class="form-control" name="dimension" placeholder="e.g. 1 X 40">
              </div>
              @foreach($rateFields as $field => $label)
                <div class="col-lg-6 mb-2">
                  <label>{{ $label }}</label>
                  <input type="number" step="any" class="form-control" name="{{ $field }}" value="0">
                </div>
              @endforeach
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Add Route</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- EDIT MODAL --}}
    @can('vehicle_routes.edit')
    <div id="editModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" id="editForm" onkeydown="return event.key != 'Enter';">
          @csrf
          @method('PUT')
          <header class="card-header">
            <h2 class="card-title">Edit Vehicle Route</h2>
          </header>
          <div class="card-body">
            <div class="row form-group">
              <div class="col-lg-6 mb-2">
                <label>Code</label>
                <input type="text" id="edit_code" class="form-control" readonly disabled>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Vehicle Route <span class="text-danger">*</span></label>
                <input type="text" id="edit_name" class="form-control" name="name" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Dimension</label>
                <input type="text" id="edit_dimension" class="form-control" name="dimension">
              </div>
              @foreach($rateFields as $field => $label)
                <div class="col-lg-6 mb-2">
                  <label>{{ $label }}</label>
                  <input type="number" step="any" id="edit_{{ $field }}" class="form-control" name="{{ $field }}">
                </div>
              @endforeach
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Update Route</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- DELETE MODAL --}}
    @can('vehicle_routes.delete')
    <div id="deleteModal" class="modal-block modal-block-warning mfp-hide">
      <section class="card">
        <form method="POST" id="deleteForm">
          @csrf
          @method('DELETE')
          <header class="card-header">
            <h2 class="card-title">Delete Vehicle Route</h2>
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
var routesData = @json($routes->keyBy('id'));
var rateFieldNames = @json(array_keys($rateFields));

function openEditModal(id) {
    var row = routesData[id];
    document.getElementById('editForm').action = '/vehicle-routes/' + id;
    document.getElementById('edit_code').value = row.code;
    document.getElementById('edit_name').value = row.name;
    document.getElementById('edit_dimension').value = row.dimension || '';
    rateFieldNames.forEach(function(field) {
        document.getElementById('edit_' + field).value = row[field] || 0;
    });
    openMfpModal('#editModal');
}

function openDeleteModal(id) {
    var row = routesData[id];
    document.getElementById('deleteForm').action = '/vehicle-routes/' + id;
    document.getElementById('delete_label').textContent = row.name;
    openMfpModal('#deleteModal');
}

$(document).ready(function() {
    $('#routes-datatable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
        scrollX: true,
    });
});
</script>
@endsection