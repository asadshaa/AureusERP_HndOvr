<?php

/**
 * Interview scheduling, invitations and feedback (roadmap Stage 2.3):
 * scheduling checks company, rights and timing; the candidate and the
 * interviewers get queued emails with an .ics calendar file (REQUEST, then
 * a higher SEQUENCE on reschedule, CANCEL on cancellation); feedback is
 * taken from assigned interviewers once the interview started and turns
 * into the application's interview score; reminders and feedback requests
 * go out once; the Interviews list only shows an interviewer their own
 * interviews and never another company's.
 */

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Enums\InterviewStatus;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\ViewApplicant;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\RelationManagers\InterviewsRelationManager;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\InterviewResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\InterviewResource\Pages\ListInterviews;
use Webkul\Recruitment\Jobs\SendRecruitmentMail;
use Webkul\Recruitment\Mail\InterviewMail;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\Interview;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\RefuseReason;
use Webkul\Recruitment\Policies\InterviewPolicy;
use Webkul\Recruitment\Services\InterviewService;
use Webkul\Recruitment\Support\IcsCalendar;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

require_once __DIR__.'/Support/JobOpeningHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

/** @return array{company: Company, job: JobPosition, hr: User, interviewer: User, second: User, applicant: Applicant} */
function interviewFixture(): array
{
    // Fresh acting user: a previous test's rolled-back user must not be stamped as creator_id.
    Auth::login(User::factory()->create(['is_active' => true]));

    $company = Company::factory()->create(['is_active' => true, 'email' => 'careers@'.Str::lower(Str::random(6)).'.example', 'website' => 'https://example.test']);
    $job = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Fleet Dispatcher', 'public_title' => 'Fleet Dispatcher (Karachi)', 'is_active' => true]);
    $hr = jobOpeningRoleUser($company, 'recruiter');
    $interviewer = jobOpeningUser($company);
    $second = jobOpeningUser($company);

    return [
        'company'     => $company,
        'job'         => $job,
        'hr'          => $hr,
        'interviewer' => $interviewer,
        'second'      => $second,
        'applicant'   => interviewApplicant($job, 'Ali Raza', 'ali@example.test'),
    ];
}

function interviewApplicant(JobPosition $job, string $name, ?string $email): Applicant
{
    $partner = Partner::query()->create(['name' => $name, 'sub_type' => 'individual', 'company_id' => $job->company_id]);
    $candidate = Candidate::query()->create(['company_id' => $job->company_id, 'name' => $name, 'email_from' => $email, 'partner_id' => $partner->id]);

    return Applicant::query()->create(['company_id' => $job->company_id, 'candidate_id' => $candidate->id, 'job_id' => $job->id, 'create_date' => now()]);
}

/** @return array<string, mixed> */
function interviewData(array $userIds, array $overrides = []): array
{
    return array_merge([
        'starts_at'        => now()->addDays(3)->setTime(10, 0)->toDateTimeString(),
        'duration_minutes' => 60,
        'mode'             => 'video',
        'location'         => 'https://meet.example.test/abc-defg',
        'interviewer_ids'  => $userIds,
        'notify_candidate' => true,
    ], $overrides);
}

/** @return Collection<int, SendRecruitmentMail> */
function interviewMails(): Collection
{
    return Queue::pushed(SendRecruitmentMail::class, fn (SendRecruitmentMail $job): bool => $job->mailClass === InterviewMail::class)->values();
}

function interviewMailTo(string $address, ?string $kind = null): ?SendRecruitmentMail
{
    return interviewMails()->first(fn (SendRecruitmentMail $job): bool => $job->payload['to']['address'] === $address
        && ($kind === null || $job->payload['kind'] === $kind));
}

/** Renders every queued interview email (all kinds and audiences must render). */
function interviewRenderAll(): void
{
    foreach (interviewMails() as $job) {
        expect((new InterviewMail($job->view, $job->payload))->render())->toContain(e($job->payload['to']['name']));
    }
}

/** The attached calendar text, unfolded (RFC 5545 3.1) so long lines can be asserted on. */
function interviewIcs(SendRecruitmentMail $job): string
{
    return str_replace("\r\n ", '', (string) ($job->attachments[0]['data'] ?? ''));
}

// ---------------------------------------------------------------------
// Scheduling and invitations
// ---------------------------------------------------------------------

