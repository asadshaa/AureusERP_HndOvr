<?php

use Illuminate\Support\Facades\Route;
use Webkul\Employee\Http\Controllers\EmployeeRequestLetterController;

/*
 | Issued HR letters live on the private disk. The link handed out is a
 | temporary signed URL, and the signed-in user is re-checked in the
 | controller. Plugin routes are loaded without a group, so 'web' is explicit.
 */
Route::middleware(['web', 'auth', 'signed'])
    ->get('admin/employee-requests/{employeeRequest}/letter', EmployeeRequestLetterController::class)
    ->name('employees.requests.letter');
