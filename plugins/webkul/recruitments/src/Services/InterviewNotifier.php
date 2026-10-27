<?php

namespace Webkul\Recruitment\Services;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Throwable;
use Webkul\Recruitment\Enums\InterviewMode;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\InterviewResource;
use Webkul\Recruitment\Mail\InterviewMail;
use Webkul\Recruitment\Models\Interview;
use Webkul\Recruitment\Models\InterviewInterviewer;
use Webkul\Recruitment\Support\IcsCalendar;
use Webkul\Security\Models\User;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * Interview emails (Stage 2.3), queued through RecruitmentMailer: from the
 * company's address, replies to the person who acted (or the organizer).
 * Invitations, reschedules and cancellations carry an .ics calendar file
 * (RFC 5545, one stable UID per interview, SEQUENCE raised on every change),
 * so the interview lands in, moves in or leaves any calendar without a
 * calendar API.
 *
 * Kinds: invitation, rescheduled, cancelled, removed (interviewer taken off),
 * reminder, feedback_request (interviewer only).
 */
class InterviewNotifier
{
    public const VIEW = 'recruitments::mails.interview';

    public const AUDIENCE_CANDIDATE = 'candidate';

    public const AUDIENCE_INTERVIEWER = 'interviewer';

    public function __construct(
        protected RecruitmentMailer $mailer,
        protected JobPublicProfile $jobProfile,
    ) {}

    /** Emails the candidate; false when they have no usable email address. */
    public function toCandidate(Interview $interview, string $kind, ?string $calendarMethod = null, ?User $sender = null): bool
    {
        $interview->loadMissing(['applicant.candidate', 'company', 'job', 'organizer', 'assignments.user']);
        $candidate = $interview->applicant?->candidate;
        $address = $candidate?->email_from;

        if (! $this->isEmail($address)) {
            return false;
        }

        $recipient = ['address' => $address, 'name' => (string) $candidate->name];
        $attachments = $calendarMethod
            ? [$this->calendarAttachment($interview, self::AUDIENCE_CANDIDATE, $calendarMethod, [['email' => $address, 'name' => $candidate->name]])]
            : [];

        $this->mailer->queue(
            InterviewMail::class,
            self::VIEW,
            $this->payload($interview, self::AUDIENCE_CANDIDATE, $kind, $recipient),
            $interview->company ?? $interview->company_id,
            $sender ?? $interview->organizer,
            $attachments,
        );

        return true;
    }

    /**
     * Emails each interviewer (users without an email address are skipped).
     *
     * @param  iterable<int, User>  $users
     * @return int number of emails queued
     */
    public function toInterviewers(Interview $interview, iterable $users, string $kind, ?string $calendarMethod = null, ?User $sender = null): int
    {
        $interview->loadMissing(['applicant.candidate', 'company', 'job', 'organizer', 'assignments.user']);
        $queued = 0;

        foreach ($users as $user) {
            if (! $this->isEmail($user->email)) {
                continue;
            }

            $attendees = $kind === 'removed'
                ? [['email' => $user->email, 'name' => $user->name]]
                : $this->interviewerAttendees($interview);

            $this->mailer->queue(
                InterviewMail::class,
                self::VIEW,
                $this->payload($interview, self::AUDIENCE_INTERVIEWER, $kind, ['address' => $user->email, 'name' => (string) $user->name], $user),
                $interview->company ?? $interview->company_id,
                $sender ?? $interview->organizer,
                $calendarMethod ? [$this->calendarAttachment($interview, self::AUDIENCE_INTERVIEWER, $calendarMethod, $attendees, $user)] : [],
            );

            $queued++;
        }

        return $queued;
    }

    /** The reminder before the interview: candidate (when they were told about it) and every interviewer. */
    public function reminder(Interview $interview): int
    {
        $interview->loadMissing(['applicant.candidate', 'company', 'job', 'organizer', 'assignments.user']);
        $sent = $interview->candidate_notified_at && $this->toCandidate($interview, 'reminder') ? 1 : 0;

        return $sent + $this->toInterviewers($interview, $interview->assignments->pluck('user')->filter(), 'reminder');
    }

