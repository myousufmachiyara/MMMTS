@extends('layouts.app')

@section('title', 'Fleet | Customer Locations')

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
        <h2 class="card-title">Customer Locations</h2>
        @can('customer_locations.create')
          <button type="button" class="modal-with-form btn btn-primary" href="#addModal">
            <i class="fas fa-plus"></i> Add Location
          </button>
        @endcan
      </header>

      <div class="card-body">

        {{-- ── Filter ───────────────────────────────────── --}}
        <form method="GET" action="{{ route('customer-locations.index') }}" class="mb-3">
          <div class="col-md-3">
            <label>Filter by Customer</label>
            <select name="customer_id" class="form-control" onchange="this.form.submit()">
              <option value="all" {{ request('customer_id') == 'all' || !request('customer_id') ? 'selected' : '' }}>All</option>
              @foreach($customers as $c)
                <option value="{{ $c->id }}" {{ request('customer_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
              @endforeach
            </select>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="locations-datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>Customer</th>
                <th>Location</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($locations as $index => $row)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td>{{ $row->customer->name ?? '—' }}</td>
                  <td><strong>{{ $row->location_name }}</strong></td>
                  <td>
                    <span class="badge {{ $row->is_active ? 'bg-success' : 'bg-secondary' }}">
                        {{ $row->is_active ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td class="actions">
                    @can('customer_locations.edit')
                      <a class="text-primary me-1" href="javascript:void(0)"
                         onclick="openEditModal({{ $row->id }})" title="Edit">
                          <i class="fas fa-edit"></i>
                      </a>
                      <form action="{{ route('customer-locations.toggleActive', $row->id) }}" method="POST" style="display:inline;">
                          @csrf
                          @method('PUT')
                          <button type="submit" class="btn btn-link p-0 text-{{ $row->is_active ? 'danger' : 'success' }} me-1"
                                  title="{{ $row->is_active ? 'Deactivate' : 'Activate' }}">
                              <i class="fa fa-toggle-{{ $row->is_active ? 'on' : 'off' }}"></i>
                          </button>
                      </form>
                    @endcan
                    @can('customer_locations.delete')
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
    @can('customer_locations.create')
    <div id="addModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" action="{{ route('customer-locations.store') }}" onkeydown="return event.key != 'Enter';">
          @csrf
          <header class="card-header">
            <h2 class="card-title">Add Customer Location</h2>
          </header>
          <div class="card-body">
            <div class="row form-group">
              <div class="col-lg-6 mb-2">
                <label>Customer <span class="text-danger">*</span></label>
                <select data-plugin-selecttwo class="form-control select2-js" name="customer_id" required>
                  <option value="" disabled selected>Select Customer</option>
                  @foreach($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Location Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="location_name" placeholder="Location Name" required>
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Add Location</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- EDIT MODAL --}}
    @can('customer_locations.edit')
    <div id="editModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" id="editForm" onkeydown="return event.key != 'Enter';">
          @csrf
          @method('PUT')
          <header class="card-header">
            <h2 class="card-title">Edit Customer Location</h2>
          </header>
          <div class="card-body">
            <div class="row form-group">
              <div class="col-lg-6 mb-2">
                <label>Customer <span class="text-danger">*</span></label>
                <select id="edit_customer_id" class="form-control select2-js" name="customer_id" required>
                  <option value="" disabled>Select Customer</option>
                  @foreach($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Location Name <span class="text-danger">*</span></label>
                <input type="text" id="edit_location_name" class="form-control" name="location_name" required>
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Update Location</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- DELETE MODAL --}}
    @can('customer_locations.delete')
    <div id="deleteModal" class="modal-block modal-block-warning mfp-hide">
      <section class="card">
        <form method="POST" id="deleteForm">
          @csrf
          @method('DELETE')
          <header class="card-header">
            <h2 class="card-title">Delete Customer Location</h2>
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
var locationsData = @json($locations->keyBy('id'));

function openEditModal(id) {
    var row = locationsData[id];
    document.getElementById('editForm').action = '/customer-locations/' + id;
    document.getElementById('edit_location_name').value = row.location_name;
    $('#edit_customer_id').val(row.customer_id).trigger('change');
    openMfpModal('#editModal');
}

function openDeleteModal(id) {
    var row = locationsData[id];
    document.getElementById('deleteForm').action = '/customer-locations/' + id;
    document.getElementById('delete_label').textContent = row.location_name;
    openMfpModal('#deleteModal');
}

$(document).ready(function() {
    $('#locations-datatable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
    });
});
</script>
@endsection
