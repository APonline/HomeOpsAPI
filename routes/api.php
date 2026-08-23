<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeOpsAuthController;
use App\Http\Controllers\HomeOpsDashboardController;
use App\Http\Controllers\HomeOpsReadController;
use App\Http\Controllers\HomeOpsWriteController;
use App\Http\Controllers\HomeOpsHomeController;
use App\Http\Controllers\HomeOpsBudgetController;
use App\Http\Controllers\HomeOpsCoreBillsController;
use App\Http\Controllers\HomeOpsCloseoutController;
use App\Http\Controllers\HomeOpsV0StatusController;
use App\Http\Controllers\HomeOpsRecordsController;
use App\Http\Controllers\HomeOpsReceiptScanController;
use App\Http\Controllers\HomeOpsV1Controller;
use App\Http\Controllers\HomeOpsAdminController;
use App\Http\Controllers\HomeOpsPublicContentController;
use App\Http\Controllers\HomeOpsFeatureController;
use App\Http\Middleware\HomeOpsTokenAuth;
use App\Http\Middleware\HomeOpsAdminAuth;
use App\Http\Middleware\HomeOpsRequestAudit;
use App\Http\Controllers\HomeOpsPlaidController;
use App\Http\Controllers\HomeOpsGroceryInventoryController;
use App\Http\Controllers\HomeOpsAttentionController;
use App\Http\Controllers\HomeOpsGroceryRecipeController;

