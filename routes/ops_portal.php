<?php

/*
|--------------------------------------------------------------------------
| PCFix customer features inside the existing Invoice Ninja client portal
|--------------------------------------------------------------------------
| Same guard and middleware as routes/client.php (auth:contact). All lookups
| are scoped to the logged-in contact's client in the controller.
*/

use App\Http\Controllers\ClientPortal\OpsPortalController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['auth:contact', 'locale', 'domain_db', 'check_client_existence'], 'prefix' => 'client', 'as' => 'client.ops.'], function () {
    Route::get('service-requests', [OpsPortalController::class, 'requests'])->name('service_requests.index');
    Route::get('service-requests/create', [OpsPortalController::class, 'createRequest'])->name('service_requests.create');
    Route::post('service-requests', [OpsPortalController::class, 'storeRequest'])->name('service_requests.store')->middleware('throttle:10,1');
    Route::get('service-requests/{id}', [OpsPortalController::class, 'showRequest'])->name('service_requests.show');
    Route::get('repairs/{id}', [OpsPortalController::class, 'showRepair'])->name('repairs.show');
    Route::get('warranty', [OpsPortalController::class, 'warranty'])->name('warranty.index');
    Route::post('warranty/claims', [OpsPortalController::class, 'storeClaim'])->name('warranty.claims.store')->middleware('throttle:10,1');
});
