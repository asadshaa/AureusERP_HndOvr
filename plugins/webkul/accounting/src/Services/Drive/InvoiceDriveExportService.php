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
use Webkul\Accounting\Support\DriveFolderPathResolver;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class InvoiceDriveExportService
{
    public function __construct(
        protected DocumentService $documentService,
        protected DriveSyncService $driveSyncService,
        protected DriveFolderPathResolver $pathResolver,
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
            $view = match ($invoice->move_type) {
                MoveType::IN_INVOICE => 'accounts::bill/actions/preview.index',
                MoveType::IN_REFUND  => 'accounts::refund/actions/preview.index',
                default              => 'accounts::invoice/actions/preview.index',
            };

            if (! view()->exists($view)) {
                $view = 'accounts::invoice/actions/preview.index';
            }

            $html = view($view, ['record' => $invoice])->render();
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
                ipAddress: request()?->ip(),
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
                request()?->ip(),
            );

            $this->documentService->attach(
                $user,
                $document,
                $invoice,
                note: 'Generated invoice document',
                ipAddress: request()?->ip(),
            );
        }

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }

        return $this->driveSyncService->export($document);
    }

    /**
     * Exports a paid invoice or bill PDF to the company's "Paid Invoices" Google Drive folder.
     */
    public function exportPaidInvoice(?User $user, Move $invoice, ?string $pdfDiskPath = null): DocumentDriveSync
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
            $view = match ($invoice->move_type) {
                MoveType::IN_INVOICE => 'accounts::bill/actions/preview.index',
                MoveType::IN_REFUND  => 'accounts::refund/actions/preview.index',
                default              => 'accounts::invoice/actions/preview.index',
            };

            if (! view()->exists($view)) {
                $view = 'accounts::invoice/actions/preview.index';
            }

            $html = view($view, ['record' => $invoice])->render();
            $pdf = Pdf::loadHTML(mb_convert_encoding($html, 'UTF-8', 'UTF-8'))
                ->setPaper('A4', 'portrait')
                ->setOption('defaultFont', 'Arial')
                ->setOption('isHtml5ParserEnabled', true)
                ->setOption('isRemoteEnabled', true);
            $pdfContent = $pdf->output();
        }

        $sanitizedName = str_replace(['/', '\\'], '-', $invoice->name ?: 'bill-'.$invoice->id);
        $cleanName = Str::slug($sanitizedName);
        $fileName = "{$cleanName}-PAID.pdf";

        $tempPath = tempnam(sys_get_temp_dir(), 'inv_paid_');
        file_put_contents($tempPath, $pdfContent);
        $uploadedFile = new UploadedFile($tempPath, $fileName, 'application/pdf', null, true);

        $docType = match ($invoice->move_type) {
            MoveType::IN_INVOICE => DocumentType::Bill,
            default              => DocumentType::Invoice,
        };

        // Check if a paid document copy already exists for this move
        $existingAttachment = $invoice->documentAttachments()
            ->whereHas('document', fn ($q) => $q->where('company_id', $invoice->company_id)->where('title', 'like', 'Paid %'))
            ->with('document')
            ->first();

        if ($existingAttachment && $existingAttachment->document) {
            $document = $existingAttachment->document;
            $this->documentService->addVersion(
                $user,
                $document,
                $uploadedFile,
                changeReason: 'Exported paid copy for '.$invoice->name,
                ipAddress: request()?->ip(),
            );
        } else {
            $document = $this->documentService->upload(
                $user,
                $invoice->company_id,
                $docType,
                "Paid {$docType->getLabel()} {$invoice->name}",
                "Exported paid copy for {$invoice->name}",
                $uploadedFile,
                request()?->ip(),
            );

            $this->documentService->attach(
                $user,
                $document,
                $invoice,
                note: 'Paid document record',
                ipAddress: request()?->ip(),
            );
        }

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }

        $company = $invoice->company ?? Company::query()->find($invoice->company_id);
        $paidFolderSegments = $company ? $this->pathResolver->resolvePaidFolder($company) : null;

        return $this->driveSyncService->export($document, $paidFolderSegments);
    }
}