it('schedules an interview, adds the interviewers to the application and queues invitations with a calendar file', function () {
    $f = interviewFixture();

    $result = app(InterviewService::class)->schedule($f['applicant'], interviewData([$f['interviewer']->id, $f['second']->id]), $f['hr']);
    $interview = $result->interview->refresh();

    expect($interview->status)->toBe(InterviewStatus::SCHEDULED)
        ->and($interview->company_id)->toBe($f['company']->id)
        ->and($interview->job_id)->toBe($f['job']->id)
        ->and($interview->organizer_id)->toBe($f['hr']->id)
        ->and($interview->sequence)->toBe(0)
        ->and($interview->title)->toBe('Interview: Fleet Dispatcher')
        ->and($interview->ends_at->diffInMinutes($interview->starts_at, true))->toEqual(60)
        ->and($interview->candidate_notified_at)->not->toBeNull()
        ->and($interview->reminded_at)->toBeNull()
        ->and($interview->assignments()->pluck('user_id')->sort()->values()->all())->toBe(collect([$f['interviewer']->id, $f['second']->id])->sort()->values()->all())
        ->and($result->hasOverlaps())->toBeFalse();

    // The application's own interviewer list now includes them.
    expect($f['applicant']->interviewer()->pluck('users.id')->all())->toContain($f['interviewer']->id, $f['second']->id);

    expect(interviewMails())->toHaveCount(3);

    $candidateMail = interviewMailTo('ali@example.test', 'invitation');
    expect($candidateMail)->not->toBeNull()
        ->and($candidateMail->payload['audience'])->toBe('candidate')
        ->and($candidateMail->payload['subject'])->toContain('Fleet Dispatcher (Karachi)')
        ->and($candidateMail->payload['from']['address'])->toBe($f['company']->email)
        ->and($candidateMail->payload['reply_to']['address'])->toBe($f['hr']->email)
        ->and($candidateMail->attachments[0]['name'])->toBe('invite.ics')
        ->and($candidateMail->attachments[0]['mime'])->toBe('text/calendar; charset=UTF-8; method=REQUEST');

    $ics = interviewIcs($candidateMail);
    expect($ics)->toContain("BEGIN:VCALENDAR\r\n")
        ->toContain("METHOD:REQUEST\r\n")
        ->toContain("SEQUENCE:0\r\n")
        ->toContain('UID:recruitment-interview-'.$interview->id.'@')
        ->toContain('DTSTART:'.$interview->starts_at->copy()->utc()->format('Ymd\THis\Z'))
        ->toContain('DTEND:'.$interview->ends_at->copy()->utc()->format('Ymd\THis\Z'))
        ->toContain('mailto:ali@example.test')
        ->toContain('ORGANIZER;CN="'.$f['hr']->name.'":mailto:'.$f['hr']->email)
        ->not->toContain('mailto:'.$f['interviewer']->email);

    // Interviewers: the panel as attendees, and a link they can open (their Interviews list, as they cannot open applications).
    $interviewerMail = interviewMailTo($f['interviewer']->email, 'invitation');
    expect($interviewerMail->payload['audience'])->toBe('interviewer')
        ->and(interviewIcs($interviewerMail))->toContain('mailto:'.$f['second']->email)->toContain('METHOD:REQUEST')
        ->and($interviewerMail->payload['action_url'])->toBe(InterviewResource::getUrl('index', panel: 'admin'));

    // The emails render, and the calendar file goes out as text/calendar with its method.
    $html = (new InterviewMail($candidateMail->view, $candidateMail->payload))->render();
    expect($html)->toContain('Ali Raza')->toContain('https://meet.example.test/abc-defg')->not->toContain('Internal:');
    expect((new InterviewMail($interviewerMail->view, $interviewerMail->payload))->render())->toContain('Internal:');

    $candidateMail->handle();
    $sent = collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage();
    $calendarPart = collect($sent->getAttachments())->first();
    expect($calendarPart->getFilename())->toBe('invite.ics')
        ->and($calendarPart->getPreparedHeaders()->get('Content-Type')->getBodyAsString())->toContain('text/calendar')->toContain('method=REQUEST')
        ->and($calendarPart->getBody())->toContain('BEGIN:VCALENDAR');

    expect($f['applicant']->messages()->where('body', 'like', '%Interview scheduled%')->exists())->toBeTrue();
});

