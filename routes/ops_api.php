<?php

/*
|--------------------------------------------------------------------------
| PCFix Operations REST API (JSON)
|--------------------------------------------------------------------------
| Uses Invoice Ninja's existing API authentication (X-API-TOKEN via
| token_auth). Abilities are enforced with the same `ops.*` Gates as the
| console, and every lookup is company-scoped.
*/

use App\Http\Controllers\Ops\Api\OpsApiController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['throttle:api', 'token_auth', 'valid_json', 'locale'], 'prefix' => 'api/v1/pcfix', 'as' => 'api.pcfix.'], function () {
    Route::get('dashboard', [OpsApiController::class, 'dashboard'])->name('dashboard')->middleware('can:ops.reports.financial');

    Route::get('service-requests', [OpsApiController::class, 'serviceRequests'])->name('service_requests.index')->middleware('can:ops.service_requests.view');
    Route::get('service-requests/{id}', [OpsApiController::class, 'serviceRequest'])->name('service_requests.show')->middleware('can:ops.service_requests.view');
    Route::post('service-requests', [OpsApiController::class, 'storeServiceRequest'])->name('service_requests.store')->middleware('can:ops.service_requests.manage');

    Route::get('repair-jobs', [OpsApiController::class, 'repairJobs'])->name('repair_jobs.index');
    Route::get('repair-jobs/{id}', [OpsApiController::class, 'repairJob'])->name('repair_jobs.show');
    Route::post('repair-jobs/{id}/status', [OpsApiController::class, 'repairJobStatus'])->name('repair_jobs.status');
    Route::post('repair-jobs/{id}/assign', [OpsApiController::class, 'assignRepairJob'])->name('repair_jobs.assign')->middleware('can:ops.repair_jobs.manage');

    Route::get('inventory', [OpsApiController::class, 'inventory'])->name('inventory.index')->middleware('can:ops.inventory.view');
    Route::post('inventory/{id}/adjust', [OpsApiController::class, 'adjustInventory'])->name('inventory.adjust')->middleware('can:ops.inventory.manage');

    Route::get('warranty/check', [OpsApiController::class, 'warrantyCheck'])->name('warranty.check')->middleware('can:ops.warranty.view');

    Route::get('purchase-requests', [OpsApiController::class, 'purchaseRequests'])->name('purchase_requests.index');
    Route::post('purchase-requests/{id}/decide', [OpsApiController::class, 'decidePurchaseRequest'])->name('purchase_requests.decide')->middleware('can:ops.purchase_requests.approve');
});
