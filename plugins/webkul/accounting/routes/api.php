<?php

use Illuminate\Support\Facades\Route;
use Webkul\Accounting\Http\Controllers\API\V1\DocumentTransferController;
use Webkul\Accounting\Http\Controllers\API\V1\PeerTransmissionController;
use Webkul\Accounting\Http\Middleware\VerifyPeerSignature;

/*
 | Internal, user-authenticated transfer API (intra-instance).
 */
Route::prefix('admin/api/v1')->middleware(['auth:sanctum'])->group(function () {
    Route::get('document-transfers', [DocumentTransferController::class, 'index']);
    Route::post('document-transfers', [DocumentTransferController::class, 'store']);
    Route::post('document-transfers/{transfer}/claim', [DocumentTransferController::class, 'claim']);
    Route::post('document-transfers/{transfer}/cancel', [DocumentTransferController::class, 'cancel']);
});

/*
 | Peer-to-peer surface, talked to by another AureusERP instance.
 |
 | Deliberately NOT behind auth:sanctum -- a peer is an instance, not a
 | user. Pairing is unauthenticated because the one-time code is itself the
 | credential; everything after it is signature-verified. Both are rate
 | limited: pairing because the code is guessable-in-principle, and the
 | transmission endpoint because it is internet-facing.
 */
Route::prefix('api/v1/peer')->group(function () {
    Route::post('pair', [PeerTransmissionController::class, 'pair'])
        ->middleware('throttle:10,1');

    Route::post('transmissions', [PeerTransmissionController::class, 'store'])
        ->middleware([VerifyPeerSignature::class, 'throttle:120,1']);
});
