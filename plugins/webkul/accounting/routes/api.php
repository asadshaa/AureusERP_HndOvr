<?php

use Illuminate\Support\Facades\Route;
use Webkul\Accounting\Http\Controllers\API\V1\DocumentTransferController;
use Webkul\Accounting\Http\Controllers\API\V1\PeerTransmissionController;
use Webkul\Accounting\Http\Controllers\API\V1\WebRtcSignalingController;
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

/*
 | WebRTC browser-to-browser direct transfer signaling.
 |
 | Split by what the caller has to prove. Opening a session and reading the
 | file's bytes are the sender's own acts, so they need a session and are
 | re-authorized per request. The handshake itself is anonymous on purpose:
 | the recipient may hold no account on this instance, so the session code
 | is their only credential -- which is why it carries 50 bits and why these
 | endpoints expose nothing but SDP and display metadata.
 |
 | There is deliberately no ingest endpoint. See
 | docs/superpowers/specs/2026-09-16-webrtc-browser-to-browser-transfer.md.
 */
Route::prefix('api/v1/webrtc')->group(function () {
    Route::middleware(['web', 'auth'])->group(function () {
        Route::post('sessions', [WebRtcSignalingController::class, 'initiate']);
        Route::post('sessions/{code}/offer', [WebRtcSignalingController::class, 'offer']);
        Route::get('sessions/{code}/binary', [WebRtcSignalingController::class, 'binary']);
    });

    Route::middleware(['throttle:60,1'])->group(function () {
        Route::get('sessions/{code}', [WebRtcSignalingController::class, 'show']);
        Route::post('sessions/{code}/answer', [WebRtcSignalingController::class, 'answer']);
        Route::post('sessions/{code}/candidate', [WebRtcSignalingController::class, 'candidate']);
        Route::get('sessions/{code}/poll', [WebRtcSignalingController::class, 'poll']);
        Route::post('sessions/{code}/complete', [WebRtcSignalingController::class, 'complete']);
    });
});
