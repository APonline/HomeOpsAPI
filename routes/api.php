<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeOpsDashboardController;
use App\Http\Controllers\HomeOpsReadController;
use App\Http\Controllers\HomeOpsWriteController;
use App\Http\Controllers\HomeOpsHomeController;

Route::prefix('homeops')->group(function () {
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

    Route::get('/dashboard', [HomeOpsDashboardController::class, 'index']);

    Route::get('/bills', [HomeOpsReadController::class, 'bills']);
    Route::post('/bills', [HomeOpsWriteController::class, 'storeBill']);
    Route::patch('/bills/{billId}/mark-paid', [HomeOpsWriteController::class, 'markBillPaid']);
    Route::patch('/bills/{billId}', [HomeOpsWriteController::class, 'updateBill']);
    Route::delete('/bills/{billId}', [HomeOpsWriteController::class, 'deleteBill']);

    Route::post('/receipts', [HomeOpsWriteController::class, 'storeReceipt']);

    Route::get('/ledger-entries', [HomeOpsReadController::class, 'ledgerEntries']);
    Route::post('/ledger-entries', [HomeOpsWriteController::class, 'storeLedgerEntry']);

    Route::get('/spending-periods', [HomeOpsReadController::class, 'spendingPeriods']);
    Route::post('/spending-periods', [HomeOpsWriteController::class, 'storePeriod']);

    Route::get('/maintenance-items', [HomeOpsReadController::class, 'maintenanceItems']);
    Route::post('/maintenance-items', [HomeOpsWriteController::class, 'storeMaintenanceItem']);
    Route::patch('/maintenance-items/{itemId}/complete', [HomeOpsWriteController::class, 'completeMaintenanceItem']);

    Route::get('/wishlist-items', [HomeOpsReadController::class, 'wishlistItems']);
    Route::post('/wishlist-items', [HomeOpsWriteController::class, 'storeWishlistItem']);
    Route::patch('/wishlist-items/{itemId}/purchased', [HomeOpsWriteController::class, 'markWishlistPurchased']);
});
