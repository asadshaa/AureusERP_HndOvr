<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Webkul\Recruitment\Jobs\SendRecruitmentMail;
use Webkul\Recruitment\Mail\ApplicationConfirmMail;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Queues recruitment emails (Stage 2.6): sent in the background from the
 * company's address (config recruitment.mail.sender), with replies going to
 * the recruiter, so a slow or failing mail server never blocks a save and
 * candidates see the company, not a personal mailbox.
 */
class RecruitmentMailer
{
    /**
     * @param  class-string  $mailClass
     * @param  array<string, mixed>  $payload  needs to.address, to.name and subject
     * @param  array<int, array<string, mixed>>  $attachments  each {path|data, name, mime}
     */
    public function queue(string $mailClass, string $view, array $payload, Company|int|null $company, ?User $sender = null, array $attachments = []): void
    {
        if (blank($payload['to']['address'] ?? null)) {
            throw new InvalidArgumentException('A recruitment email needs a recipient address.');
        }

        $company = $company instanceof Company ? $company : ($company ? Company::query()->find($company) : null);
        $sender ??= Auth::user() instanceof User ? Auth::user() : null;

        $payload['from'] = $this->from($company, $sender);

        if ($sender?->email) {
            $payload['reply_to'] = ['address' => $sender->email, 'name' => $sender->name];
        }

        SendRecruitmentMail::dispatch($mailClass, $view, $payload, $attachments)
            ->onQueue(config('recruitment.mail.queue'));
    }

    /**
     * "We received your application", queued once per application. Returns
     * false when it was already sent or there is no address or job.
     */
    public function confirmApplication(Applicant $applicant, ?User $sender = null): bool
    {
        $applicant->loadMissing(['candidate', 'job', 'company']);
        $address = $applicant->candidate?->email_from;

        if (blank($address) || ! $applicant->job || isset($applicant->applicant_properties['mail']['confirmation_queued_at'])) {
            return false;
        }

        $jobTitle = app(JobPublicProfile::class)->title($applicant->job);

        $this->queue(ApplicationConfirmMail::class, 'recruitments::mails.application-confirm', [
            'record_name'  => $applicant->candidate->name,
            'job_position' => $jobTitle,
            'subject'      => __('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.mail.application-confirm.subject', ['job_position' => $jobTitle]),
            'to'           => ['address' => $address, 'name' => $applicant->candidate->name],
        ], $applicant->company ?? $applicant->company_id, $sender);

        $properties = (array) ($applicant->applicant_properties ?? []);
        $properties['mail']['confirmation_queued_at'] = now()->toIso8601String();
        $applicant->forceFill(['applicant_properties' => $properties])->saveQuietly();

        return true;
    }

    /**
     * The sender shown to recipients, plus the company block the mail views
     * use for their footer.
     *
     * @return array{address: string, name: string, company?: array<string, mixed>}
     */
    public function from(?Company $company, ?User $sender = null): array
    {
        $mode = config('recruitment.mail.sender', 'company');
        $override = config('recruitment.mail.from_address');

        $address = match (true) {
            filled($override)                               => $override,
            $mode === 'user' && filled($sender?->email)     => $sender->email,
            $mode === 'company' && filled($company?->email) => $company->email,
            default                                         => config('mail.from.address'),
        };

        $name = $mode === 'user' && $sender ? $sender->name : ($company?->name ?? config('mail.from.name'));

        return array_filter([
            'address' => (string) $address,
            'name'    => (string) $name,
            'company' => $company?->only(['name', 'email', 'phone', 'website']),
        ], fn ($value): bool => $value !== null);
    }
}