it('refuses interviewers of another company, past times, users without rights and another active company', function () {
    $f = interviewFixture();
    $service = app(InterviewService::class);
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $outsider = jobOpeningUser($otherCompany);

    expect(fn () => $service->schedule($f['applicant'], interviewData([$f['interviewer']->id, $outsider->id]), $f['hr']))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->schedule($f['applicant'], interviewData([$f['interviewer']->id], ['starts_at' => now()->subHour()->toDateTimeString()]), $f['hr']))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->schedule($f['applicant'], interviewData([$f['interviewer']->id], ['mode' => 'video', 'location' => 'not a link']), $f['hr']))
        ->toThrow(ValidationException::class);

    // A plain interviewer cannot schedule.
    expect(fn () => $service->schedule($f['applicant'], interviewData([$f['second']->id]), $f['interviewer']))
        ->toThrow(AuthorizationException::class);

    // HR working in another of their companies must switch first.
    $f['hr']->allowedCompanies()->syncWithoutDetaching([$otherCompany->id]);
    CurrentCompany::flush($f['hr']);
    CurrentCompany::switchTo($otherCompany->id, $f['hr']);

    expect(fn () => $service->schedule($f['applicant'], interviewData([$f['interviewer']->id]), $f['hr']->refresh()))
        ->toThrow(AuthorizationException::class, 'Switch to');

    expect(Interview::query()->where('applicant_id', $f['applicant']->id)->exists())->toBeFalse()
        ->and(interviewMails())->toHaveCount(0);
});

it('warns about an interviewer\'s overlapping interview but still schedules it', function () {
    $f = interviewFixture();
    $service = app(InterviewService::class);
    $start = now()->addDays(2)->setTime(10, 0);

    $service->schedule($f['applicant'], interviewData([$f['interviewer']->id], ['starts_at' => $start->toDateTimeString()]), $f['hr']);

    $other = interviewApplicant($f['job'], 'Sana Khan', 'sana@example.test');
    $result = $service->schedule($other, interviewData([$f['interviewer']->id, $f['second']->id], ['starts_at' => $start->copy()->addMinutes(30)->toDateTimeString()]), $f['hr']);

    expect($result->interview->exists)->toBeTrue()
        ->and($result->overlaps)->toHaveCount(1)
        ->and($result->overlaps[0]['user_id'])->toBe($f['interviewer']->id)
        ->and($result->overlapMessage())->toContain($f['interviewer']->name);

    // Back to back is not an overlap.
    $third = interviewApplicant($f['job'], 'Omar Ali', 'omar@example.test');
    expect($service->schedule($third, interviewData([$f['interviewer']->id], ['starts_at' => $start->copy()->addMinutes(150)->toDateTimeString()]), $f['hr'])->hasOverlaps())->toBeFalse();
});

it('reschedules with a higher calendar sequence, updates everyone, invites added and releases removed interviewers', function () {
    $f = interviewFixture();
    $service = app(InterviewService::class);
    $third = jobOpeningUser($f['company']);
    $interview = $service->schedule($f['applicant'], interviewData([$f['interviewer']->id, $f['second']->id]), $f['hr'])->interview;
    Queue::fake();

    $newStart = now()->addDays(4)->setTime(15, 30);
    $service->reschedule($interview, interviewData([$f['interviewer']->id, $third->id], [
        'starts_at' => $newStart->toDateTimeString(), 'duration_minutes' => 45, 'mode' => 'in_person', 'location' => 'Head office, 2nd floor',
    ]), $f['hr']);

    $interview->refresh();
    expect($interview->sequence)->toBe(1)
        ->and($interview->starts_at->equalTo($newStart))->toBeTrue()
        ->and($interview->duration_minutes)->toBe(45)
        ->and($interview->assignments()->pluck('user_id')->sort()->values()->all())->toBe(collect([$f['interviewer']->id, $third->id])->sort()->values()->all());

    $candidate = interviewMailTo('ali@example.test', 'rescheduled');
    expect(interviewIcs($candidate))->toContain('SEQUENCE:1')->toContain('METHOD:REQUEST')->toContain('LOCATION:Head office\, 2nd floor');
    expect(interviewIcs(interviewMailTo($f['interviewer']->email, 'rescheduled')))->toContain('SEQUENCE:1');
    expect(interviewIcs(interviewMailTo($third->email, 'invitation')))->toContain('METHOD:REQUEST');
    expect(interviewIcs(interviewMailTo($f['second']->email, 'removed')))->toContain('METHOD:CANCEL')->toContain('STATUS:CANCELLED');
    expect(interviewMails())->toHaveCount(4);
    interviewRenderAll();

    // The application keeps every interviewer it ever had.
    expect($f['applicant']->interviewer()->pluck('users.id')->all())->toContain($f['second']->id, $third->id);

    expect(fn () => $service->reschedule($interview->refresh(), interviewData([$f['interviewer']->id, $third->id], [
        'starts_at' => $newStart->toDateTimeString(), 'duration_minutes' => 45, 'mode' => 'in_person', 'location' => 'Head office, 2nd floor',
    ]), $f['hr']))->toThrow(RuntimeException::class, 'Nothing changed');
});

