<?php

/*
|--------------------------------------------------------------------------
| PCFix Hub Operations Console (staff) + public service request form
|--------------------------------------------------------------------------
| Loaded by App\Providers\PCFixServiceProvider inside the "web" middleware
| group (sessions + CSRF). Every staff route requires `ops_auth` and an
| explicit `can:ops.*` ability; record-level checks live in the controllers.
*/

use App\Http\Controllers\Ops\AdminController;
use App\Http\Controllers\Ops\AuthController;
use App\Http\Controllers\Ops\BillingController;
use App\Http\Controllers\Ops\CustomerController;
use App\Http\Controllers\Ops\DashboardController;
use App\Http\Controllers\Ops\FinanceController;
use App\Http\Controllers\Ops\InventoryController;
use App\Http\Controllers\Ops\ProcurementController;
use App\Http\Controllers\Ops\PublicServiceRequestController;
use App\Http\Controllers\Ops\RepairJobController;
use App\Http\Controllers\Ops\ServiceRequestController;
use App\Http\Controllers\Ops\WarrantyController;
use Illuminate\Support\Facades\Route;

/* Public, unauthenticated customer request form (company identified by its public key). */
Route::get('service-request/{company_key}', [PublicServiceRequestController::class, 'create'])->name('ops.public.create')->middleware('throttle:60,1');
Route::post('service-request/{company_key}', [PublicServiceRequestController::class, 'store'])->name('ops.public.store')->middleware('throttle:6,1');
Route::get('service-request/{company_key}/submitted/{reference}', [PublicServiceRequestController::class, 'thanks'])->name('ops.public.thanks')->middleware('throttle:60,1');

