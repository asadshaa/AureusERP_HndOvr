<?php

namespace Webkul\Accounting\Services\Drive;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Models\DocumentDriveSync;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\DriveSyncService;
use Webkul\Security\Models\User;

class InvoiceDriveExportService
{
    public function __construct(
        protected DocumentService $documentService,
        protected DriveSyncService $driveSyncService,
    ) {}

    /**
     * Exports an invoice PDF to the company's linked Google Drive.
     * Creates or updates a Document record and uploads to Google Drive.
     */
    public function exportInvoice(?User $user, Move $invoice, ?string $pdfDiskPath = null): DocumentDriveSync
    {
        if (! config('accounting_drive.enabled')) {
            throw new RuntimeException('Google Drive sync is not enabled for this installation.');
        }

        $user = $user ?? Auth::user() ?? User::query()->first();
        if (! $user) {
            throw new RuntimeException('No acting user found to record invoice document.');
        }

        $pdfContent = null;
        if ($pdfDiskPath && Storage::disk('public')->exists($pdfDiskPath)) {
            $pdfContent = Storage::disk('public')->get($pdfDiskPath);
        }

        if (! $pdfContent) {
            $html = view('accounts::invoice/actions/preview.index', ['record' => $invoice])->render();
            $pdf = Pdf::loadHTML(mb_convert_encoding($html, 'UTF-8', 'UTF-8'))
                ->setPaper('A4', 'portrait')
                ->setOption('defaultFont', 'Arial')
                ->setOption('isHtml5ParserEnabled', true)
                ->setOption('isRemoteEnabled', true);
            $pdfContent = $pdf->output();
        }

        $sanitizedName = str_replace(['/', '\\'], '-', $invoice->name ?: 'invoice-'.$invoice->id);
        $cleanName = Str::slug($sanitizedName);
        $fileName = "{$cleanName}.pdf";

        $tempPath = tempnam(sys_get_temp_dir(), 'inv_exp_');
        file_put_contents($tempPath, $pdfContent);
        $uploadedFile = new UploadedFile($tempPath, $fileName, 'application/pdf', null, true);

        // Check if an existing document is already attached to this invoice
        $existingAttachment = $invoice->documentAttachments()
            ->whereHas('document', fn ($q) => $q->where('company_id', $invoice->company_id))
            ->with('document')
            ->first();

        if ($existingAttachment && $existingAttachment->document) {
            $document = $existingAttachment->document;
            $this->documentService->addVersion(
                $user,
                $document,
                $uploadedFile,
                changeReason: 'Exported invoice copy for '.$invoice->name,
                ipAddress: request()->ip(),
            );
        } else {
            $docType = match ($invoice->move_type) {
                MoveType::IN_INVOICE => DocumentType::Bill,
                default              => DocumentType::Invoice,
            };

            $document = $this->documentService->upload(
                $user,
                $invoice->company_id,
                $docType,
                "Invoice {$invoice->name}",
                "Exported invoice copy for {$invoice->name}",
                $uploadedFile,
                request()->ip(),
            );

            $this->documentService->attach(
                $user,
                $document,
                $invoice,
                note: 'Generated invoice document',
                ipAddress: request()->ip(),
            );
        }

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }

        return $this->driveSyncService->export($document);
    }
}
