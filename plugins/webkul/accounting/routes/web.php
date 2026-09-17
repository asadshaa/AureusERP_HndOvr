<?php

use Illuminate\Support\Facades\Route;
use Webkul\Accounting\Http\Controllers\ClaimController;
use Webkul\Accounting\Http\Controllers\WebRtcTransferController;

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

/*
 | WebRTC direct transfer pages.
 |
 | Both need the 'web' group: plugin routes are registered by
 | PackageServiceProvider::loadRoutesFrom() with no group at all, so without
 | this the session never starts -- csrf_token() on these pages would emit a
 | token bound to nothing, and the authenticated POSTs they make would 419.
 |
 | The send page is the sender's own console and is gated accordingly. The
 | receive page stays open: its whole point is that the recipient may not
 | have an account here, and the session code is their credential.
 */
Route::middleware('web')->group(function () {
    Route::get('p2p/receive/{code?}', [WebRtcTransferController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('accounting.webrtc.receive');

    Route::get('p2p/send/{code}', [WebRtcTransferController::class, 'send'])
        ->middleware('auth')
        ->name('accounting.webrtc.send');
});