Route::prefix('ops')->name('ops.')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.submit')->middleware('throttle:8,1');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('ops_auth')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard')->middleware('can:ops.dashboard.view');
        Route::post('company/switch', [AuthController::class, 'switchCompany'])->name('company.switch');
        Route::get('notifications', [DashboardController::class, 'notifications'])->name('notifications');
        Route::post('notifications/read', [DashboardController::class, 'markRead'])->name('notifications.read');

        /* Customers & service requests (Module 1) */
        Route::middleware('can:ops.service_requests.view')->group(function () {
            Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('customers/{client}', [CustomerController::class, 'show'])->name('customers.show');
            Route::get('service-requests', [ServiceRequestController::class, 'index'])->name('service_requests.index');
            Route::get('service-requests/{id}', [ServiceRequestController::class, 'show'])->name('service_requests.show');
        });

        Route::middleware('can:ops.service_requests.manage')->group(function () {
            Route::get('service-requests-new', [ServiceRequestController::class, 'create'])->name('service_requests.create');
            Route::post('service-requests', [ServiceRequestController::class, 'store'])->name('service_requests.store');
            Route::put('service-requests/{id}', [ServiceRequestController::class, 'update'])->name('service_requests.update');
            Route::post('service-requests/{id}/link-client', [ServiceRequestController::class, 'linkClient'])->name('service_requests.link_client');
            Route::post('service-requests/{id}/create-client', [ServiceRequestController::class, 'createClient'])->name('service_requests.create_client');
            Route::post('service-requests/{id}/quote', [ServiceRequestController::class, 'quote'])->name('service_requests.quote');
            Route::post('service-requests/{id}/cancel', [ServiceRequestController::class, 'cancel'])->name('service_requests.cancel');
        });

        /* Repair jobs (Module 2) — record-level access enforced in controller */
        Route::get('repair-jobs', [RepairJobController::class, 'index'])->name('repair_jobs.index');
        Route::get('repair-jobs/history', [RepairJobController::class, 'history'])->name('repair_jobs.history');
        Route::get('repair-jobs/{id}', [RepairJobController::class, 'show'])->name('repair_jobs.show');
        Route::post('repair-jobs/{id}/status', [RepairJobController::class, 'status'])->name('repair_jobs.status');
        Route::post('repair-jobs/{id}/note', [RepairJobController::class, 'note'])->name('repair_jobs.note');
        Route::post('repair-jobs/{id}/parts', [RepairJobController::class, 'addPart'])->name('repair_jobs.parts.add');
        Route::delete('repair-jobs/{id}/parts/{part}', [RepairJobController::class, 'removePart'])->name('repair_jobs.parts.remove');
        Route::post('repair-jobs/{id}/revision', [RepairJobController::class, 'revision'])->name('repair_jobs.revision');
        Route::post('repair-jobs/{id}/complete', [RepairJobController::class, 'complete'])->name('repair_jobs.complete');
        Route::post('repair-jobs/{id}/quote', [RepairJobController::class, 'quote'])->name('repair_jobs.quote');

        Route::middleware('can:ops.repair_jobs.manage')->group(function () {
            Route::post('repair-jobs/{id}/assign', [RepairJobController::class, 'assign'])->name('repair_jobs.assign');
            Route::post('repair-jobs/{id}/auto-assign', [RepairJobController::class, 'autoAssign'])->name('repair_jobs.auto_assign');
            Route::post('repair-jobs/{id}/priority', [RepairJobController::class, 'priority'])->name('repair_jobs.priority');
        });

        Route::get('technicians', [RepairJobController::class, 'technicians'])->name('technicians.index')->middleware('can:ops.repair_jobs.view_all');
        Route::put('technicians/{user}', [RepairJobController::class, 'updateTechnician'])->name('technicians.update')->middleware('can:ops.technicians.manage');

        /* Billing (Module 3) */
        Route::get('billing', [BillingController::class, 'index'])->name('billing.index')->middleware('can:ops.billing.view');
        Route::post('billing/{id}/invoice', [BillingController::class, 'generate'])->name('billing.generate')->middleware('can:ops.billing.manage');

        /* Warranty (Module 4) */
        Route::middleware('can:ops.warranty.view')->group(function () {
            Route::get('warranties', [WarrantyController::class, 'index'])->name('warranty.index');
            Route::get('warranties/{id}', [WarrantyController::class, 'show'])->name('warranty.show');
            Route::get('warranty-claims', [WarrantyController::class, 'claims'])->name('warranty.claims.index');
            Route::get('warranty-claims/{id}', [WarrantyController::class, 'showClaim'])->name('warranty.claims.show');
            Route::get('warranty-check', [WarrantyController::class, 'check'])->name('warranty.check');
        });

        Route::middleware('can:ops.warranty.manage')->group(function () {
            Route::put('warranties/{id}', [WarrantyController::class, 'update'])->name('warranty.update');
            Route::post('warranty-claims', [WarrantyController::class, 'storeClaim'])->name('warranty.claims.store');
            Route::post('warranty-claims/{id}/decide', [WarrantyController::class, 'decide'])->name('warranty.claims.decide');
        });

        /* Inventory (Module 5A) */
        Route::middleware('can:ops.inventory.view')->group(function () {
            Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
            Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
            Route::get('inventory/low-stock', [InventoryController::class, 'lowStock'])->name('inventory.low');
            Route::get('inventory/export', [InventoryController::class, 'export'])->name('inventory.export');
            Route::get('inventory/items/{id}', [InventoryController::class, 'show'])->name('inventory.show');
        });

        Route::middleware('can:ops.inventory.manage')->group(function () {
            Route::get('inventory/new', [InventoryController::class, 'create'])->name('inventory.create');
            Route::post('inventory', [InventoryController::class, 'store'])->name('inventory.store');
            Route::get('inventory/items/{id}/edit', [InventoryController::class, 'edit'])->name('inventory.edit');
            Route::put('inventory/items/{id}', [InventoryController::class, 'update'])->name('inventory.update');
            Route::post('inventory/items/{id}/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
            Route::post('inventory/items/{id}/archive', [InventoryController::class, 'archive'])->name('inventory.archive');
        });

        /* Procurement (Module 5B/5C) */
        Route::middleware('can:ops.suppliers.manage')->group(function () {
            Route::get('suppliers', [ProcurementController::class, 'suppliers'])->name('suppliers.index');
            Route::get('suppliers/new', [ProcurementController::class, 'createSupplier'])->name('suppliers.create');
            Route::post('suppliers', [ProcurementController::class, 'storeSupplier'])->name('suppliers.store');
            Route::get('suppliers/{id}', [ProcurementController::class, 'showSupplier'])->name('suppliers.show');
            Route::put('suppliers/{id}', [ProcurementController::class, 'updateSupplier'])->name('suppliers.update');
        });

        Route::get('purchase-requests', [ProcurementController::class, 'requests'])->name('purchase_requests.index');
        Route::get('purchase-requests/new', [ProcurementController::class, 'createRequest'])->name('purchase_requests.create')->middleware('can:ops.purchase_requests.create');
        Route::post('purchase-requests', [ProcurementController::class, 'storeRequest'])->name('purchase_requests.store')->middleware('can:ops.purchase_requests.create');
        Route::get('purchase-requests/{id}', [ProcurementController::class, 'showRequest'])->name('purchase_requests.show');
        Route::post('purchase-requests/{id}/decide', [ProcurementController::class, 'decide'])->name('purchase_requests.decide')->middleware('can:ops.purchase_requests.approve');
        Route::post('purchase-requests/{id}/order', [ProcurementController::class, 'order'])->name('purchase_requests.order')->middleware('can:ops.purchase_requests.receive');
        Route::post('purchase-requests/{id}/receive', [ProcurementController::class, 'receive'])->name('purchase_requests.receive')->middleware('can:ops.purchase_requests.receive');
        Route::post('purchase-requests/{id}/expense', [ProcurementController::class, 'expense'])->name('purchase_requests.expense')->middleware('can:ops.expenses.manage');
        Route::get('purchase-orders', [ProcurementController::class, 'purchaseOrders'])->name('purchase_orders.index')->middleware('can:ops.purchase_requests.receive');

        /* Finance (Module 6) */
        Route::get('expenses', [FinanceController::class, 'expenses'])->name('finance.expenses')->middleware('can:ops.expenses.manage');
        Route::get('reports', [FinanceController::class, 'reports'])->name('finance.reports')->middleware('can:ops.reports.financial');
        Route::get('reports/export', [FinanceController::class, 'export'])->name('finance.export')->middleware('can:ops.reports.financial');

        /* Administration */
        Route::middleware('can:ops.admin.users')->group(function () {
            Route::get('admin/users', [AdminController::class, 'users'])->name('admin.users');
            Route::put('admin/users/{user}', [AdminController::class, 'updateUser'])->name('admin.users.update');
            Route::get('admin/settings', [AdminController::class, 'settings'])->name('admin.settings');
        });
        Route::get('admin/audit', [AdminController::class, 'audit'])->name('admin.audit')->middleware('can:ops.audit.view');
    });
});
