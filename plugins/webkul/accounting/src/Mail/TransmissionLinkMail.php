<?php

namespace Webkul\Accounting\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a claim link to a recipient who has no AureusERP.
 *
 * Carries BOTH a link and an attachment, deliberately: the attachment is
 * what most recipients actually want (they open it and file it), while the
 * link is what gives the sender delivery tracking and an expiry, and gives
 * the recipient the machine-readable version if their accountant wants it.
 *
 * The plaintext claim token appears only here, in the outgoing message --
 * only its hash is stored, so this mail cannot be regenerated later.
 */
class TransmissionLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $claimUrl  The tokenised link (plaintext, one copy only).
     * @param  string  $senderName  The company sending this.
     * @param  string  $subjectLine  What the recipient sees.
     * @param  string  $headline  Shown at the top of the body.
     * @param  ?string  $attachmentPath  Absolute path to a file to attach, if any.
     * @param  ?string  $attachmentName  Filename the recipient sees.
     * @param  ?string  $expiresAt  Human-readable expiry, shown in the body.
     */
    public function __construct(
        public string $claimUrl,
        public string $senderName,
        public string $subjectLine,
        public string $headline,
        public ?string $attachmentPath = null,
        public ?string $attachmentName = null,
        public ?string $expiresAt = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        // markdown, not view: the template uses <x-mail::message> components,
        // which only render through Laravel's markdown mail renderer.
        return new Content(markdown: 'accounting::mail.transmission-link');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->attachmentPath || ! is_readable($this->attachmentPath)) {
            return [];
        }

        return [
            Attachment::fromPath($this->attachmentPath)
                ->as($this->attachmentName ?: basename($this->attachmentPath)),
        ];
    }
}
