@extends('layouts.app')

@section('title', 'Fleet | Our Companies')

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
        <h2 class="card-title">Our Companies</h2>
        @can('companies.create')
          <button type="button" class="modal-with-form btn btn-primary" href="#addModal">
            <i class="fas fa-plus"></i> Add Company
          </button>
        @endcan
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="companies-datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>Logo</th>
                <th>Code</th>
                <th>Name</th>
                <th>NTN#</th>
                <th>Address</th>
                <th>Contact No.</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($companies as $index => $row)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td>
                    @if($row->logo_url)
                      <img src="{{ $row->logo_url }}" alt="{{ $row->name }}" style="height:32px;width:32px;object-fit:contain;">
                    @else
                      —
                    @endif
                  </td>
                  <td><code>{{ $row->code }}</code></td>
                  <td><strong>{{ $row->name }}</strong></td>
                  <td>{{ $row->ntn ?? '—' }}</td>
                  <td>{{ $row->address ?? '—' }}</td>
                  <td>{{ $row->contact_no ?? '—' }}</td>
                  <td>
                    <span class="badge {{ $row->is_active ? 'bg-success' : 'bg-secondary' }}">
                        {{ $row->is_active ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td class="actions">
                    @can('companies.edit')
                      <a class="text-primary me-1" href="javascript:void(0)"
                         onclick="openEditModal({{ $row->id }})" title="Edit">
                          <i class="fas fa-edit"></i>
                      </a>
                      <form action="{{ route('companies.toggleActive', $row->id) }}" method="POST" style="display:inline;">
                          @csrf
                          @method('PUT')
                          <button type="submit" class="btn btn-link p-0 text-{{ $row->is_active ? 'danger' : 'success' }} me-1"
                                  title="{{ $row->is_active ? 'Deactivate' : 'Activate' }}">
                              <i class="fa fa-toggle-{{ $row->is_active ? 'on' : 'off' }}"></i>
                          </button>
                      </form>
                    @endcan
                    @can('companies.delete')
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
    @can('companies.create')
    <div id="addModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" action="{{ route('companies.store') }}" enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
          @csrf
          <header class="card-header">
            <h2 class="card-title">Add Company</h2>
          </header>
          <div class="card-body">
            <div class="row form-group">
              <div class="col-lg-6 mb-2">
                <label>Code <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="code" placeholder="Company Code" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" placeholder="Company Name" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>NTN#</label>
                <input type="text" class="form-control" name="ntn" placeholder="NTN#">
              </div>
              <div class="col-lg-6 mb-2">
                <label>Logo</label>
                <input type="file" class="form-control" name="logo" accept="image/*">
              </div>
              <div class="col-lg-6 mb-2">
                <label>Address</label>
                <textarea class="form-control" rows="2" name="address" placeholder="Address"></textarea>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Contact No.</label>
                <input type="text" class="form-control" name="contact_no" placeholder="Contact No.">
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Add Company</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- EDIT MODAL --}}
    @can('companies.edit')
    <div id="editModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" id="editForm" enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
          @csrf
          @method('PUT')
          <header class="card-header">
            <h2 class="card-title">Edit Company</h2>
          </header>
          <div class="card-body">
            <div class="row form-group">
              <div class="col-lg-6 mb-2">
                <label>Code <span class="text-danger">*</span></label>
                <input type="text" id="edit_code" class="form-control" name="code" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Name <span class="text-danger">*</span></label>
                <input type="text" id="edit_name" class="form-control" name="name" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>NTN#</label>
                <input type="text" id="edit_ntn" class="form-control" name="ntn">
              </div>
              <div class="col-lg-6 mb-2">
                <label>Logo <small class="text-muted">(leave blank to keep current)</small></label>
                <input type="file" class="form-control" name="logo" accept="image/*">
                <img id="edit_logo_preview" src="" alt="" style="height:32px;margin-top:6px;display:none;object-fit:contain;">
              </div>
              <div class="col-lg-6 mb-2">
                <label>Address</label>
                <textarea id="edit_address" class="form-control" rows="2" name="address"></textarea>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Contact No.</label>
                <input type="text" id="edit_contact_no" class="form-control" name="contact_no">
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Update Company</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- DELETE MODAL --}}
    @can('companies.delete')
    <div id="deleteModal" class="modal-block modal-block-warning mfp-hide">
      <section class="card">
        <form method="POST" id="deleteForm">
          @csrf
          @method('DELETE')
          <header class="card-header">
            <h2 class="card-title">Delete Company</h2>
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
var companiesData = @json($companies->keyBy('id'));

function openEditModal(id) {
    var row = companiesData[id];
    document.getElementById('editForm').action = '/companies/' + id;
    document.getElementById('edit_code').value = row.code;
    document.getElementById('edit_name').value = row.name;
    document.getElementById('edit_ntn').value = row.ntn || '';
    document.getElementById('edit_address').value = row.address || '';
    document.getElementById('edit_contact_no').value = row.contact_no || '';
    var preview = document.getElementById('edit_logo_preview');
    if (row.logo_url) {
        preview.src = row.logo_url;
        preview.style.display = 'inline-block';
    } else {
        preview.style.display = 'none';
    }
    openMfpModal('#editModal');
}

function openDeleteModal(id) {
    var row = companiesData[id];
    document.getElementById('deleteForm').action = '/companies/' + id;
    document.getElementById('delete_label').textContent = row.name;
    openMfpModal('#deleteModal');
}

$(document).ready(function() {
    $('#companies-datatable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
    });
});
</script>
@endsection