Route::prefix('homeops')->group(function () {
    // Public auth attempts are still request-audited (credentials are redacted by middleware).
    Route::middleware([HomeOpsRequestAudit::class])->group(function () {
        Route::post('/auth/login', [HomeOpsAuthController::class, 'login']);
        Route::post('/auth/register', [HomeOpsAuthController::class, 'register']);
        Route::get('/public/content', [HomeOpsPublicContentController::class, 'index']);
    });

    Route::middleware([HomeOpsTokenAuth::class, HomeOpsRequestAudit::class])->group(function () {
        Route::get('/auth/me', [HomeOpsAuthController::class, 'me']);
        Route::patch('/auth/profile', [HomeOpsAuthController::class, 'updateProfile']);
        Route::patch('/auth/password', [HomeOpsAuthController::class, 'updatePassword']);
        Route::post('/auth/logout', [HomeOpsAuthController::class, 'logout']);
        Route::get('/features', [HomeOpsFeatureController::class, 'index']);

        Route::get('/homes', [HomeOpsHomeController::class, 'index']);
        Route::post('/homes', [HomeOpsHomeController::class, 'store']);
        Route::post('/property-setup', [HomeOpsHomeController::class, 'storeSetup']);
        Route::get('/homes/{homeId}', [HomeOpsHomeController::class, 'show']);
        Route::patch('/homes/{homeId}', [HomeOpsHomeController::class, 'update']);
        Route::delete('/homes/{homeId}', [HomeOpsHomeController::class, 'destroy']);
        Route::get('/homes/{homeId}/rooms', [HomeOpsHomeController::class, 'rooms']);
        Route::post('/homes/{homeId}/rooms', [HomeOpsHomeController::class, 'storeRoom']);
        Route::patch('/homes/{homeId}/rooms/{roomId}', [HomeOpsHomeController::class, 'updateRoom']);
        Route::delete('/homes/{homeId}/rooms/{roomId}', [HomeOpsHomeController::class, 'deleteRoom']);
        Route::get('/homes/{homeId}/assets', [HomeOpsHomeController::class, 'assets']);
        Route::post('/homes/{homeId}/assets', [HomeOpsHomeController::class, 'storeAsset']);
        Route::patch('/homes/{homeId}/assets/{assetId}', [HomeOpsHomeController::class, 'updateAsset']);
        Route::delete('/homes/{homeId}/assets/{assetId}', [HomeOpsHomeController::class, 'deleteAsset']);
        Route::get('/homes/{homeId}/timeline', [HomeOpsHomeController::class, 'timeline']);
        Route::post('/homes/{homeId}/timeline', [HomeOpsHomeController::class, 'storeTimelineEvent']);
        Route::patch('/homes/{homeId}/timeline/{eventId}', [HomeOpsHomeController::class, 'updateTimelineEvent']);
        Route::delete('/homes/{homeId}/timeline/{eventId}', [HomeOpsHomeController::class, 'deleteTimelineEvent']);
        Route::get('/homes/{homeId}/core-bills', [HomeOpsCoreBillsController::class, 'index']);
        Route::post('/homes/{homeId}/core-bills/sync', [HomeOpsCoreBillsController::class, 'sync']);

        // V1 naming alias: homes are now treated as properties in the product language.
        Route::get('/properties', [HomeOpsHomeController::class, 'index']);
        Route::post('/properties', [HomeOpsHomeController::class, 'store']);
        Route::get('/properties/{homeId}', [HomeOpsHomeController::class, 'show']);
        Route::patch('/properties/{homeId}', [HomeOpsHomeController::class, 'update']);
        Route::delete('/properties/{homeId}', [HomeOpsHomeController::class, 'destroy']);
        Route::get('/properties/{homeId}/core-bills', [HomeOpsCoreBillsController::class, 'index']);
        Route::post('/properties/{homeId}/core-bills/sync', [HomeOpsCoreBillsController::class, 'sync']);

        Route::get('/dashboard', [HomeOpsDashboardController::class, 'index']);
        Route::get('/attention', [HomeOpsAttentionController::class, 'index']);
        Route::get('/v0/status', [HomeOpsV0StatusController::class, 'show']);

        Route::get('/budget-profile', [HomeOpsBudgetController::class, 'show']);
        Route::patch('/budget-profile', [HomeOpsBudgetController::class, 'update']);

        Route::get('/month-close', [HomeOpsCloseoutController::class, 'show'])->middleware('homeops.feature:month_close');
        Route::post('/month-close/close', [HomeOpsCloseoutController::class, 'close'])->middleware('homeops.feature:month_close');
        Route::post('/month-close/reopen', [HomeOpsCloseoutController::class, 'reopen'])->middleware('homeops.feature:month_close');

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
        Route::post('/receipts/scan', [HomeOpsReceiptScanController::class, 'scan'])->middleware('homeops.feature:receipt_scanner');
        Route::post('/receipts/scans/{scanId}/commit', [HomeOpsReceiptScanController::class, 'commit'])->middleware('homeops.feature:receipt_scanner');
        Route::delete('/receipts/scans/{scanId}', [HomeOpsReceiptScanController::class, 'cancel'])->middleware('homeops.feature:receipt_scanner');
        Route::get('/receipts/{receiptId}/file', [HomeOpsReceiptScanController::class, 'download']);
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

        Route::get('/grocery-inventory', [HomeOpsGroceryInventoryController::class, 'index']);
        Route::post('/grocery-inventory', [HomeOpsGroceryInventoryController::class, 'store']);
        Route::post('/grocery-inventory/starter', [HomeOpsGroceryInventoryController::class, 'starter']);
        Route::patch('/grocery-inventory/{slotId}', [HomeOpsGroceryInventoryController::class, 'update']);
        Route::patch('/grocery-inventory/{slotId}/state', [HomeOpsGroceryInventoryController::class, 'updateState']);
        Route::patch('/grocery-inventory/{slotId}/shopping', [HomeOpsGroceryInventoryController::class, 'toggleShopping']);
        Route::post('/grocery-inventory/{slotId}/equip-replacement', [HomeOpsGroceryInventoryController::class, 'equipReplacement']);
        Route::delete('/grocery-inventory/{slotId}', [HomeOpsGroceryInventoryController::class, 'destroy']);

        Route::get('/grocery-recipes', [HomeOpsGroceryRecipeController::class, 'index']);
        Route::post('/grocery-recipes', [HomeOpsGroceryRecipeController::class, 'store']);
        Route::patch('/grocery-recipes/{recipeId}', [HomeOpsGroceryRecipeController::class, 'update']);
        Route::post('/grocery-recipes/{recipeId}/shopping', [HomeOpsGroceryRecipeController::class, 'addMissingToShopping']);
        Route::post('/grocery-recipes/{recipeId}/cook', [HomeOpsGroceryRecipeController::class, 'cook']);
        Route::delete('/grocery-recipes/{recipeId}', [HomeOpsGroceryRecipeController::class, 'destroy']);


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

        Route::get('/financial-accounts', [HomeOpsV1Controller::class, 'financialAccounts'])->middleware('homeops.feature:financing');
        Route::post('/financial-accounts', [HomeOpsV1Controller::class, 'storeFinancialAccount'])->middleware('homeops.feature:financing');
        Route::patch('/financial-accounts/{accountId}', [HomeOpsV1Controller::class, 'updateFinancialAccount'])->middleware('homeops.feature:financing');
        Route::delete('/financial-accounts/{accountId}', [HomeOpsV1Controller::class, 'deleteFinancialAccount'])->middleware('homeops.feature:financing');

        Route::post('/plaid/link-token', [HomeOpsPlaidController::class, 'linkToken'])->middleware('homeops.feature:financing');
        Route::post('/plaid/exchange', [HomeOpsPlaidController::class, 'exchange'])->middleware('homeops.feature:financing');
        Route::post('/plaid/refresh-balances', [HomeOpsPlaidController::class, 'refreshBalances'])->middleware('homeops.feature:financing');
        Route::post('/plaid/update-link-token', [HomeOpsPlaidController::class, 'updateLinkToken'])->middleware('homeops.feature:financing');

        Route::get('/documents', [HomeOpsV1Controller::class, 'documents'])->middleware('homeops.feature:documents');
        Route::post('/documents', [HomeOpsV1Controller::class, 'storeDocument'])->middleware('homeops.feature:documents');
        Route::get('/documents/{documentId}/file', [HomeOpsV1Controller::class, 'downloadDocument'])->middleware('homeops.feature:documents');
        Route::patch('/documents/{documentId}', [HomeOpsV1Controller::class, 'updateDocument'])->middleware('homeops.feature:documents');
        Route::delete('/documents/{documentId}', [HomeOpsV1Controller::class, 'deleteDocument'])->middleware('homeops.feature:documents');

        Route::get('/reports', [HomeOpsV1Controller::class, 'reports']);

        Route::prefix('admin')->middleware([HomeOpsAdminAuth::class])->group(function () {
            Route::get('/overview', [HomeOpsAdminController::class, 'overview']);

            Route::get('/customers', [HomeOpsAdminController::class, 'customers']);
            Route::get('/customers/{userId}', [HomeOpsAdminController::class, 'customer']);
            Route::get('/customers/{userId}/timeline', [HomeOpsAdminController::class, 'customerTimeline']);
            Route::patch('/customers/{userId}', [HomeOpsAdminController::class, 'updateCustomer']);
            Route::post('/customers/{userId}/revoke-sessions', [HomeOpsAdminController::class, 'revokeSessions']);
            Route::post('/customers/{userId}/notes', [HomeOpsAdminController::class, 'addNote']);
            Route::post('/customers/{userId}/support-cases', [HomeOpsAdminController::class, 'createCase']);
            Route::post('/customers/{userId}/data-requests', [HomeOpsAdminController::class, 'createDataRequest']);

            Route::get('/support-cases', [HomeOpsAdminController::class, 'supportCases']);
            Route::get('/support-cases/{caseId}', [HomeOpsAdminController::class, 'supportCase']);
            Route::patch('/support-cases/{caseId}', [HomeOpsAdminController::class, 'updateCase']);
            Route::post('/support-cases/{caseId}/messages', [HomeOpsAdminController::class, 'addCaseMessage']);

            Route::get('/logs', [HomeOpsAdminController::class, 'logs']);
            Route::get('/audit-logs', [HomeOpsAdminController::class, 'auditLogs']);
            Route::get('/system-events', [HomeOpsAdminController::class, 'systemEvents']);

            Route::get('/feature-flags', [HomeOpsAdminController::class, 'featureFlags']);
            Route::patch('/feature-flags/{flagId}', [HomeOpsAdminController::class, 'updateFeatureFlag']);
            Route::put('/feature-flags/{flagId}/customers/{userId}', [HomeOpsAdminController::class, 'setFeatureOverride']);
            Route::delete('/feature-flags/{flagId}/customers/{userId}', [HomeOpsAdminController::class, 'deleteFeatureOverride']);

            Route::get('/cms', [HomeOpsAdminController::class, 'cmsEntries']);
            Route::patch('/cms/{entryId}', [HomeOpsAdminController::class, 'updateCmsEntry']);

            Route::patch('/data-requests/{requestId}', [HomeOpsAdminController::class, 'updateDataRequest']);
        });
    });
});
