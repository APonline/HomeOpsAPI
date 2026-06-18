<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeOpsDashboardController;
use App\Http\Controllers\HomeOpsReadController;
use App\Http\Controllers\HomeOpsWriteController;

Route::prefix('homeops')->group(function () {
    Route::get('/dashboard', [HomeOpsDashboardController::class, 'index']);

    Route::get('/bills', [HomeOpsReadController::class, 'bills']);
    Route::get('/ledger-entries', [HomeOpsReadController::class, 'ledgerEntries']);
    Route::get('/spending-periods', [HomeOpsReadController::class, 'spendingPeriods']);
    Route::get('/maintenance-items', [HomeOpsReadController::class, 'maintenanceItems']);
    Route::get('/wishlist-items', [HomeOpsReadController::class, 'wishlistItems']);

    Route::post('/bills', [HomeOpsWriteController::class, 'storeBill']);
    Route::patch('/bills/{billId}/mark-paid', [HomeOpsWriteController::class, 'markBillPaid']);

    Route::post('/receipts', [HomeOpsWriteController::class, 'storeReceipt']);
    Route::post('/ledger-entries', [HomeOpsWriteController::class, 'storeLedgerEntry']);
    Route::post('/spending-periods', [HomeOpsWriteController::class, 'storePeriod']);

    Route::post('/maintenance-items', [HomeOpsWriteController::class, 'storeMaintenanceItem']);
    Route::patch('/maintenance-items/{itemId}/complete', [HomeOpsWriteController::class, 'completeMaintenanceItem']);

    Route::post('/wishlist-items', [HomeOpsWriteController::class, 'storeWishlistItem']);
    Route::patch('/wishlist-items/{itemId}/purchased', [HomeOpsWriteController::class, 'markWishlistPurchased']);
});