    /** After the interview: ask one interviewer for their scorecard (email + bell notification). */
    public function feedbackRequest(Interview $interview, InterviewInterviewer $assignment): bool
    {
        $assignment->loadMissing('user');
        $user = $assignment->user;

        if (! $user) {
            return false;
        }

        $queued = $this->toInterviewers($interview, [$user], 'feedback_request') > 0;

        try {
            $notification = FilamentNotification::make()
                ->info()
                ->title(e(__('Feedback needed: interview with :candidate', ['candidate' => $this->candidateName($interview)])))
                ->body(e($interview->whenLabel()))
                ->actions(array_filter([
                    ($url = $this->interviewsUrl()) ? Action::make('open')->label(__('Give feedback'))->url($url) : null,
                ]));

            app(HierarchicalNotificationService::class)->sendIdempotentNotification($user, $notification, "recruitment_interview_feedback_{$assignment->id}", [
                'event'        => 'interview_feedback_requested',
                'interview_id' => $interview->id,
                'company_id'   => $interview->company_id,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        return $queued;
    }

    /**
     * The .ics text for one audience: the candidate sees the company and
     * themselves as attendee; interviewers see the candidate's name and the
     * interviewer panel.
     *
     * @param  array<int, array{email: string, name?: string|null}>  $attendees
     */
    public function calendar(Interview $interview, string $audience, string $method, array $attendees = [], ?User $recipient = null): string
    {
        $interview->loadMissing(['applicant.candidate', 'company', 'job', 'organizer', 'assignments.user']);
        $details = $this->details($interview, $audience);
        $isCandidate = $audience === self::AUDIENCE_CANDIDATE;

        $description = array_filter([
            $isCandidate
                ? __('Interview for :job at :company.', ['job' => $details['job'], 'company' => $details['company']])
                : __('Interview with :candidate for :job.', ['candidate' => $details['candidate'], 'job' => $details['job']]),
            __('Format: :mode', ['mode' => $details['mode']]),
            filled($details['location']) ? $details['location_label'].': '.$details['location'] : null,
            filled($details['interviewers']) ? __('Interviewers: :names', ['names' => $details['interviewers']]) : null,
            $isCandidate ? __('If the time does not suit you, reply to the invitation email.') : $this->recordUrl($interview, $recipient),
        ]);

        return IcsCalendar::event($method, [
            'uid'         => $this->uid($interview),
            'sequence'    => (int) $interview->sequence,
            'start'       => $interview->starts_at,
            'end'         => $interview->ends_at,
            'summary'     => $interview->title.' - '.($isCandidate ? $details['company'] : $details['candidate']),
            'location'    => $details['location'],
            'description' => implode("\n", $description),
            'url'         => $details['location_is_link'] ? $details['location'] : null,
            'organizer'   => $this->organizer($interview),
            'attendees'   => $attendees,
        ]);
    }

    /** Stable for the life of the interview, so updates and cancellations replace the same calendar entry. */
    public function uid(Interview $interview): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'aureus-erp.local';

        return 'recruitment-interview-'.$interview->getKey().'@'.$host;
    }

    /**
     * @param  array<int, array{email: string, name?: string|null}>  $attendees
     * @return array{data: string, name: string, mime: string}
     */
    protected function calendarAttachment(Interview $interview, string $audience, string $method, array $attendees, ?User $recipient = null): array
    {
        return [
            'data' => $this->calendar($interview, $audience, $method, $attendees, $recipient),
            'name' => $method === IcsCalendar::METHOD_CANCEL ? 'cancel.ics' : 'invite.ics',
            'mime' => 'text/calendar; charset=UTF-8; method='.$method,
        ];
    }

    /**
     * @param  array{address: string, name: string}  $to
     * @return array<string, mixed>
     */
    protected function payload(Interview $interview, string $audience, string $kind, array $to, ?User $recipient = null): array
    {
        $details = $this->details($interview, $audience);
        $replace = [
            'job'       => $details['job'],
            'company'   => $details['company'],
            'candidate' => $details['candidate'],
            'when'      => $details['when'],
            'date'      => $details['date'],
        ];
        $isInterviewer = $audience === self::AUDIENCE_INTERVIEWER;

        return [
            'kind'          => $kind,
            'audience'      => $audience,
            'subject'       => __("recruitments::mails/interview.{$audience}.{$kind}.subject", $replace),
            'to'            => $to,
            'replace'       => $replace,
            'interview'     => $details,
            'show_details'  => ! in_array($kind, ['cancelled', 'removed'], true),
            'calendar'      => in_array($kind, ['invitation', 'rescheduled'], true) ? 'request' : (in_array($kind, ['cancelled', 'removed'], true) ? 'cancel' : null),
            'cancel_reason' => $isInterviewer && $kind === 'cancelled' ? $interview->cancel_reason : null,
            'action_url'    => $isInterviewer && $kind !== 'removed'
                ? ($kind === 'feedback_request' ? $this->interviewsUrl() : $this->recordUrl($interview, $recipient))
                : null,
            'action_label'  => $kind === 'feedback_request'
                ? __('recruitments::mails/interview.give_feedback')
                : __('recruitments::mails/interview.view_applicant'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function details(Interview $interview, string $audience): array
    {
        $job = $interview->job;
        $jobTitle = $job
            ? ($audience === self::AUDIENCE_CANDIDATE ? $this->jobProfile->title($job) : (string) $job->name)
            : __('the open position');

        return [
            'title'            => (string) $interview->title,
            'when'             => $interview->whenLabel(),
            'date'             => $interview->localStart()->format('D j M Y, H:i'),
            'duration'         => (int) $interview->duration_minutes,
            'mode'             => $interview->mode->getLabel(),
            'mode_value'       => $interview->mode->value,
            'location_label'   => $interview->mode->locationLabel(),
            'location'         => filled($interview->location) ? (string) $interview->location : null,
            'location_is_link' => $interview->mode === InterviewMode::VIDEO && filled($interview->location),
            'candidate'        => $this->candidateName($interview),
            'job'              => $jobTitle,
            'company'          => (string) ($interview->company?->name ?? ''),
            'interviewers'     => $interview->assignments->pluck('user.name')->filter()->implode(', '),
            'organizer'        => $interview->organizer?->name,
        ];
    }

    /** @return array<int, array{email: string, name: string}> */
    protected function interviewerAttendees(Interview $interview): array
    {
        return $interview->assignments
            ->pluck('user')
            ->filter(fn (?User $user): bool => $user !== null && $this->isEmail($user->email))
            ->map(fn (User $user): array => ['email' => $user->email, 'name' => (string) $user->name])
            ->values()
            ->all();
    }

    /** @return array{email: string, name: string}|null */
    protected function organizer(Interview $interview): ?array
    {
        $organizer = $interview->organizer;

        if ($organizer && $this->isEmail($organizer->email)) {
            return ['email' => $organizer->email, 'name' => (string) $organizer->name];
        }

        $from = $this->mailer->from($interview->company);

        return $this->isEmail($from['address'] ?? null) ? ['email' => $from['address'], 'name' => $from['name']] : null;
    }

    /** The application page for someone allowed to open it; otherwise their Interviews list. */
    protected function recordUrl(Interview $interview, ?User $recipient = null): ?string
    {
        try {
            if ($interview->applicant && (! $recipient || $recipient->can('view', $interview->applicant))) {
                return ApplicantResource::getUrl('view', ['record' => $interview->applicant], panel: 'admin');
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $this->interviewsUrl();
    }

    protected function interviewsUrl(): ?string
    {
        try {
            return InterviewResource::getUrl('index', panel: 'admin');
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function candidateName(Interview $interview): string
    {
        return (string) ($interview->applicant?->candidate?->name ?? __('the candidate'));
    }

    protected function isEmail(?string $address): bool
    {
        return filled($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }
}