it('cancels with a reason and sends METHOD:CANCEL, to the candidate only when they were invited', function () {
    $f = interviewFixture();
    $service = app(InterviewService::class);
    $interview = $service->schedule($f['applicant'], interviewData([$f['interviewer']->id]), $f['hr'])->interview;
    Queue::fake();

    expect(fn () => $service->cancel($interview, '  ', $f['hr']))->toThrow(ValidationException::class);

    $service->cancel($interview, 'Position put on hold', $f['hr']);
    $interview->refresh();

    expect($interview->status)->toBe(InterviewStatus::CANCELLED)
        ->and($interview->sequence)->toBe(1)
        ->and($interview->cancel_reason)->toBe('Position put on hold');

    $toInterviewer = interviewMailTo($f['interviewer']->email, 'cancelled');
    $toCandidate = interviewMailTo('ali@example.test', 'cancelled');
    expect(interviewIcs($toInterviewer))->toContain('METHOD:CANCEL')->toContain('STATUS:CANCELLED')->toContain('SEQUENCE:1')
        ->and($toInterviewer->attachments[0]['mime'])->toBe('text/calendar; charset=UTF-8; method=CANCEL')
        ->and($toInterviewer->payload['cancel_reason'])->toBe('Position put on hold')
        ->and(interviewIcs($toCandidate))->toContain('METHOD:CANCEL')
        ->and($toCandidate->payload['cancel_reason'])->toBeNull();
    interviewRenderAll();

    expect(fn () => $service->cancel($interview, 'Again', $f['hr']))->toThrow(RuntimeException::class);

    // Not invited (toggle off) => no cancellation email to the candidate either.
    $quiet = $service->schedule($f['applicant'], interviewData([$f['interviewer']->id], ['notify_candidate' => false]), $f['hr'])->interview;
    expect($quiet->candidate_notified_at)->toBeNull()
        ->and(interviewMailTo('ali@example.test', 'invitation'))->toBeNull();

    Queue::fake();
    $service->cancel($quiet, 'Duplicate', $f['hr']);
    expect(interviewMailTo('ali@example.test'))->toBeNull()
        ->and(interviewMailTo($f['interviewer']->email, 'cancelled'))->not->toBeNull();
});

// ---------------------------------------------------------------------
// Feedback and score
// ---------------------------------------------------------------------

it('takes feedback only from assigned interviewers once the interview started, and scores the application', function () {
    $f = interviewFixture();
    $service = app(InterviewService::class);
    $outsider = jobOpeningUser($f['company']);
    $interview = $service->schedule($f['applicant'], interviewData([$f['interviewer']->id, $f['second']->id]), $f['hr'])->interview;
    $feedback = ['rating' => 4, 'recommendation' => 'yes', 'feedback' => 'Knows the routes well.'];

    expect(fn () => $service->submitFeedback($interview, $f['interviewer'], $feedback))->toThrow(RuntimeException::class, 'once the interview has started');

    $this->travelTo($interview->starts_at->copy()->addMinutes(10));

    expect(fn () => $service->submitFeedback($interview, $outsider, $feedback))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->submitFeedback($interview, $f['interviewer'], $feedback, $f['second']))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->submitFeedback($interview, $f['interviewer'], ['rating' => 6, 'recommendation' => 'yes']))->toThrow(ValidationException::class);

    $service->submitFeedback($interview, $f['interviewer'], $feedback);
    expect($f['applicant']->refresh()->interview_score)->toEqual(80.0);

    // Others' scorecards stay hidden from an interviewer until they gave their own.
    expect($service->visibleFeedback($f['second'], $interview->refresh())->pluck('user_id')->all())->toBe([$f['second']->id])
        ->and($service->visibleFeedback($f['interviewer'], $interview)->count())->toBe(2);

    $service->submitFeedback($interview->refresh(), $f['second'], ['rating' => 5, 'recommendation' => 'strong_yes']);
    expect($f['applicant']->refresh()->interview_score)->toEqual(90.0);

    // HR may record (correct) an interviewer's scorecard.
    $service->submitFeedback($interview->refresh(), $f['hr'], ['rating' => 3, 'recommendation' => 'no'], $f['second']);
    expect($f['applicant']->refresh()->interview_score)->toEqual(70.0)
        ->and($interview->assignmentFor($f['second']->id)?->refresh()->rating)->toBe(3);

    // A cancelled interview takes no feedback.
    $later = $service->schedule($f['applicant'], interviewData([$f['interviewer']->id], ['starts_at' => now()->addDay()->toDateTimeString()]), $f['hr'])->interview;
    $service->cancel($later, 'Not needed', $f['hr']);
    expect(fn () => $service->submitFeedback($later->refresh(), $f['interviewer'], $feedback))->toThrow(RuntimeException::class);

    expect($f['applicant']->messages()->where('body', 'like', '%Interview feedback given%')->count())->toBe(2)
        ->and($f['applicant']->messages()->where('body', 'like', '%Recorded by%')->exists())->toBeTrue();
});

