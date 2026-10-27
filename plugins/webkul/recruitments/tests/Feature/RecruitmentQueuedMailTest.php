<?php

/**
 * Queued recruitment emails (roadmap Stage 2.6): sent in the background from
 * the company's address with replies to the recruiter, logged once, the
 * "application received" email sent once and automatically for website
 * applications, and the refusal email queued from the applicant page.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\ViewApplicant;
use Webkul\Recruitment\Jobs\SendRecruitmentMail;
use Webkul\Recruitment\Mail\ApplicantRefuseMail;
use Webkul\Recruitment\Mail\ApplicationConfirmMail;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\RefuseReason;
use Webkul\Recruitment\Services\RecruitmentMailer;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\EmailLog;

require_once __DIR__.'/Support/JobOpeningHelpers.php';

/** @return array{company: Company, job: JobPosition, recruiter: User} */
function mailFixture(): array
{
    Auth::login(User::factory()->create(['is_active' => true]));
    $company = Company::factory()->create(['is_active' => true, 'email' => 'careers@'.Str::lower(Str::random(6)).'.example', 'website' => 'https://example.test']);
    $job = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Fleet Dispatcher', 'public_title' => 'Fleet Dispatcher (Karachi)', 'is_active' => true]);
    $recruiter = jobOpeningRoleUser($company, 'recruiter');

    return compact('company', 'job', 'recruiter');
}

function mailApplicant(JobPosition $job, array $properties = [], ?string $email = 'ali@example.test'): Applicant
{
    $partner = Partner::query()->create(['name' => 'Ali Raza', 'sub_type' => 'individual', 'company_id' => $job->company_id]);
    $candidate = Candidate::query()->create(['company_id' => $job->company_id, 'name' => 'Ali Raza', 'email_from' => $email, 'partner_id' => $partner->id]);

    return Applicant::query()->create([
        'company_id'  => $job->company_id, 'candidate_id' => $candidate->id, 'job_id' => $job->id,
        'create_date' => now(), 'applicant_properties' => $properties,
    ]);
}

beforeEach(fn () => Queue::fake());

it('queues recruitment emails from the company address with replies to the recruiter', function () {
    ['company' => $company, 'recruiter' => $recruiter] = mailFixture();

    app(RecruitmentMailer::class)->queue(ApplicationConfirmMail::class, 'recruitments::mails.application-confirm', [
        'subject' => 'Hello', 'record_name' => 'Ali', 'to' => ['address' => 'ali@example.test', 'name' => 'Ali'],
    ], $company, $recruiter);

    Queue::assertPushed(SendRecruitmentMail::class, function (SendRecruitmentMail $job) use ($company, $recruiter): bool {
        return $job->mailClass === ApplicationConfirmMail::class
            && $job->payload['from']['address'] === $company->email
            && $job->payload['from']['name'] === $company->name
            && $job->payload['from']['company']['website'] === 'https://example.test'
            && $job->payload['reply_to'] === ['address' => $recruiter->email, 'name' => $recruiter->name];
    });

    expect(fn () => app(RecruitmentMailer::class)->queue(ApplicationConfirmMail::class, 'x', ['subject' => 'x', 'to' => ['address' => '']], $company))
        ->toThrow(InvalidArgumentException::class);
});

it('can send from the app address or the recruiter instead, and falls back when the company has no email', function () {
    ['company' => $company, 'recruiter' => $recruiter] = mailFixture();
    $mailer = app(RecruitmentMailer::class);

    config(['recruitment.mail.sender' => 'app']);
    expect($mailer->from($company, $recruiter)['address'])->toBe(config('mail.from.address'));

    config(['recruitment.mail.sender' => 'user']);
    expect($mailer->from($company, $recruiter))->toMatchArray(['address' => $recruiter->email, 'name' => $recruiter->name]);

    config(['recruitment.mail.sender' => 'company', 'recruitment.mail.from_address' => 'jobs@group.example']);
    expect($mailer->from($company, $recruiter)['address'])->toBe('jobs@group.example');

    config(['recruitment.mail.from_address' => null]);
    $company->forceFill(['email' => null])->saveQuietly();
    expect($mailer->from($company->refresh(), $recruiter)['address'])->toBe(config('mail.from.address'));
});

