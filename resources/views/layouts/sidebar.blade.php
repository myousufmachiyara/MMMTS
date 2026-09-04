<aside id="sidebar-left" class="sidebar-left">
  <div class="sidebar-header">
    <div class="sidebar-title" style="display: flex; justify-content: space-between;">
      <a href="{{ route('dashboard') }}" class="logo">
        <img src="/assets/img/billtrix-logo-1.png" class="sidebar-logo" alt="BillTrix Logo" />
      </a>
      <div class="d-md-none toggle-sidebar-left col-1" data-toggle-class="sidebar-left-opened" data-target="html" data-fire-event="sidebar-left-opened">
        <i class="fas fa-times" aria-label="Toggle sidebar"></i>
      </div>
    </div>
    <div class="sidebar-toggle d-none d-md-block" data-toggle-class="sidebar-left-collapsed" data-target="html" data-fire-event="sidebar-left-toggle">
      <i class="fas fa-bars" aria-label="Toggle sidebar"></i>
    </div>
  </div>

  <div class="nano">
    <div class="nano-content">
      <nav id="menu" class="nav-main" role="navigation">
        <ul class="nav nav-main">

          <li class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('dashboard') }}">
              <i class="fa fa-home" aria-hidden="true"></i>
              <span>Dashboard</span>
            </a>
          </li>

          {{-- User Management --}}
          @if(auth()->user()->can('user_roles.index') || auth()->user()->can('users.index'))
          <li class="nav-parent">
            <a class="nav-link" href="#"><i class="fa fa-user-shield"></i> <span>Users</span></a>
            <ul class="nav nav-children">
              @can('user_roles.index')
              <li><a class="nav-link" href="{{ route('roles.index') }}">Roles & Permissions</a></li>
              @endcan
              @can('users.index')
              <li><a class="nav-link" href="{{ route('users.index') }}">All Users</a></li>
              @endcan
            </ul>
          </li>
          @endif

          {{-- Accounts --}}
          @if(auth()->user()->can('coa.index') || auth()->user()->can('shoa.index'))
          <li class="nav-parent">
            <a class="nav-link" href="#"><i class="fa fa-book"></i> <span>Accounts</span></a>
            <ul class="nav nav-children">
              @can('coa.index')
              <li><a class="nav-link" href="{{ route('coa.index') }}">Chart of Accounts</a></li>
              {{-- Parties are managed from Chart of Accounts, differentiated by account_type --}}
              <li><a class="nav-link" href="{{ route('coa.index', ['account_type' => 'customer']) }}">Customers</a></li>
              <li><a class="nav-link" href="{{ route('coa.index', ['account_type' => 'vendor']) }}">Vendors</a></li>
              @endcan
              @can('shoa.index')
              <li><a class="nav-link" href="{{ route('shoa.index') }}">Sub Heads</a></li>
              @endcan
            </ul>
          </li>
          @endif

          {{-- Fleet Setup --}}
          @if(
            auth()->user()->can('vehicles.index') ||
            auth()->user()->can('companies.index') ||
            auth()->user()->can('ports.index') ||
            auth()->user()->can('customer_locations.index') ||
            auth()->user()->can('vehicle_routes.index')
          )
          <li class="nav-parent">
            <a class="nav-link" href="#"><i class="fa fa-truck"></i> <span>Setup</span></a>
            <ul class="nav nav-children">
              @can('companies.index')
              <li><a class="nav-link" href="{{ route('companies.index') }}">Companies</a></li>
              @endcan
              @can('vehicles.index')
              <li><a class="nav-link" href="{{ route('vehicles.index') }}">Vehicles</a></li>
              @endcan
              @can('ports.index')
              <li><a class="nav-link" href="{{ route('ports.index') }}">Ports</a></li>
              @endcan
              @can('customer_locations.index')
              <li><a class="nav-link" href="{{ route('customer-locations.index') }}">Customer Locations</a></li>
              @endcan
              @can('vehicle_routes.index')
              <li><a class="nav-link" href="{{ route('vehicle-routes.index') }}">Vehicle Routes</a></li>
              @endcan
            </ul>
          </li>
          @endif

          {{-- Operations --}}
          @if(
            auth()->user()->can('delivery_challans.index') ||
            auth()->user()->can('daily_jobs.index') ||
            auth()->user()->can('bills.index') ||
            auth()->user()->can('invoices.index') ||
            auth()->user()->can('payments.index')
          )
          <li class="nav-parent">
            <a class="nav-link" href="#"><i class="fa fa-route"></i> <span>Operations</span></a>
            <ul class="nav nav-children">
              @can('delivery_challans.index')
              <li><a class="nav-link" href="{{ route('delivery-challans.index') }}">Delivery Challans</a></li>
              @endcan
              @can('daily_jobs.index')
              <li><a class="nav-link" href="{{ route('daily-jobs.index') }}">Daily Jobs</a></li>
              @endcan
              @can('bills.index')
              <li><a class="nav-link" href="{{ route('bills.index') }}">Bills</a></li>
              @endcan
              @can('invoices.index')
              <li><a class="nav-link" href="{{ route('invoices.index') }}">Invoices</a></li>
              @endcan
              @can('payments.index')
              <li><a class="nav-link" href="{{ route('payments.index') }}">Payments</a></li>
              @endcan
            </ul>
          </li>
          @endif

          {{-- Vouchers --}}
          @if(auth()->user()->can('vouchers.index'))
            <li class="nav-parent">
                <a class="nav-link" href="#">
                    <i class="fa fa-money-check"></i>
                    <span>Vouchers</span>
                </a>
                <ul class="nav nav-children">
                    @can('vouchers.index')
                      <li><a class="nav-link" href="{{ route('vouchers.index', 'journal') }}">Journal Vouchers</a></li>
                    @endcan
                    @can('vouchers.index')
                      <li><a class="nav-link" href="{{ route('vouchers.index', 'payment') }}">Payment Vouchers</a></li>
                    @endcan
                    @can('vouchers.index')
                      <li><a class="nav-link" href="{{ route('vouchers.index', 'receipt') }}">Receipt Vouchers</a></li>
                    @endcan
                </ul>
            </li>
          @endif

          {{-- Reports --}}
          @if(
            auth()->user()->can('reports.accounts') ||
            auth()->user()->can('reports.fleet')
          )
          <li class="nav-parent">
            <a class="nav-link" href="#">
              <i class="fa fa-chart-bar"></i>
              <span>Reports</span>
            </a>
            <ul class="nav nav-children">
              @can('reports.accounts')
                <li><a class="nav-link" href="{{ route('reports.accounts') }}">Accounting</a></li>
              @endcan
              @can('reports.fleet')
                <li><a class="nav-link" href="{{ route('reports.fleet') }}">Others</a></li>
              @endcan
            </ul>
          </li>
          @endif
        </ul>
      </nav>
    </div>

    <script>
      if (typeof localStorage !== 'undefined') {
        if (localStorage.getItem('sidebar-left-position') !== null) {
          var sidebarLeft = document.querySelector('#sidebar-left .nano-content');
          sidebarLeft.scrollTop = localStorage.getItem('sidebar-left-position');
        }
      }
    </script>
  </div>
</aside>