it('marks an interview completed or no-show only after it started, and no-show never after feedback', function () {
    $f = interviewFixture();
    $service = app(InterviewService::class);
    $first = $service->schedule($f['applicant'], interviewData([$f['interviewer']->id]), $f['hr'])->interview;
    $second = $service->schedule($f['applicant'], interviewData([$f['second']->id], ['starts_at' => now()->addDays(3)->setTime(14, 0)->toDateTimeString()]), $f['hr'])->interview;

    expect(fn () => $service->markCompleted($first, $f['hr']))->toThrow(RuntimeException::class, 'not started')
        ->and(fn () => $service->markNoShow($first, $f['hr']))->toThrow(RuntimeException::class, 'not started');

    $this->travelTo(now()->addDays(3)->setTime(16, 0));

    expect(fn () => $service->markCompleted($first, $f['interviewer']))->toThrow(AuthorizationException::class);

    $service->markNoShow($first, $f['hr']);
    expect($first->refresh()->status)->toBe(InterviewStatus::NO_SHOW);

    $service->submitFeedback($second, $f['second'], ['rating' => 2, 'recommendation' => 'no']);
    expect(fn () => $service->markNoShow($second->refresh(), $f['hr']))->toThrow(RuntimeException::class, 'Feedback has already been given')
        ->and(fn () => $service->cancel($second->refresh(), 'Too late', $f['hr']))->toThrow(RuntimeException::class);

    $service->markCompleted($second->refresh(), $f['hr']);
    expect($second->refresh()->status)->toBe(InterviewStatus::COMPLETED);
});

// ---------------------------------------------------------------------
// Reminders and feedback requests
// ---------------------------------------------------------------------

it('sends the 24h reminder and the feedback requests once, however often the command runs', function () {
    $f = interviewFixture();
    $service = app(InterviewService::class);
    $start = now()->addHours(30)->startOfHour();
    $interview = $service->schedule($f['applicant'], interviewData([$f['interviewer']->id, $f['second']->id], ['starts_at' => $start->toDateTimeString()]), $f['hr'])->interview;
    Queue::fake();

    $this->artisan('recruitments:interview-reminders')->assertSuccessful();
    expect(interviewMails())->toHaveCount(0);

    $this->travelTo($start->copy()->subHours(23));
    $this->artisan('recruitments:interview-reminders')->assertSuccessful();
    $this->artisan('recruitments:interview-reminders')->assertSuccessful();

    $reminders = interviewMails()->filter(fn (SendRecruitmentMail $job): bool => $job->payload['kind'] === 'reminder');
    expect($reminders)->toHaveCount(3)
        ->and($reminders->pluck('payload.to.address')->all())->toContain('ali@example.test', $f['interviewer']->email, $f['second']->email)
        ->and($interview->refresh()->reminded_at)->not->toBeNull()
        ->and($reminders->every(fn (SendRecruitmentMail $job): bool => $job->attachments === []))->toBeTrue();
    interviewRenderAll();

    // After the interview: one feedback request per interviewer still owing feedback.
    $this->travelTo($interview->ends_at->copy()->addMinutes(5));
    $service->submitFeedback($interview, $f['second'], ['rating' => 4, 'recommendation' => 'yes']);
    Queue::fake();

    $this->artisan('recruitments:interview-reminders')->assertSuccessful();
    $this->artisan('recruitments:interview-reminders')->assertSuccessful();

    $requests = interviewMails()->filter(fn (SendRecruitmentMail $job): bool => $job->payload['kind'] === 'feedback_request');
    expect($requests)->toHaveCount(1)
        ->and($requests->first()->payload['to']['address'])->toBe($f['interviewer']->email)
        ->and($requests->first()->payload['action_url'])->toBe(InterviewResource::getUrl('index', panel: 'admin'))
        ->and($interview->assignmentFor($f['interviewer']->id)?->refresh()->feedback_requested_at)->not->toBeNull()
        ->and(DB::table('notifications')->where('notifiable_id', $f['interviewer']->id)->where('data', 'like', '%recruitment_interview_feedback_%')->count())->toBe(1);
    interviewRenderAll();
});

