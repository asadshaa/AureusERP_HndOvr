<?php

use Illuminate\Support\Facades\Route;
use Webkul\Accounting\Http\Controllers\ClaimController;

/*
 | Public claim links for recipients who do not run AureusERP. The token in
 | the URL is the only credential, so these are rate limited and every
 | failure renders one neutral page (see ClaimController).
 */
Route::prefix('transmissions/claim')->middleware('throttle:30,1')->group(function () {
    Route::get('{token}', [ClaimController::class, 'show'])->name('accounting.claim.show');
    Route::get('{token}/payload', [ClaimController::class, 'payload'])->name('accounting.claim.payload');
    Route::get('{token}/file', [ClaimController::class, 'file'])->name('accounting.claim.file');
});
