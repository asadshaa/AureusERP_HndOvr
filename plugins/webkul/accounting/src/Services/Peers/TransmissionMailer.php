<?php

namespace Webkul\Accounting\Services\Peers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Mail\TransmissionLinkMail;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\OutboundTransmission;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Support\Traits\PDFHandler;

/**
 * Emails a claim link, with the thing itself attached.
 *
 * Kept out of DocumentExchangeService on purpose: that service owns the
 * transmission record and must not fail because an SMTP server is down. A
 * failure here is logged and swallowed, because the link has already been
 * created and is still usable -- losing the email is an inconvenience,
 * losing the transmission would be a bug.
 */
class TransmissionMailer
{
    use PDFHandler;

    public function __construct(
        private readonly DocumentService $documents,
    ) {}

    /**
     * @return bool Whether the message was handed to the mailer.
     */
    public function sendInvoiceLink(OutboundTransmission $transmission, Move $invoice, string $claimUrl): bool
    {
        $attachment = $this->renderInvoicePdf($invoice);

        return $this->dispatch(
            $transmission,
            $claimUrl,
            subject: "Invoice {$invoice->name} from ".($invoice->company?->name ?? config('app.name')),
            headline: "Invoice {$invoice->name}",
            senderName: $invoice->company?->name ?? config('app.name'),
            attachmentPath: $attachment['path'] ?? null,
            attachmentName: $attachment['name'] ?? null,
        );
    }

    public function sendDocumentLink(OutboundTransmission $transmission, Document $document, string $claimUrl): bool
    {
        $attachment = $this->materialiseDocument($document);

        return $this->dispatch(
            $transmission,
            $claimUrl,
            subject: $document->title.' from '.($document->company?->name ?? config('app.name')),
            headline: $document->title,
            senderName: $document->company?->name ?? config('app.name'),
            attachmentPath: $attachment['path'] ?? null,
            attachmentName: $attachment['name'] ?? null,
        );
    }

    private function dispatch(
        OutboundTransmission $transmission,
        string $claimUrl,
        string $subject,
        string $headline,
        string $senderName,
        ?string $attachmentPath,
        ?string $attachmentName,
    ): bool {
        if (! $transmission->recipient_email) {
            return false;
        }

        try {
            Mail::to($transmission->recipient_email)->send(new TransmissionLinkMail(
                claimUrl: $claimUrl,
                senderName: $senderName,
                subjectLine: $subject,
                headline: $headline,
                attachmentPath: $attachmentPath,
                attachmentName: $attachmentName,
                expiresAt: $transmission->expires_at?->toDayDateTimeString(),
            ));

            return true;
        } catch (Throwable $e) {
            // Deliberately non-fatal: the claim link already exists and still
            // works, so a dead SMTP server must not undo the transmission.
            Log::warning('Could not email a transmission claim link.', [
                'transmission_id' => $transmission->id,
                'recipient'       => $transmission->recipient_email,
                'error'           => $e->getMessage(),
            ]);

            return false;
        } finally {
            // Temp copies only -- never the stored original.
            if ($attachmentPath && str_starts_with($attachmentPath, sys_get_temp_dir())) {
                @unlink($attachmentPath);
            }
        }
    }

    /**
     * @return array{path: ?string, name: ?string}
     */
    private function renderInvoicePdf(Move $invoice): array
    {
        $template = 'accounts::invoice/actions/preview.index';

        if (! view()->exists($template)) {
            return ['path' => null, 'name' => null];
        }

        try {
            $pdf = $this->generatePDF(view($template, ['record' => $invoice])->render());

            // tempnam() atomically creates AND names the file it returns.
            // Appending '.pdf' to that string abandons the file it just
            // created -- confirmed: the real tempnam() path is left behind
            // on disk every time, and the cleanup in dispatch()'s finally
            // block only ever unlinks the '.pdf'-suffixed path, so it never
            // catches it. The recipient never sees this filename anyway --
            // Attachment::as() below sets the name they see, and Symfony
            // sniffs the MIME type from content, not the extension.
            $path = tempnam(sys_get_temp_dir(), 'aureus-inv-');

            if ($path === false) {
                return ['path' => null, 'name' => null];
            }

            file_put_contents($path, $pdf->output());

            return [
                'path' => $path,
                'name' => 'invoice-'.Str::slug((string) $invoice->name).'.pdf',
            ];
        } catch (Throwable $e) {
            // An unrenderable invoice should still get its link emailed.
            Log::warning('Could not render an invoice PDF for emailing.', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return ['path' => null, 'name' => null];
        }
    }

    /**
     * @return array{path: ?string, name: ?string}
     */
    private function materialiseDocument(Document $document): array
    {
        try {
            $read = $this->documents->readCurrentVersionForSync($document);

            $path = tempnam(sys_get_temp_dir(), 'aureus-doc-');

            if ($path === false) {
                return ['path' => null, 'name' => null];
            }

            file_put_contents($path, $read['contents']);

            return ['path' => $path, 'name' => $read['version']->original_filename];
        } catch (Throwable $e) {
            Log::warning('Could not attach a document to its claim email.', [
                'document_id' => $document->id,
                'error'       => $e->getMessage(),
            ]);

            return ['path' => null, 'name' => null];
        }
    }
}
