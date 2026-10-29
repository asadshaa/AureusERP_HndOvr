<?php

use Illuminate\Support\Facades\Route;
use Webkul\Employee\Http\Controllers\EmployeeDocumentDownloadController;
use Webkul\Employee\Http\Controllers\PwaManifestController;

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

/*
 | Manifest of the "Aureus" home-screen app (opens on /admin/check-in). No
 | session or cookies: browsers fetch manifests anonymously, and it holds no
 | user data. The service worker itself is the static public/sw.js.
 */
Route::get('manifest.webmanifest', PwaManifestController::class)
    ->name('employees.pwa.manifest');
