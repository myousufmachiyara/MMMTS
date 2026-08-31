@extends('layouts.app')

@section('title', 'Fleet | Ports')

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
        <h2 class="card-title">Ports</h2>
        @can('ports.create')
          <button type="button" class="modal-with-form btn btn-primary" href="#addModal">
            <i class="fas fa-plus"></i> Add Port
          </button>
        @endcan
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="ports-datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($ports as $index => $row)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td><strong>{{ $row->name }}</strong></td>
                  <td>
                    <span class="badge {{ $row->is_active ? 'bg-success' : 'bg-secondary' }}">
                        {{ $row->is_active ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td class="actions">
                    @can('ports.edit')
                      <a class="text-primary me-1" href="javascript:void(0)"
                         onclick="openEditModal({{ $row->id }})" title="Edit">
                          <i class="fas fa-edit"></i>
                      </a>
                      <form action="{{ route('ports.toggleActive', $row->id) }}" method="POST" style="display:inline;">
                          @csrf
                          @method('PUT')
                          <button type="submit" class="btn btn-link p-0 text-{{ $row->is_active ? 'danger' : 'success' }} me-1"
                                  title="{{ $row->is_active ? 'Deactivate' : 'Activate' }}">
                              <i class="fa fa-toggle-{{ $row->is_active ? 'on' : 'off' }}"></i>
                          </button>
                      </form>
                    @endcan
                    @can('ports.delete')
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
    @can('ports.create')
    <div id="addModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" action="{{ route('ports.store') }}" onkeydown="return event.key != 'Enter';">
          @csrf
          <header class="card-header">
            <h2 class="card-title">Add Port</h2>
          </header>
          <div class="card-body">
            <div class="row form-group">
              <div class="col-lg-12 mb-2">
                <label>Port Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" placeholder="Port Name" required>
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Add Port</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- EDIT MODAL --}}
    @can('ports.edit')
    <div id="editModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" id="editForm" onkeydown="return event.key != 'Enter';">
          @csrf
          @method('PUT')
          <header class="card-header">
            <h2 class="card-title">Edit Port</h2>
          </header>
          <div class="card-body">
            <div class="row form-group">
              <div class="col-lg-12 mb-2">
                <label>Port Name <span class="text-danger">*</span></label>
                <input type="text" id="edit_name" class="form-control" name="name" required>
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Update Port</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- DELETE MODAL --}}
    @can('ports.delete')
    <div id="deleteModal" class="modal-block modal-block-warning mfp-hide">
      <section class="card">
        <form method="POST" id="deleteForm">
          @csrf
          @method('DELETE')
          <header class="card-header">
            <h2 class="card-title">Delete Port</h2>
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
var portsData = @json($ports->keyBy('id'));

function openEditModal(id) {
    var row = portsData[id];
    document.getElementById('editForm').action = '/ports/' + id;
    document.getElementById('edit_name').value = row.name;
    openMfpModal('#editModal');
}

function openDeleteModal(id) {
    var row = portsData[id];
    document.getElementById('deleteForm').action = '/ports/' + id;
    document.getElementById('delete_label').textContent = row.name;
    openMfpModal('#deleteModal');
}

$(document).ready(function() {
    $('#ports-datatable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
    });
});
</script>
@endsection
