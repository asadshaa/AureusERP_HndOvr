<?php

use Illuminate\Support\Facades\Route;
use Webkul\Employee\Http\Controllers\EmployeeDocumentDownloadController;

/*
 | Employee document downloads. The link is a short-lived signed URL
 | (EmployeeDocument::temporaryDownloadUrl()); the controller also needs a
 | signed-in user who may see the document, so a leaked link alone opens
 | nothing. 'web' gives the session (plugin routes get no group by default).
 */
Route::middleware(['web', 'signed', 'throttle:60,1'])
    ->get('employees/documents/{document}/download', EmployeeDocumentDownloadController::class)
    ->whereNumber('document')
    ->name('employees.documents.download');
