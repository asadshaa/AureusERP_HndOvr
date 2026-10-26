<?php

use Illuminate\Support\Facades\Route;
use Webkul\Security\Http\Controllers\SwitchCompanyController;
use Webkul\Security\Livewire\AcceptInvitation;

Route::middleware(['web'])->group(function () {
    Route::middleware('signed')
        ->get('invitation/{invitation}/accept', AcceptInvitation::class)
        ->name('security.invitation.accept');

    // Topbar company switcher (only among the user's own companies).
    Route::middleware('auth')
        ->post('admin/switch-company', SwitchCompanyController::class)
        ->name('security.switch-company');
});