it('does not send a separate reminder for an interview scheduled inside the reminder window', function () {
    $f = interviewFixture();
    $interview = app(InterviewService::class)->schedule($f['applicant'], interviewData([$f['interviewer']->id], ['starts_at' => now()->addHours(5)->toDateTimeString()]), $f['hr'])->interview;
    Queue::fake();

    expect($interview->reminded_at)->not->toBeNull();

    $this->artisan('recruitments:interview-reminders')->assertSuccessful();
    expect(interviewMails())->toHaveCount(0);

    // Nor for an application refused after its interview was scheduled.
    $later = app(InterviewService::class)->schedule($f['applicant'], interviewData([$f['interviewer']->id], ['starts_at' => now()->addHours(30)->toDateTimeString()]), $f['hr'])->interview;
    $f['applicant']->forceFill(['refuse_reason_id' => RefuseReason::query()->value('id')])->saveQuietly();
    Queue::fake();
    $this->travelTo($later->starts_at->copy()->subHours(2));

    $this->artisan('recruitments:interview-reminders')->assertSuccessful();
    expect(interviewMails()->where('payload.kind', 'reminder'))->toHaveCount(0)
        ->and($later->refresh()->reminded_at)->toBeNull();
});

// ---------------------------------------------------------------------
// Interviews list, permissions and the application tab
// ---------------------------------------------------------------------

it('shows an interviewer only their own interviews of the active company, and HR all of that company\'s', function () {
    $f = interviewFixture();
    $service = app(InterviewService::class);
    $mine = $service->schedule($f['applicant'], interviewData([$f['interviewer']->id]), $f['hr'])->interview;
    $notMine = $service->schedule(interviewApplicant($f['job'], 'Sana Khan', 'sana@example.test'), interviewData([$f['second']->id]), $f['hr'])->interview;

    // Another company where the same interviewer is also a member and on an interview.
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $otherJob = JobPosition::query()->create(['company_id' => $otherCompany->id, 'name' => 'Driver', 'is_active' => true]);
    $otherHr = jobOpeningRoleUser($otherCompany, 'recruiter');
    $f['interviewer']->allowedCompanies()->syncWithoutDetaching([$otherCompany->id]);
    CurrentCompany::flush();
    $elsewhere = $service->schedule(interviewApplicant($otherJob, 'Bilal Ahmed', 'bilal@example.test'), interviewData([$f['interviewer']->id]), $otherHr)->interview;

    test()->actingAs($f['interviewer']->refresh());
    CurrentCompany::flush();

    expect(InterviewResource::getEloquentQuery()->pluck('id')->all())->toBe([$mine->id])
        ->and(app(InterviewPolicy::class)->viewAny($f['interviewer']))->toBeTrue()
        ->and(app(InterviewPolicy::class)->view($f['interviewer'], $notMine))->toBeFalse()
        ->and(InterviewsRelationManager::canViewForRecord($f['applicant'], ViewApplicant::class))->toBeFalse();

    Livewire::test(ListInterviews::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$notMine, $elsewhere])
        ->assertTableActionHidden('reschedule', $mine)
        ->assertTableActionHidden('giveFeedback', $mine);

    // Someone on no interview at all gets no Interviews list.
    expect(app(InterviewPolicy::class)->viewAny(jobOpeningUser($f['company'])))->toBeFalse();

    test()->actingAs($f['hr']);
    expect(InterviewResource::getEloquentQuery()->pluck('id')->sort()->values()->all())->toBe(collect([$mine->id, $notMine->id])->sort()->values()->all());

    Livewire::test(ListInterviews::class)
        ->assertCanSeeTableRecords([$mine, $notMine])
        ->assertCanNotSeeTableRecords([$elsewhere]);

    // After the start, the interviewer gives their feedback from the list.
    $this->travelTo($mine->starts_at->copy()->addMinutes(30));
    test()->actingAs($f['interviewer']);

    Livewire::test(ListInterviews::class)
        ->filterTable('needs_my_feedback', true)
        ->assertCanSeeTableRecords([$mine])
        ->callTableAction('giveFeedback', $mine, data: ['rating' => 5, 'recommendation' => 'strong_yes', 'feedback' => 'Excellent'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Feedback saved');

    expect($mine->assignmentFor($f['interviewer']->id)?->refresh()->rating)->toBe(5)
        ->and($f['applicant']->refresh()->interview_score)->toEqual(100.0);
});

it('opens the Interviews page for an interviewer without application rights, and refuses users on no interview', function () {
    $f = interviewFixture();
    app(InterviewService::class)->schedule($f['applicant'], interviewData([$f['interviewer']->id]), $f['hr']);

    $this->actingAs($f['interviewer'])->get(InterviewResource::getUrl('index'))->assertOk()->assertSee('Ali Raza');
    $this->actingAs(jobOpeningUser($f['company']))->get(InterviewResource::getUrl('index'))->assertForbidden();
});

it('gives the seeded recruiting roles the company-wide interviews permission', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $service = app(InterviewService::class);

    expect(jobOpeningRoleUser($company, 'recruiter')->can('view_any_recruitment_interview'))->toBeTrue()
        ->and(jobOpeningRoleUser($company, 'hiring_manager')->can('view_any_recruitment_interview'))->toBeTrue()
        ->and(jobOpeningRoleUser($company, 'hr_auditor')->can('view_any_recruitment_interview'))->toBeTrue()
        ->and($service->seesAllInterviews(jobOpeningRoleUser($company, 'hr_auditor')))->toBeTrue()
        ->and($service->seesAllInterviews(jobOpeningUser($company)))->toBeFalse();

    // The role editor only offers the two permissions the Interviews list uses.
    expect(array_keys(FilamentShield::getResourcePermissionsWithLabels(InterviewResource::class)))
        ->toEqualCanonicalizing(['view_any_recruitment_interview', 'view_recruitment_interview']);
});

