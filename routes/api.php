<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeOpsAuthController;
use App\Http\Controllers\HomeOpsDashboardController;
use App\Http\Controllers\HomeOpsReadController;
use App\Http\Controllers\HomeOpsWriteController;
use App\Http\Controllers\HomeOpsHomeController;
use App\Http\Controllers\HomeOpsBudgetController;
use App\Http\Controllers\HomeOpsCoreBillsController;
use App\Http\Controllers\HomeOpsV0StatusController;
use App\Http\Controllers\HomeOpsRecordsController;
use App\Http\Controllers\HomeOpsV1Controller;
use App\Http\Middleware\HomeOpsTokenAuth;

Route::prefix('homeops')->group(function () {
    Route::post('/auth/login', [HomeOpsAuthController::class, 'login']);
    Route::post('/auth/register', [HomeOpsAuthController::class, 'register']);

    Route::middleware([HomeOpsTokenAuth::class])->group(function () {
        Route::get('/auth/me', [HomeOpsAuthController::class, 'me']);
        Route::patch('/auth/profile', [HomeOpsAuthController::class, 'updateProfile']);
        Route::patch('/auth/password', [HomeOpsAuthController::class, 'updatePassword']);
        Route::post('/auth/logout', [HomeOpsAuthController::class, 'logout']);

        Route::get('/homes', [HomeOpsHomeController::class, 'index']);
        Route::post('/homes', [HomeOpsHomeController::class, 'store']);
        Route::get('/homes/{homeId}', [HomeOpsHomeController::class, 'show']);
        Route::patch('/homes/{homeId}', [HomeOpsHomeController::class, 'update']);
        Route::get('/homes/{homeId}/rooms', [HomeOpsHomeController::class, 'rooms']);
        Route::post('/homes/{homeId}/rooms', [HomeOpsHomeController::class, 'storeRoom']);
        Route::get('/homes/{homeId}/assets', [HomeOpsHomeController::class, 'assets']);
        Route::post('/homes/{homeId}/assets', [HomeOpsHomeController::class, 'storeAsset']);
        Route::get('/homes/{homeId}/timeline', [HomeOpsHomeController::class, 'timeline']);
        Route::post('/homes/{homeId}/timeline', [HomeOpsHomeController::class, 'storeTimelineEvent']);
        Route::get('/homes/{homeId}/core-bills', [HomeOpsCoreBillsController::class, 'index']);
        Route::post('/homes/{homeId}/core-bills/sync', [HomeOpsCoreBillsController::class, 'sync']);

        // V1 naming alias: homes are now treated as properties in the product language.
        Route::get('/properties', [HomeOpsHomeController::class, 'index']);
        Route::post('/properties', [HomeOpsHomeController::class, 'store']);
        Route::get('/properties/{homeId}', [HomeOpsHomeController::class, 'show']);
        Route::patch('/properties/{homeId}', [HomeOpsHomeController::class, 'update']);
        Route::get('/properties/{homeId}/core-bills', [HomeOpsCoreBillsController::class, 'index']);
        Route::post('/properties/{homeId}/core-bills/sync', [HomeOpsCoreBillsController::class, 'sync']);

        Route::get('/dashboard', [HomeOpsDashboardController::class, 'index']);
        Route::get('/v0/status', [HomeOpsV0StatusController::class, 'show']);

        Route::get('/budget-profile', [HomeOpsBudgetController::class, 'show']);
        Route::patch('/budget-profile', [HomeOpsBudgetController::class, 'update']);

        Route::get('/bills', [HomeOpsReadController::class, 'bills']);
        Route::post('/bills', [HomeOpsWriteController::class, 'storeBill']);
        Route::patch('/bills/{billId}/mark-paid', [HomeOpsWriteController::class, 'markBillPaid']);
        Route::patch('/bills/{billId}/mark-unpaid', [HomeOpsWriteController::class, 'markBillUnpaid']);
        Route::patch('/bills/{billId}/skip-month', [HomeOpsWriteController::class, 'skipBillForMonth']);
        Route::patch('/bill-instances/{instanceId}', [HomeOpsWriteController::class, 'updateBillInstance']);
        Route::patch('/bills/{billId}', [HomeOpsWriteController::class, 'updateBill']);
        Route::delete('/bills/{billId}', [HomeOpsWriteController::class, 'deleteBill']);

        Route::get('/receipts', [HomeOpsRecordsController::class, 'receipts']);
        Route::post('/receipts', [HomeOpsWriteController::class, 'storeReceipt']);
        Route::patch('/receipts/{receiptId}', [HomeOpsRecordsController::class, 'updateReceipt']);
        Route::delete('/receipts/{receiptId}', [HomeOpsRecordsController::class, 'deleteReceipt']);

        Route::get('/ledger-entries', [HomeOpsReadController::class, 'ledgerEntries']);
        Route::post('/ledger-entries', [HomeOpsWriteController::class, 'storeLedgerEntry']);
        Route::patch('/ledger-entries/{entryId}', [HomeOpsRecordsController::class, 'updateLedgerEntry']);
        Route::delete('/ledger-entries/{entryId}', [HomeOpsRecordsController::class, 'deleteLedgerEntry']);

        Route::get('/spending-periods', [HomeOpsReadController::class, 'spendingPeriods']);
        Route::post('/spending-periods', [HomeOpsWriteController::class, 'storePeriod']);
        Route::patch('/spending-periods/{periodId}', [HomeOpsRecordsController::class, 'updatePeriod']);
        Route::delete('/spending-periods/{periodId}', [HomeOpsRecordsController::class, 'deletePeriod']);

        Route::get('/maintenance-items', [HomeOpsReadController::class, 'maintenanceItems']);
        Route::post('/maintenance-items', [HomeOpsWriteController::class, 'storeMaintenanceItem']);
        Route::patch('/maintenance-items/{itemId}/complete', [HomeOpsWriteController::class, 'completeMaintenanceItem']);
        Route::patch('/maintenance-items/{itemId}/restock', [HomeOpsWriteController::class, 'restockMaintenanceItem']);
        Route::patch('/maintenance-items/{itemId}', [HomeOpsRecordsController::class, 'updateMaintenanceItem']);
        Route::delete('/maintenance-items/{itemId}', [HomeOpsRecordsController::class, 'deleteMaintenanceItem']);
        Route::get('/maintenance-items/{itemId}/logs', [HomeOpsRecordsController::class, 'maintenanceLogs']);

        Route::get('/wishlist-items', [HomeOpsReadController::class, 'wishlistItems']);
        Route::post('/wishlist-items', [HomeOpsWriteController::class, 'storeWishlistItem']);
        Route::patch('/wishlist-items/{itemId}/purchased', [HomeOpsWriteController::class, 'markWishlistPurchased']);
        Route::patch('/wishlist-items/{itemId}', [HomeOpsRecordsController::class, 'updateWishlistItem']);
        Route::delete('/wishlist-items/{itemId}', [HomeOpsRecordsController::class, 'deleteWishlistItem']);

        Route::get('/financial-accounts', [HomeOpsV1Controller::class, 'financialAccounts']);
        Route::post('/financial-accounts', [HomeOpsV1Controller::class, 'storeFinancialAccount']);
        Route::patch('/financial-accounts/{accountId}', [HomeOpsV1Controller::class, 'updateFinancialAccount']);
        Route::delete('/financial-accounts/{accountId}', [HomeOpsV1Controller::class, 'deleteFinancialAccount']);

        Route::get('/documents', [HomeOpsV1Controller::class, 'documents']);
        Route::post('/documents', [HomeOpsV1Controller::class, 'storeDocument']);
        Route::patch('/documents/{documentId}', [HomeOpsV1Controller::class, 'updateDocument']);
        Route::delete('/documents/{documentId}', [HomeOpsV1Controller::class, 'deleteDocument']);

        Route::get('/reports', [HomeOpsV1Controller::class, 'reports']);
    });
});
