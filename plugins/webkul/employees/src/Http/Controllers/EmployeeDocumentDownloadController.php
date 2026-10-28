<?php

namespace Webkul\Employee\Http\Controllers;

use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Employee\Models\EmployeeDocument;

/**
 * Serves one employee document from the private disk. Reached only through
 * a signed, expiring link ('signed' middleware), and only for a signed-in
 * user whom EmployeeDocumentPolicy::download() allows.
 */
class EmployeeDocumentDownloadController
{
    public function __invoke(int $document): StreamedResponse|RedirectResponse
    {
        if (! Auth::check()) {
            return redirect()->guest(Filament::getPanel('admin')->getLoginUrl() ?? url('/admin/login'));
        }

        $record = EmployeeDocument::query()->withTrashed()->with(['employee', 'documentType'])->findOrFail($document);

        abort_if($record->trashed() || ! Gate::allows('download', $record), 403);

        $disk = Storage::disk(EmployeeDocument::DISK);

        abort_unless($disk->exists($record->file_path), 404);

        return $disk->download($record->file_path, $record->original_name ?: basename($record->file_path), [
            'Content-Type'           => $record->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
    }
}