it('runs schedule, reschedule, feedback and cancel from the application\'s Interviews tab', function () {
    $f = interviewFixture();
    test()->actingAs($f['hr']);
    $tab = fn () => Livewire::test(InterviewsRelationManager::class, ['ownerRecord' => $f['applicant']->refresh(), 'pageClass' => ViewApplicant::class]);

    // The form takes times in the interview timezone (Asia/Karachi by default) and stores UTC.
    $start = now(config('recruitment.interviews.timezone'))->addDays(2)->setTime(11, 0);
    $tab()->callTableAction('scheduleInterview', data: [
        'starts_at'        => $start->toDateTimeString(),
        'duration_minutes' => 30,
        'mode'             => 'phone',
        'location'         => '',
        'interviewer_ids'  => [$f['interviewer']->id, $f['hr']->id],
        'title'            => 'Phone screen',
        'notify_candidate' => true,
    ])->assertHasNoTableActionErrors()->assertNotified('Interview scheduled');

    $interview = Interview::query()->where('applicant_id', $f['applicant']->id)->sole();
    expect($interview->title)->toBe('Phone screen')
        ->and($interview->location)->toBeNull()
        ->and($interview->starts_at->equalTo($start))->toBeTrue()
        ->and(interviewMails())->toHaveCount(3);

    Queue::fake();
    $tab()->assertCanSeeTableRecords([$interview])
        ->callTableAction('reschedule', $interview, data: ['starts_at' => $start->copy()->addHour()->toDateTimeString()])
        ->assertHasNoTableActionErrors();
    expect($interview->refresh()->sequence)->toBe(1)
        ->and(interviewIcs(interviewMailTo($f['interviewer']->email, 'rescheduled')))->toContain('SEQUENCE:1')
        ->and(interviewMailTo($f['hr']->email, 'rescheduled'))->not->toBeNull()
        ->and(interviewMailTo('ali@example.test', 'rescheduled'))->not->toBeNull();

    $this->travelTo($interview->starts_at->copy()->addMinutes(5));
    $tab()->callTableAction('giveFeedback', $interview, data: ['interviewer_id' => $f['interviewer']->id, 'rating' => 2, 'recommendation' => 'no'])
        ->assertHasNoTableActionErrors();
    expect($interview->assignmentFor($f['interviewer']->id)?->refresh()->rating)->toBe(2)
        ->and($f['applicant']->refresh()->interview_score)->toEqual(40.0);

    // Feedback exists, so it can no longer be cancelled; a refused change (start now in the past) becomes a message.
    $tab()->assertTableActionHidden('cancelInterview', $interview)
        ->callTableAction('reschedule', $interview, data: ['notify_candidate' => false])
        ->assertNotified('Please check the details');
    expect($interview->refresh()->status)->toBe(InterviewStatus::SCHEDULED)
        ->and($interview->sequence)->toBe(1);

    $tab()->callTableAction('markCompleted', $interview)->assertHasNoTableActionErrors();
    expect($interview->refresh()->status)->toBe(InterviewStatus::COMPLETED);
});

