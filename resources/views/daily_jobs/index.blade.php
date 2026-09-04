@extends('layouts.app')

@section('title', 'Daily Jobs')

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
        <h2 class="card-title">Daily Jobs</h2>
        @can('daily_jobs.create')
          <div>
            <a href="{{ route('daily-jobs.create', ['type' => 'direct']) }}" class="btn btn-primary">
              <i class="fas fa-plus"></i> Add Direct Job
            </a>
            <a href="{{ route('daily-jobs.create', ['type' => 'party_to_party']) }}" class="btn btn-outline-primary">
              <i class="fas fa-plus"></i> Add Party-to-Party Job
            </a>
          </div>
        @endcan
      </header>

      <div class="card-body">
        {{-- ── Filters ───────────────────────────────────── --}}
        <form method="GET" action="{{ route('daily-jobs.index') }}" class="row form-group mb-3">
          <div class="col-lg-3 mb-2">
            <label>From Date</label>
            <input type="date" name="from_date" class="form-control" value="{{ $from }}">
          </div>
          <div class="col-lg-3 mb-2">
            <label>To Date</label>
            <input type="date" name="to_date" class="form-control" value="{{ $to }}">
          </div>
          <div class="col-lg-3 mb-2">
            <label>Customer</label>
            <select name="customer_id" class="form-control select2-js">
              <option value="all">All</option>
              @foreach($customers as $c)
                <option value="{{ $c->id }}" {{ request('customer_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-lg-2 mb-2">
            <label>Billed</label>
            <select name="billed" class="form-control">
              <option value="all">All</option>
              <option value="billed" {{ request('billed') == 'billed' ? 'selected' : '' }}>Billed</option>
              <option value="non_billed" {{ request('billed') == 'non_billed' ? 'selected' : '' }}>Non-Billed</option>
            </select>
          </div>
          <div class="col-lg-2 mb-2">
            <label>Completion</label>
            <select name="status" class="form-control">
              <option value="all">All</option>
              <option value="incomplete" {{ request('status') == 'incomplete' ? 'selected' : '' }}>Incomplete</option>
              <option value="complete" {{ request('status') == 'complete' ? 'selected' : '' }}>Complete</option>
            </select>
          </div>
          <div class="col-lg-1 mb-2 d-flex align-items-end">
            <button type="submit" class="btn btn-secondary w-100">Filter</button>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="jobs-datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>Job No.</th>
                <th>Type</th>
                <th>Date</th>
                <th>Vehicle(s)</th>
                <th>Customer</th>
                <th>Route(s) / Destination</th>
                <th>Job Total</th>
                <th>Completion</th>
                <th>Billed</th>
                <th>DC</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($jobs as $index => $row)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td><code>{{ $row->job_no }}</code></td>
                  <td>
                    <span class="badge {{ $row->job_type === 'party_to_party' ? 'bg-info' : 'bg-primary' }}">
                        {{ $row->job_type === 'party_to_party' ? 'Party-to-Party' : 'Direct' }}
                    </span>
                  </td>
                  <td>{{ $row->date->format('d-m-Y') }}</td>
                  <td>
                    @if($row->job_type === 'party_to_party')
                      {{ $row->pty_vehicle_no ?? '—' }}
                    @else
                      {{ $row->vehicles->pluck('vehicle.name')->filter()->implode(', ') ?: '—' }}
                      @if($row->vehicles->count() > 1)
                        <span class="badge bg-light text-dark border">{{ $row->vehicles->count() }} vehicles</span>
                      @endif
                    @endif
                  </td>
                  <td>{{ $row->customer->name ?? '—' }}</td>
                  <td>{{ $row->job_type === 'party_to_party' ? ($row->pty_destination ?? '—') : ($row->route->name ?? ($row->vehicles->pluck('route.name')->filter()->implode(', ') ?: '—')) }}</td>
                  <td class="text-end">{{ number_format($row->job_total, 2) }}</td>
                  <td>
                    @if($row->job_type === 'party_to_party')
                      <span class="text-muted">—</span>
                    @else
                      <span class="badge {{ $row->status === 'complete' ? 'bg-success' : 'bg-warning text-dark' }}">
                          {{ ucfirst($row->status) }}
                      </span>
                    @endif
                  </td>
                  <td>
                    <span class="badge {{ $row->bill_id ? 'bg-success' : 'bg-secondary' }}">
                        {{ $row->bill_id ? 'Billed' : 'Non-Billed' }}
                    </span>
                  </td>
                  <td>
                    @if($row->job_type === 'direct')
                      @if($row->has_dc)
                        <span class="badge bg-success">DC issued</span>
                      @else
                        <span class="badge bg-warning text-dark">No DC</span>
                      @endif
                    @else
                      <span class="text-muted">—</span>
                    @endif
                  </td>
                  <td class="actions">
                    @can('daily_jobs.edit')
                      @if(!$row->bill_id)
                        <a class="text-primary me-1" href="{{ route('daily-jobs.edit', $row->id) }}" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                      @endif
                    @endcan
                    @can('daily_jobs.print')
                      <a class="text-success me-1" href="{{ route('daily-jobs.print', $row->id) }}" target="_blank" title="Print Job">
                          <i class="fas fa-print"></i>
                      </a>
                    @endcan
                    {{-- Legacy per-job DC action — only shown for jobs created before the
                         standalone Delivery Challan module existed (they already carry a
                         dc_no on the job header). New jobs link a Delivery Challan per
                         vehicle-row from the Edit Job screen instead — see delivery-challans.index. --}}
                    @if($row->job_type === 'direct' && $row->dc_no)
                      @can('daily_jobs.edit')
                        <a class="text-secondary me-1" href="javascript:void(0)"
                           onclick="openDcModal({{ $row->id }})" title="Edit DC (legacy)">
                            <i class="fas fa-file-alt"></i>
                        </a>
                      @endcan
                      @can('daily_jobs.print')
                        @if($row->dc_no)
                          <a class="text-info me-1" href="{{ route('daily-jobs.printDc', $row->id) }}" target="_blank" title="Print DC (legacy)">
                              <i class="fas fa-print"></i>
                          </a>
                        @endif
                      @endcan
                    @endif
                    @can('daily_jobs.delete')
                      @if(!$row->bill_id)
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

    {{-- DELETE MODAL --}}
    @can('daily_jobs.delete')
    <div id="deleteModal" class="modal-block modal-block-warning mfp-hide">
      <section class="card">
        <form method="POST" id="deleteForm">
          @csrf
          @method('DELETE')
          <header class="card-header">
            <h2 class="card-title">Delete Daily Job</h2>
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

    {{-- DELIVERY CHALLAN MODAL --}}
    @can('daily_jobs.edit')
    <div id="dcModal" class="modal-block mfp-hide">
      <section class="card">
        <form method="POST" id="dcForm">
          @csrf
          @method('PUT')
          <header class="card-header">
            <h2 class="card-title">Delivery Challan — <span id="dc_job_no"></span></h2>
          </header>
          <div class="card-body">
            <p class="text-muted small" id="dc_no_label"></p>
            <div class="row form-group">
              <div class="col-lg-4 mb-2">
                <label>DC Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="dc_date" id="dc_date" required>
              </div>
              <div class="col-lg-4 mb-2">
                <label>Consignee (Customer)</label>
                <input type="text" class="form-control" id="dc_disp_customer" readonly>
              </div>
              <div class="col-lg-4 mb-2">
                <label>Port (Pickup / Dropoff)</label>
                <input type="text" class="form-control" id="dc_disp_ports" readonly>
              </div>
            </div>
            <div class="row form-group">
              <div class="col-lg-4 mb-2">
                <label>Clearing Agent</label>
                <input type="text" class="form-control" name="dc_clearing_agent" id="dc_clearing_agent">
              </div>
              <div class="col-lg-4 mb-2">
                <label>Unit</label>
                <input type="text" class="form-control" name="dc_unit" id="dc_unit">
              </div>
              <div class="col-lg-4 mb-2">
                <label>BL #</label>
                <input type="text" class="form-control" name="dc_bl_no" id="dc_bl_no">
              </div>
            </div>
            <div class="row form-group">
              <div class="col-lg-4 mb-2">
                <label>Container No.</label>
                <input type="text" class="form-control" name="dc_container_no" id="dc_container_no">
              </div>
              <div class="col-lg-4 mb-2">
                <label>Quantity</label>
                <input type="text" class="form-control" name="dc_quantity" id="dc_quantity">
              </div>
              <div class="col-lg-4 mb-2">
                <label>Truck No.</label>
                <input type="text" class="form-control" name="dc_truck_no" id="dc_truck_no">
              </div>
            </div>
            <div class="row form-group">
              <div class="col-lg-12 mb-2">
                <label>Item Description</label>
                <input type="text" class="form-control" name="dc_item_description" id="dc_item_description">
              </div>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Save DC</button>
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
var jobsData = @json($jobs->keyBy('id'));

function openDeleteModal(id) {
    var row = jobsData[id];
    document.getElementById('deleteForm').action = '/daily-jobs/' + id;
    document.getElementById('delete_label').textContent = row.job_no;
    openMfpModal('#deleteModal');
}

function openDcModal(id) {
    var row = jobsData[id];

    document.getElementById('dcForm').action = '/daily-jobs/' + id + '/dc';
    document.getElementById('dc_job_no').textContent = row.job_no;
    document.getElementById('dc_no_label').textContent = row.dc_no ? ('DC No.: ' + row.dc_no) : 'No DC issued yet — saving will issue one.';

    document.getElementById('dc_date').value = row.dc_date || '{{ date('Y-m-d') }}';
    document.getElementById('dc_disp_customer').value = (row.customer && row.customer.name) || '';
    document.getElementById('dc_disp_ports').value =
        ((row.pickup_port && row.pickup_port.name) || '—') + ' / ' + ((row.dropoff_port && row.dropoff_port.name) || '—');

    document.getElementById('dc_clearing_agent').value = row.dc_clearing_agent || '';
    document.getElementById('dc_unit').value = row.dc_unit || '';
    document.getElementById('dc_bl_no').value = row.dc_bl_no || '';
    document.getElementById('dc_container_no').value = row.dc_container_no || row.container_no || '';
    document.getElementById('dc_quantity').value = row.dc_quantity || (row.route && row.route.dimension) || '';
    document.getElementById('dc_truck_no').value = row.dc_truck_no || (row.vehicle && row.vehicle.vehicle_no) || '';
    document.getElementById('dc_item_description').value = row.dc_item_description || row.item_description || '';

    openMfpModal('#dcModal');
}

$(document).ready(function() {
    $('#jobs-datatable').DataTable({
        pageLength: 50,
        order: [],
        searching: true,
    });
});
</script>
@endsection