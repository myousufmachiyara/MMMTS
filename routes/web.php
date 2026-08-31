<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\{
    DashboardController,
    SubHeadOfAccController,
    COAController,
    SaleInvoiceController,
    PurchaseInvoiceController,
    PurchaseReturnController,
    ProductController,
    UserController,
    RoleController,
    AttributeController,
    ProductCategoryController,
    VoucherController,
    InventoryReportController,
    PurchaseReportController,
    SalesReportController,
    AccountsReportController,
    SaleReturnController,
    PermissionController,
    ProductSubcategoryController,
    VehicleController,
    OurCompanyController,
    PortController,
    CustomerLocationController,
    VehicleRouteController,
    DailyJobController,
    BillController,
    InvoiceController,
    PaymentController,
    FleetReportController,
};

Auth::routes();

Route::middleware(['auth'])->group(function () {
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::put('/users/{id}/change-password', [UserController::class, 'changePassword'])->name('users.changePassword');
    Route::put('/users/{id}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggleActive');
    Route::post('/change-my-password', [UserController::class, 'changeMyPassword'])->name('users.changeMyPassword');

    // Toggle-active endpoints for the new Fleet Setup master-data modules
    // (not part of the generic CRUD loop below — mirrors users.toggleActive)
    Route::put('/vehicles/{id}/toggle-active', [VehicleController::class, 'toggleActive'])->middleware('check.permission:vehicles.edit')->name('vehicles.toggleActive');
    Route::put('/companies/{id}/toggle-active', [OurCompanyController::class, 'toggleActive'])->middleware('check.permission:companies.edit')->name('companies.toggleActive');
    Route::put('/ports/{id}/toggle-active', [PortController::class, 'toggleActive'])->middleware('check.permission:ports.edit')->name('ports.toggleActive');
    Route::put('/customer-locations/{id}/toggle-active', [CustomerLocationController::class, 'toggleActive'])->middleware('check.permission:customer_locations.edit')->name('customer-locations.toggleActive');
    Route::put('/vehicle-routes/{id}/toggle-active', [VehicleRouteController::class, 'toggleActive'])->middleware('check.permission:vehicle_routes.edit')->name('vehicle-routes.toggleActive');

    // AJAX picker endpoints — must be registered BEFORE the generic "$uri/{id}" loop below,
    // otherwise "bills/get-jobs" / "invoices/get-bills" would be swallowed by the {id} route.
    Route::get('/bills/get-jobs', [BillController::class, 'getJobs'])->middleware('check.permission:bills.index')->name('bills.getJobs');
    Route::get('/invoices/get-bills', [InvoiceController::class, 'getBills'])->middleware('check.permission:invoices.index')->name('invoices.getBills');

    // Delivery Challan — not a separate module, just a save + print action
    // against a single Direct job (see DailyJobController).
    Route::put('/daily-jobs/{id}/dc', [DailyJobController::class, 'saveDc'])->middleware('check.permission:daily_jobs.edit')->name('daily-jobs.saveDc');
    Route::get('/daily-jobs/{id}/dc/print', [DailyJobController::class, 'printDc'])->middleware('check.permission:daily_jobs.print')->name('daily-jobs.printDc');

    // Common Modules
    $modules = [
        // User Management
        'roles' => ['controller' => RoleController::class, 'permission' => 'user_roles'],
        'permissions' => ['controller' => PermissionController::class, 'permission' => 'role_permissions'],
        'users' => ['controller' => UserController::class, 'permission' => 'users'],

        // Accounts
        'coa' => ['controller' => COAController::class, 'permission' => 'coa'],
        'shoa' => ['controller' => SubHeadOfAccController::class, 'permission' => 'shoa'],
        // Vouchers
        'vouchers' => ['controller' => VoucherController::class, 'permission' => 'vouchers'],

        // Fleet Setup / Masters
        'vehicles' => ['controller' => VehicleController::class, 'permission' => 'vehicles'],
        'companies' => ['controller' => OurCompanyController::class, 'permission' => 'companies'],
        'ports' => ['controller' => PortController::class, 'permission' => 'ports'],
        'customer-locations' => ['controller' => CustomerLocationController::class, 'permission' => 'customer_locations'],
        'vehicle-routes' => ['controller' => VehicleRouteController::class, 'permission' => 'vehicle_routes'],

        // Operations
        'daily-jobs' => ['controller' => DailyJobController::class, 'permission' => 'daily_jobs'],
        'bills' => ['controller' => BillController::class, 'permission' => 'bills'],
        'invoices' => ['controller' => InvoiceController::class, 'permission' => 'invoices'],
        'payments' => ['controller' => PaymentController::class, 'permission' => 'payments'],
    ];

    foreach ($modules as $uri => $config) {
        $controller = $config['controller'];
        $permission = $config['permission'];

        // Determine route parameter
        $param = $uri === 'roles' ? '{role}' : '{id}';

        if ($uri === 'vouchers') {
            // Voucher routes with type in all relevant actions
            Route::prefix("$uri/{type}")->group(function () use ($controller, $permission) {
                Route::get('/', [$controller, 'index'])->middleware("check.permission:$permission.index")->name("vouchers.index");
                Route::get('/create', [$controller, 'create'])->middleware("check.permission:$permission.create")->name("vouchers.create");
                Route::post('/', [$controller, 'store'])->middleware("check.permission:$permission.create")->name("vouchers.store");

                Route::get('/{id}', [$controller, 'show'])->middleware("check.permission:$permission.index")->name("vouchers.show");
                Route::get('/{id}/edit', [$controller, 'edit'])->middleware("check.permission:$permission.edit")->name("vouchers.edit");
                Route::put('/{id}', [$controller, 'update'])->middleware("check.permission:$permission.edit")->name("vouchers.update");
                Route::delete('/{id}', [$controller, 'destroy'])->middleware("check.permission:$permission.delete")->name("vouchers.destroy");
                Route::get('/{id}/print', [$controller, 'print'])->middleware("check.permission:$permission.print")->name('vouchers.print');
            });

            continue;
        }

        // Index & Create
        Route::get("$uri", [$controller, 'index'])->middleware("check.permission:$permission.index")->name("$uri.index");
        Route::get("$uri/create", [$controller, 'create'])->middleware("check.permission:$permission.create")->name("$uri.create");
        Route::post("$uri", [$controller, 'store'])->middleware("check.permission:$permission.create")->name("$uri.store");

        // Show, Edit, Update, Delete, Print
        Route::get("$uri/$param", [$controller, 'show'])->middleware("check.permission:$permission.index")->name("$uri.show");
        Route::get("$uri/$param/edit", [$controller, 'edit'])->middleware("check.permission:$permission.edit")->name("$uri.edit");
        Route::put("$uri/$param", [$controller, 'update'])->middleware("check.permission:$permission.edit")->name("$uri.update");
        Route::delete("$uri/$param", [$controller, 'destroy'])->middleware("check.permission:$permission.delete")->name("$uri.destroy");
        Route::get("$uri/$param/print", [$controller, 'print'])->middleware("check.permission:$permission.print")->name("$uri.print");
    }

    // Reports (readonly)
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('accounts', [AccountsReportController::class, 'accounts'])->name('accounts');
        Route::get('fleet', [FleetReportController::class, 'index'])->middleware('check.permission:reports.fleet')->name('fleet');
    });
});