it('shows the interview score, the next interview and the Interviews tab on the application page', function () {
    $f = interviewFixture();
    $interview = app(InterviewService::class)->schedule($f['applicant'], interviewData([$f['interviewer']->id]), $f['hr'])->interview;
    $f['applicant']->forceFill(['interview_score' => 85])->saveQuietly();

    $this->actingAs($f['hr'])
        ->get(ApplicantResource::getUrl('view', ['record' => $f['applicant']]))
        ->assertOk()
        ->assertSee('Interview score (average rating x 20)')
        ->assertSee('85 / 100')
        ->assertSee($interview->refresh()->whenLabel())
        ->assertSee('Interviews');
});

it('keeps another member company\'s application tab view-only', function () {
    $f = interviewFixture();
    $interview = app(InterviewService::class)->schedule($f['applicant'], interviewData([$f['interviewer']->id]), $f['hr'])->interview;

    $otherCompany = Company::factory()->create(['is_active' => true]);
    $f['hr']->allowedCompanies()->syncWithoutDetaching([$otherCompany->id]);
    CurrentCompany::flush($f['hr']);
    CurrentCompany::switchTo($otherCompany->id, $f['hr']);
    test()->actingAs($f['hr']->refresh());

    Livewire::test(InterviewsRelationManager::class, ['ownerRecord' => $f['applicant']->refresh(), 'pageClass' => ViewApplicant::class])
        ->assertCanSeeTableRecords([$interview])
        ->assertTableActionHidden('scheduleInterview')
        ->assertTableActionHidden('reschedule', $interview)
        ->assertTableActionHidden('cancelInterview', $interview);
});

// ---------------------------------------------------------------------
// .ics format
// ---------------------------------------------------------------------

it('writes RFC 5545 calendar text: escaped values, UTC times and folded lines', function () {
    expect(IcsCalendar::text("Office, floor 2; room \\4\nAsk at reception"))
        ->toBe('Office\, floor 2\; room \\\\4\nAsk at reception');

    $long = 'DESCRIPTION:'.str_repeat('Interview avec Zoë – ', 12);
    $folded = IcsCalendar::fold($long);
    $lines = explode("\r\n", $folded);

    expect(count($lines))->toBeGreaterThan(1);
    foreach ($lines as $index => $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75)
            ->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();

        if ($index > 0) {
            expect($line[0])->toBe(' ');
        }
    }
    expect(str_replace("\r\n ", '', $folded))->toBe($long);

    $ics = IcsCalendar::event(IcsCalendar::METHOD_CANCEL, [
        'uid'       => 'recruitment-interview-7@example.test',
        'sequence'  => 2,
        'start'     => Carbon\Carbon::parse('2026-11-02 10:00', 'Asia/Karachi'),
        'end'       => Carbon\Carbon::parse('2026-11-02 11:00', 'Asia/Karachi'),
        'summary'   => 'Interview: Driver, night shift',
        'organizer' => ['email' => 'hr@example.test', 'name' => 'HR "Team"'],
        'attendees' => [['email' => 'ali@example.test', 'name' => 'Raza, Ali'], ['email' => 'not-an-email', 'name' => 'Skipped']],
    ]);

    expect(collect(explode("\r\n", $ics))->every(fn (string $line): bool => strlen($line) <= 75))->toBeTrue();
    $ics = str_replace("\r\n ", '', $ics);

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->toEndWith("END:VCALENDAR\r\n")
        ->toContain("METHOD:CANCEL\r\n")
        ->toContain("STATUS:CANCELLED\r\n")
        ->toContain("SEQUENCE:2\r\n")
        ->toContain("DTSTART:20261102T050000Z\r\n")
        ->toContain("DTEND:20261102T060000Z\r\n")
        ->toContain('SUMMARY:Interview: Driver\, night shift')
        ->toContain('ORGANIZER;CN="HR Team":mailto:hr@example.test')
        ->toContain('ATTENDEE;CN="Raza, Ali";')
        ->not->toContain('Skipped');
});

it('cancels upcoming interviews when the application is refused, telling the interviewers but not the candidate', function () {
    $f = interviewFixture();
    $service = app(InterviewService::class);
    $interview = $service->schedule($f['applicant'], interviewData([$f['interviewer']->id]), $f['hr'])->interview;
    Auth::login($f['hr']);
    Queue::fake();

    $f['applicant']->refresh()->setAsRefused((int) RefuseReason::query()->value('id'));

    expect($interview->refresh()->status)->toBe(InterviewStatus::CANCELLED)
        ->and($interview->cancel_reason)->toBe('The application was refused.')
        ->and(interviewIcs(interviewMailTo($f['interviewer']->email, 'cancelled')))->toContain('METHOD:CANCEL')
        ->and(interviewMailTo('ali@example.test', 'cancelled'))->toBeNull();
});