it('sends the queued email with its sender and reply-to and logs it once', function () {
    ['company' => $company, 'recruiter' => $recruiter] = mailFixture();
    Mail::fake();

    $payload = [
        'subject'  => 'Your application', 'record_name' => 'Ali', 'job_position' => 'Fleet Dispatcher',
        'to'       => ['address' => 'ali@example.test', 'name' => 'Ali'],
        'from'     => app(RecruitmentMailer::class)->from($company, $recruiter),
        'reply_to' => ['address' => $recruiter->email, 'name' => $recruiter->name],
    ];

    (new SendRecruitmentMail(ApplicationConfirmMail::class, 'recruitments::mails.application-confirm', $payload))->handle();

    Mail::assertSent(ApplicationConfirmMail::class, fn (ApplicationConfirmMail $mail): bool => $mail->hasTo('ali@example.test')
        && $mail->hasFrom($company->email)
        && $mail->hasReplyTo($recruiter->email)
        && $mail->hasSubject('Your application'));

    expect(EmailLog::query()->where('recipient_email', 'ali@example.test')->where('subject', 'Your application')->pluck('status')->all())->toBe(['sent']);

    (new SendRecruitmentMail(ApplicationConfirmMail::class, 'x', $payload))->failed(new RuntimeException('SMTP down'));

    expect(EmailLog::query()->where('recipient_email', 'ali@example.test')->where('status', 'failed')->value('error_message'))->toBe('SMTP down');
});

it('queues the application-received email once per application', function () {
    ['job' => $job] = mailFixture();
    $applicant = mailApplicant($job);
    $mailer = app(RecruitmentMailer::class);

    expect($mailer->confirmApplication($applicant))->toBeTrue()
        ->and($mailer->confirmApplication($applicant->refresh()))->toBeFalse()
        ->and($mailer->confirmApplication(mailApplicant($job, [], null)))->toBeFalse();

    Queue::assertPushed(SendRecruitmentMail::class, fn (SendRecruitmentMail $mail): bool => $mail->payload['to']['address'] === 'ali@example.test'
        && $mail->payload['job_position'] === 'Fleet Dispatcher (Karachi)');
    Queue::assertPushed(SendRecruitmentMail::class, 1);
});

it('confirms website applications automatically, but not bulk imports', function () {
    ['job' => $job] = mailFixture();

    mailApplicant($job, ['source_provenance' => ['adapter' => 'import']]);
    Queue::assertNotPushed(SendRecruitmentMail::class);

    $website = mailApplicant($job, ['source_provenance' => ['adapter' => 'company_website']]);
    Queue::assertPushed(SendRecruitmentMail::class, 1);

    expect($website->refresh()->applicant_properties['mail']['confirmation_queued_at'] ?? null)->not->toBeNull();
});

it('queues the refusal email from the applicant page instead of sending it during the request', function () {
    ['company' => $company, 'job' => $job, 'recruiter' => $recruiter] = mailFixture();
    $applicant = mailApplicant($job);
    $reason = RefuseReason::query()->where('template', 'applicant-refuse')->firstOrFail();
    Mail::fake();
    Auth::login($recruiter);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(ViewApplicant::class, ['record' => $applicant->getRouteKey()])
        ->callAction('Refuse', ['refuse_reason_id' => $reason->id, 'notify' => true, 'email' => 'ali@example.test'])
        ->assertHasNoActionErrors();

    Mail::assertNothingSent();
    Queue::assertPushed(SendRecruitmentMail::class, fn (SendRecruitmentMail $mail): bool => $mail->mailClass === ApplicantRefuseMail::class
        && $mail->view === 'recruitments::mails.applicant-refuse'
        && $mail->payload['from']['address'] === $company->email);

    expect($applicant->refresh()->refuse_reason_id)->toBe($reason->id);
});
