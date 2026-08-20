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
              @endcan
              @can('shoa.index')
              <li><a class="nav-link" href="{{ route('shoa.index') }}">Sub Heads</a></li>
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
            auth()->user()->can('reports.accounts')
          )
          <li class="nav-parent">
            <a class="nav-link" href="#">
              <i class="fa fa-chart-bar"></i>
              <span>Reports</span>
            </a>
            <ul class="nav nav-children">
              @can('reports.accounts')
                <li><a class="nav-link" href="{{ route('reports.accounts') }}">Accounts</a></li>
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
