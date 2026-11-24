<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\OrderStatusController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\GrabFoodPayoutController;
use App\Http\Controllers\Api\OwnerDashboardController;
use App\Http\Controllers\Api\OwnerDashboardPreferenceController;
use App\Http\Controllers\Api\ProductCatalogController;
use App\Http\Controllers\Api\ProductManagementController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\UserPermissionController;
use App\Http\Controllers\Api\InventoryController;

/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');


/*
|--------------------------------------------------------------------------
| AUTHENTICATED ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | INVENTORY MANAGEMENT
    |--------------------------------------------------------------------------
    */

    Route::prefix('inventory')->group(function () {

        Route::get('/', [InventoryController::class, 'index'])
            ->middleware('permission:inventory.view');

        Route::post('/', [InventoryController::class, 'store'])
            ->middleware('permission:inventory.manage');

        Route::put('/{id}', [InventoryController::class, 'update'])
            ->middleware('permission:inventory.manage');

        Route::delete('/{id}', [InventoryController::class, 'destroy'])
            ->middleware('permission:inventory.manage');

        Route::post('/{id}/movements', [InventoryController::class, 'move'])
            ->middleware('permission:inventory.stock.manage');

        Route::get('/movements', [InventoryController::class, 'movements'])
            ->middleware('permission:inventory.view');
    });


    /*
    |--------------------------------------------------------------------------
    | AUTHENTICATION
    |--------------------------------------------------------------------------
    */

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/me', [AuthController::class, 'me']);


    /*
    |--------------------------------------------------------------------------
    | PRODUCT CATALOG
    |--------------------------------------------------------------------------
    */

    Route::get('/products', [ProductCatalogController::class, 'index'])
        ->middleware('permission:product.view');


    /*
    |--------------------------------------------------------------------------
    | PRODUCT MANAGEMENT
    |--------------------------------------------------------------------------
    */

    Route::prefix('owner/products')->group(function () {

        Route::get('/', [ProductManagementController::class, 'index'])
            ->middleware('permission:product.view');

        Route::post('/', [ProductManagementController::class, 'store'])
            ->middleware('permission:product.create');

        Route::get('/{id}', [ProductManagementController::class, 'show'])
            ->middleware('permission:product.view');

        /*
         * Permission update diperiksa secara granular
         * di dalam ProductManagementController.
         *
         * product.update
         * product.price.update
         * product.status.update
         *
         * Autentikasi tetap diwajibkan oleh auth:sanctum.
         */

        Route::put('/{id}', [ProductManagementController::class, 'update']);

        Route::delete('/{id}', [ProductManagementController::class, 'destroy'])
            ->middleware('permission:product.delete');
    });


    /*
    |--------------------------------------------------------------------------
    | CATEGORY MANAGEMENT
    |--------------------------------------------------------------------------
    */

    Route::prefix('owner/categories')
        ->middleware('permission:category.manage')
        ->group(function () {

            Route::get('/', [CategoryController::class, 'index']);

            Route::post('/', [CategoryController::class, 'store']);

            Route::get('/{id}', [CategoryController::class, 'show']);

            Route::put('/{id}', [CategoryController::class, 'update']);

            Route::delete('/{id}', [CategoryController::class, 'destroy']);
        });


    /*
    |--------------------------------------------------------------------------
    | TRANSACTIONS
    |--------------------------------------------------------------------------
    */

    Route::get('/transactions', [TransactionController::class, 'index'])
        ->middleware('permission:transaction.view');

    Route::post('/transactions', [TransactionController::class, 'store'])
        ->middleware('permission:transaction.create');

    Route::get('/transactions/{id}', [TransactionController::class, 'show'])
        ->middleware('permission:transaction.view');

    Route::post('/transactions/{id}/payment', [PaymentController::class, 'store'])
        ->middleware('permission:transaction.payment');

    Route::patch('/transactions/{id}/status', [OrderStatusController::class, 'update'])
        ->middleware('permission:transaction.status.update');

    Route::post('/transactions/{id}/payout', [GrabFoodPayoutController::class, 'store'])
        ->middleware('role:owner');


    /*
    |--------------------------------------------------------------------------
    | EXPENSES
    |--------------------------------------------------------------------------
    */

    Route::get('/expenses', [ExpenseController::class, 'index'])
        ->middleware('permission:expense.view');

    Route::post('/expenses', [ExpenseController::class, 'store'])
        ->middleware('permission:expense.create');

    Route::get('/expenses/{id}', [ExpenseController::class, 'show'])
        ->middleware('permission:expense.view');

    Route::put('/expenses/{id}', [ExpenseController::class, 'update'])
        ->middleware('permission:expense.update');

    Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy'])
        ->middleware('permission:expense.delete');


    /*
    |--------------------------------------------------------------------------
    | OWNER ONLY
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:owner')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | OWNER DASHBOARD
        |--------------------------------------------------------------------------
        */

        Route::get('/owner/dashboard', [OwnerDashboardController::class, 'index']);

        Route::get(
            '/owner/dashboard/preferences',
            [OwnerDashboardPreferenceController::class, 'show']
        );

        Route::put(
            '/owner/dashboard/preferences',
            [OwnerDashboardPreferenceController::class, 'update']
        );


        /*
        |--------------------------------------------------------------------------
        | AUDIT LOGS
        |--------------------------------------------------------------------------
        */

        Route::get('/owner/audit-logs', [AuditLogController::class, 'index']);

        Route::get('/owner/audit-logs/{id}', [AuditLogController::class, 'show']);


        /*
        |--------------------------------------------------------------------------
        | USER PERMISSION MANAGEMENT
        |--------------------------------------------------------------------------
        */

        Route::get('/owner/users', [UserPermissionController::class, 'index']);

        Route::get(
            '/owner/users/{userId}/permissions',
            [UserPermissionController::class, 'show']
        );

        Route::put(
            '/owner/users/{userId}/permissions',
            [UserPermissionController::class, 'update']
        );
    });

});