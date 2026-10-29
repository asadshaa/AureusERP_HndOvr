<?php

use Illuminate\Support\Facades\Route;
use Webkul\Recruitment\Http\Controllers\JobOfferLetterController;
use Webkul\Recruitment\Http\Controllers\JobOfferResponseController;

/*
 | Job offers (HR parity 3.6). Plugin routes are loaded without a group, so
 | 'web' is explicit.
 |
 | The candidate's page is public: the emailed link is a temporary signed URL
 | (until the end of the offer's expiry date) carrying the offer's response
 | token. The controller checks the signature itself so a tampered link and an
 | expired one get their own message; the POST goes to the same signed URL.
 |
 | HR's letter download needs a signed-in user and a short-lived signed link;
 | the controller re-checks that the user may see the offer's salary.
 */
Route::middleware(['web', 'throttle:30,1'])
    ->prefix('careers/offers')
    ->group(function (): void {
        Route::get('{offer}/{token}', [JobOfferResponseController::class, 'show'])
            ->whereNumber('offer')
            ->name('recruitments.offers.respond');

        Route::post('{offer}/{token}', [JobOfferResponseController::class, 'respond'])
            ->whereNumber('offer')
            ->name('recruitments.offers.respond.submit');

        Route::get('{offer}/{token}/letter', [JobOfferResponseController::class, 'letter'])
            ->whereNumber('offer')
            ->name('recruitments.offers.letter');
    });

Route::middleware(['web', 'auth', 'signed'])
    ->get('admin/recruitment-offers/{offer}/letter', JobOfferLetterController::class)
    ->whereNumber('offer')
    ->name('recruitments.offers.letter.download');
