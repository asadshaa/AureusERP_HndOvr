<?php

namespace Webkul\Recruitment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Support\Models\EmailLog;

/**
 * Sends one recruitment email in the background (Stage 2.6) and writes the
 * outcome to the email log once: "sent", or "failed" after the last retry.
 */
class SendRecruitmentMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> seconds before the 2nd and 3rd attempt */
    public array $backoff = [60, 300];

    /**
     * @param  class-string  $mailClass
     * @param  array<string, mixed>  $payload  needs to.address, to.name, subject, from
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public function __construct(
        public string $mailClass,
        public string $view,
        public array $payload,
        public array $attachments = [],
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $mailable = (new $this->mailClass($this->view, $this->payload))->withAttachments($this->attachments);

        Mail::to($this->payload['to']['address'], $this->payload['to']['name'] ?? null)->send($mailable);

        $this->log('sent');
    }

    public function failed(?Throwable $exception): void
    {
        $this->log('failed', $exception ? Str::limit($exception->getMessage(), 1000, '') : null);
    }

    protected function log(string $status, ?string $error = null): void
    {
        EmailLog::query()->create([
            'recipient_email' => $this->payload['to']['address'],
            'recipient_name'  => (string) ($this->payload['to']['name'] ?? ''),
            'subject'         => (string) ($this->payload['subject'] ?? ''),
            'status'          => $status,
            'error_message'   => $error,
            'sent_at'         => now(),
        ]);
    }
}
