<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use Webkul\Recruitment\Enums\InterviewMode;
use Webkul\Recruitment\Enums\InterviewRecommendation;
use Webkul\Recruitment\Enums\InterviewStatus;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Interview;
use Webkul\Recruitment\Models\InterviewInterviewer;
use Webkul\Recruitment\Support\IcsCalendar;
use Webkul\Recruitment\Support\InterviewScheduleResult;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * Interview scheduling, invitations and feedback (Recruitment Stage 2.3).
 * Every action is checked here, whatever screen calls it:
 *
 *  - scheduling, rescheduling, cancelling and closing (completed / no-show)
 *    need "update" on the application (ApplicantPolicy) in the active
 *    company; interviewers must be active users of the application's company;
 *  - feedback is given by an assigned interviewer for their own scorecard,
 *    or by HR who can update the application, once the interview started;
 *  - the application's interview score is the average submitted rating x 20;
 *  - overlapping interviews of an interviewer are reported, never blocked.
 *
 * Emails are queued (after commit) by InterviewNotifier and every change is
 * written to the application's chatter.
 */
class InterviewService
{
    public const MIN_DURATION = 5;

    public const MAX_DURATION = 480;

    public const MAX_INTERVIEWERS = 20;

    public function __construct(
        protected InterviewNotifier $notifier,
    ) {}

    // ---------------------------------------------------------------------
    // Who may do what
    // ---------------------------------------------------------------------

    /** Schedule / reschedule / cancel / close interviews of this application. */
    public function canManage(?User $user, ?Applicant $applicant): bool
    {
        return $user !== null
            && $applicant !== null
            && $applicant->company_id !== null
            && CurrentCompany::id($user) === (int) $applicant->company_id
            && Gate::forUser($user)->allows('update', $applicant);
    }

    /** HR: sees every interview of their companies, not only their own. */
    public function seesAllInterviews(?User $user): bool
    {
        return $user !== null
            && ($user->can('view_any_recruitment_interview') || $user->can('view_any_recruitment_applicant'));
    }

    public function canView(?User $user, Interview $interview): bool
    {
        if (! $user || ! CurrentCompany::canAccess((int) $interview->company_id, $user)) {
            return false;
        }

        return $this->seesAllInterviews($user)
            || (int) $interview->organizer_id === (int) $user->getKey()
            || $interview->hasInterviewer($user);
    }

    public function canGiveFeedback(?User $actor, Interview $interview, ?User $interviewer = null): bool
    {
        try {
            $this->assertCanGiveFeedback($actor, $interview, $interviewer);

            return true;
        } catch (AuthorizationException|RuntimeException) {
            return false;
        }
    }

    /**
     * @throws AuthorizationException when the actor may not give this feedback
     * @throws RuntimeException when the interview does not take feedback (yet)
     */
    public function assertCanGiveFeedback(?User $actor, Interview $interview, ?User $interviewer = null): void
    {
        if (! $actor || CurrentCompany::id($actor) !== (int) $interview->company_id) {
            throw new AuthorizationException(__('Switch to :company to give feedback on this interview.', ['company' => $this->companyName($interview->company_id)]));
        }

        $interviewer ??= $actor;

        if (! $interview->hasInterviewer($interviewer)) {
            throw new AuthorizationException(__(':name is not an interviewer on this interview.', ['name' => $interviewer->name]));
        }

        if (! $interviewer->is($actor) && ! $this->canManage($actor, $interview->applicant)) {
            throw new AuthorizationException(__('Only the interviewer, or HR who can edit the application, can give this feedback.'));
        }

        if (! $interview->status->acceptsFeedback()) {
            throw new RuntimeException(__('Feedback can only be given on scheduled or completed interviews.'));
        }

        if (! $interview->hasStarted()) {
            throw new RuntimeException(__('Feedback can be given once the interview has started (:when).', ['when' => $interview->whenLabel()]));
        }
    }

    /**
     * Scorecards the viewer may read: HR sees all of them; an interviewer sees
     * their own, and the others only once they have submitted theirs (so
     * nobody is swayed before giving their own view).
     *
     * @return Collection<int, InterviewInterviewer>
     */
    public function visibleFeedback(?User $viewer, Interview $interview): Collection
    {
        $interview->loadMissing('assignments.user');

        if (! $viewer || ! $this->canView($viewer, $interview)) {
            return collect();
        }

        if ($this->seesAllInterviews($viewer) || $this->canManage($viewer, $interview->applicant)) {
            return $interview->assignments->values();
        }

        $own = $interview->assignmentFor($viewer);

        if ($own?->hasSubmitted()) {
            return $interview->assignments->values();
        }

        return collect(array_filter([$own]));
    }

    // ---------------------------------------------------------------------
    // Scheduling
    // ---------------------------------------------------------------------

    /**
     * @param  array{starts_at: mixed, duration_minutes: mixed, mode: mixed, location?: mixed, interviewer_ids: array<int, mixed>, title?: mixed, notes?: mixed, notify_candidate?: mixed}  $data
     */
    public function schedule(Applicant $applicant, array $data, User $actor): InterviewScheduleResult
    {
        $this->assertCanManage($actor, $applicant);

        if ($applicant->refuse_reason_id || ! $applicant->is_active || $applicant->trashed()) {
            throw new RuntimeException(__('This application is refused or archived. Reopen it before scheduling an interview.'));
        }

        $notifyCandidate = (bool) ($data['notify_candidate'] ?? true);
        $validated = $this->validated($data, requireInterviewers: true);
        $start = $this->futureStart($validated['starts_at']);
        $interviewers = $this->companyInterviewers($validated['interviewer_ids'], (int) $applicant->company_id);
        $overlaps = $this->findOverlaps($interviewers->modelKeys(), $start, (int) $validated['duration_minutes']);

        $interview = DB::transaction(function () use ($applicant, $validated, $start, $interviewers, $actor, $notifyCandidate): Interview {
            $applicant->loadMissing(['candidate', 'job']);

            $interview = Interview::query()->create([
                'company_id'       => $applicant->company_id,
                'applicant_id'     => $applicant->id,
                'job_id'           => $applicant->job_id,
                'stage_id'         => $applicant->stage_id,
                'title'            => filled($validated['title'] ?? null) ? trim($validated['title']) : $this->defaultTitle($applicant),
                'starts_at'        => $start,
                'duration_minutes' => (int) $validated['duration_minutes'],
                'mode'             => $validated['mode'],
                'location'         => $this->cleanLocation($validated['location'] ?? null),
                'status'           => InterviewStatus::SCHEDULED,
                'organizer_id'     => $actor->id,
                'notes'            => filled($validated['notes'] ?? null) ? $validated['notes'] : null,
                'sequence'         => 0,
                // Inside the reminder window the invitation itself is the reminder.
                'reminded_at'      => $this->withinReminderWindow($start) ? now() : null,
                'creator_id'       => $actor->id,
            ]);

            foreach ($interviewers as $user) {
                $interview->assignments()->create(['user_id' => $user->id]);
            }

            // Keep the application's interviewer list in step (add only: earlier interviewers stay).
            $applicant->interviewer()->syncWithoutDetaching($interviewers->modelKeys());

            $interview->load(['assignments.user', 'applicant.candidate', 'job', 'company', 'organizer']);

            if ($notifyCandidate && $this->notifier->toCandidate($interview, 'invitation', IcsCalendar::METHOD_REQUEST, $actor)) {
                $interview->forceFill(['candidate_notified_at' => now()])->save();
            }

            $this->notifier->toInterviewers($interview, $interviewers, 'invitation', IcsCalendar::METHOD_REQUEST, $actor);

            $this->log($interview, $actor, __('Interview scheduled'), [
                __('Interviewers: :names', ['names' => $interviewers->pluck('name')->implode(', ')]),
                $interview->candidate_notified_at ? __('Invitation emailed to the candidate.') : __('The candidate was not emailed.'),
            ]);

            return $interview;
        });

        return new InterviewScheduleResult($interview, $overlaps);
    }

    /**
     * New time, place or interviewers. Raises the calendar SEQUENCE so every
     * calendar replaces its entry; removed interviewers get a cancellation,
     * added ones an invitation. Interviewers who already gave feedback stay.
     *
     * @param  array{starts_at: mixed, duration_minutes: mixed, mode: mixed, location?: mixed, interviewer_ids?: array<int, mixed>, notify_candidate?: mixed}  $data
     */
    public function reschedule(Interview $interview, array $data, User $actor): InterviewScheduleResult
    {
        // Fresh, complete rows: a table may have eager-loaded only some user columns (no email).
        $interview->load(['applicant', 'assignments.user']);
        $this->assertCanManage($actor, $interview->applicant);
        $this->assertScheduled($interview, __('Only a scheduled interview can be rescheduled.'));

        $notifyCandidate = (bool) ($data['notify_candidate'] ?? true);
        $validated = $this->validated($data, requireInterviewers: false);
        $start = $this->futureStart($validated['starts_at']);
        $duration = (int) $validated['duration_minutes'];
        $location = $this->cleanLocation($validated['location'] ?? null);

        $currentIds = $interview->assignments->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $newIds = array_key_exists('interviewer_ids', $validated)
            ? $this->companyInterviewers($validated['interviewer_ids'], (int) $interview->company_id)->modelKeys()
            : $currentIds;
        $addedIds = array_values(array_diff($newIds, $currentIds));
        $removedIds = array_values(array_diff($currentIds, $newIds));

        $withFeedback = $interview->assignments->whereIn('user_id', $removedIds)->filter(fn (InterviewInterviewer $assignment): bool => $assignment->hasSubmitted());

        if ($withFeedback->isNotEmpty()) {
            throw ValidationException::withMessages([
                'interviewer_ids' => __(':names already gave feedback and cannot be taken off this interview.', ['names' => $withFeedback->pluck('user.name')->implode(', ')]),
            ]);
        }

        $timeChanged = ! $start->equalTo($interview->starts_at)
            || $duration !== (int) $interview->duration_minutes
            || $validated['mode'] !== $interview->mode->value
            || $location !== $interview->location;
        $candidateFirstNotice = $notifyCandidate && $interview->candidate_notified_at === null;

        if (! $timeChanged && $addedIds === [] && $removedIds === [] && ! $candidateFirstNotice) {
            throw new RuntimeException(__('Nothing changed: pick a new time or place, or change the interviewers.'));
        }

        $overlaps = $this->findOverlaps($newIds, $start, $duration, (int) $interview->id);
        $previousWhen = $interview->whenLabel();

        DB::transaction(function () use ($interview, $actor, $start, $duration, $location, $validated, $addedIds, $removedIds, $timeChanged, $notifyCandidate, $previousWhen): void {
            $removedUsers = $interview->assignments->whereIn('user_id', $removedIds)->pluck('user')->filter()->values();
            $keptUsers = $interview->assignments->whereNotIn('user_id', $removedIds)->pluck('user')->filter()->values();

            $interview->fill([
                'starts_at'        => $start,
                'duration_minutes' => $duration,
                'mode'             => $validated['mode'],
                'location'         => $location,
                'sequence'         => (int) $interview->sequence + 1,
            ]);

            if ($timeChanged) {
                $interview->reminded_at = $this->withinReminderWindow($start) ? now() : null;
                // A new time is a new "after the interview": ask again for feedback still missing.
                $interview->assignments()->whereNull('submitted_at')->update(['feedback_requested_at' => null]);
            }

            $interview->save();

            if ($removedIds !== []) {
                $interview->assignments()->whereIn('user_id', $removedIds)->delete();
            }

            foreach ($addedIds as $userId) {
                $interview->assignments()->create(['user_id' => $userId]);
            }

            $interview->applicant->interviewer()->syncWithoutDetaching($addedIds);
            $interview->load(['assignments.user', 'applicant.candidate', 'job', 'company', 'organizer']);
            $addedUsers = $interview->assignments->whereIn('user_id', $addedIds)->pluck('user')->filter()->values();

            $this->notifier->toInterviewers($interview, $removedUsers, 'removed', IcsCalendar::METHOD_CANCEL, $actor);
            $this->notifier->toInterviewers($interview, $keptUsers, 'rescheduled', IcsCalendar::METHOD_REQUEST, $actor);
            $this->notifier->toInterviewers($interview, $addedUsers, 'invitation', IcsCalendar::METHOD_REQUEST, $actor);

            $candidateEmailed = false;

            if ($notifyCandidate && ($timeChanged || $interview->candidate_notified_at === null)) {
                $kind = $interview->candidate_notified_at ? 'rescheduled' : 'invitation';
                $candidateEmailed = $this->notifier->toCandidate($interview, $kind, IcsCalendar::METHOD_REQUEST, $actor);

                if ($candidateEmailed) {
                    $interview->forceFill(['candidate_notified_at' => now()])->save();
                }
            }

            $this->log($interview, $actor, __('Interview rescheduled'), array_filter([
                $timeChanged ? __('Was: :when', ['when' => $previousWhen]) : null,
                $addedUsers->isNotEmpty() ? __('Added: :names', ['names' => $addedUsers->pluck('name')->implode(', ')]) : null,
                $removedUsers->isNotEmpty() ? __('Removed: :names', ['names' => $removedUsers->pluck('name')->implode(', ')]) : null,
                $candidateEmailed ? __('Update emailed to the candidate.') : null,
            ]));
        });

        return new InterviewScheduleResult($interview->refresh()->load(['assignments.user', 'applicant']), $overlaps);
    }

    public function cancel(Interview $interview, string $reason, User $actor, bool $notifyCandidate = true): Interview
    {
        $interview->load(['applicant', 'assignments.user']);
        $this->assertCanManage($actor, $interview->applicant);
        $this->assertScheduled($interview, __('Only a scheduled interview can be cancelled.'));

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['cancel_reason' => __('Give a reason for cancelling (at most 1000 characters).')]);
        }

        if ($interview->hasSubmittedFeedback()) {
            throw new RuntimeException(__('Feedback has already been given for this interview. Mark it completed instead.'));
        }

        DB::transaction(function () use ($interview, $reason, $actor, $notifyCandidate): void {
            $interview->forceFill([
                'status'        => InterviewStatus::CANCELLED,
                'cancel_reason' => $reason,
                'sequence'      => (int) $interview->sequence + 1,
            ])->save();

            $interview->load(['assignments.user', 'applicant.candidate', 'job', 'company', 'organizer']);

            $this->notifier->toInterviewers($interview, $interview->assignments->pluck('user')->filter(), 'cancelled', IcsCalendar::METHOD_CANCEL, $actor);

            // Only a candidate who was told about the interview hears that it is off.
            $candidateEmailed = $notifyCandidate
                && $interview->candidate_notified_at !== null
                && $this->notifier->toCandidate($interview, 'cancelled', IcsCalendar::METHOD_CANCEL, $actor);

            $this->log($interview, $actor, __('Interview cancelled'), array_filter([
                __('Reason: :reason', ['reason' => $reason]),
                $candidateEmailed ? __('Cancellation emailed to the candidate.') : null,
            ]));
        });

        return $interview;
    }

    public function markCompleted(Interview $interview, User $actor): Interview
    {
        return $this->close($interview, $actor, InterviewStatus::COMPLETED);
    }

    public function markNoShow(Interview $interview, User $actor): Interview
    {
        return $this->close($interview, $actor, InterviewStatus::NO_SHOW);
    }

    // ---------------------------------------------------------------------
    // Feedback
    // ---------------------------------------------------------------------

    /**
     * Saves (or updates) one interviewer's scorecard and recalculates the
     * application's interview score.
     *
     * @param  array{rating: mixed, recommendation: mixed, feedback?: mixed}  $data
     */
    public function submitFeedback(Interview $interview, User $actor, array $data, ?User $interviewer = null): InterviewInterviewer
    {
        $interview->load(['applicant', 'assignments.user']);
        $interviewer ??= $actor;
        $this->assertCanGiveFeedback($actor, $interview, $interviewer);

        if (($data['recommendation'] ?? null) instanceof InterviewRecommendation) {
            $data['recommendation'] = $data['recommendation']->value;
        }

        $validated = Validator::make($data, [
            'rating'         => ['required', 'integer', 'between:1,5'],
            'recommendation' => ['required', Rule::enum(InterviewRecommendation::class)],
            'feedback'       => ['nullable', 'string', 'max:10000'],
        ])->validate();

        return DB::transaction(function () use ($interview, $actor, $interviewer, $validated): InterviewInterviewer {
            /** @var InterviewInterviewer $assignment */
            $assignment = $interview->assignments()->where('user_id', $interviewer->id)->lockForUpdate()->firstOrFail();
            $wasSubmitted = $assignment->hasSubmitted();

            $assignment->fill([
                'rating'         => (int) $validated['rating'],
                'recommendation' => $validated['recommendation'],
                'feedback'       => filled($validated['feedback'] ?? null) ? $validated['feedback'] : null,
                'submitted_at'   => now(),
            ])->save();

            $score = $this->recomputeInterviewScore($interview->applicant);
            $recommendation = InterviewRecommendation::from($validated['recommendation'])->getLabel();

            $this->log($interview, $actor, $wasSubmitted ? __('Interview feedback updated') : __('Interview feedback given'), array_filter([
                __(':name: :rating/5, :recommendation', ['name' => $interviewer->name, 'rating' => $assignment->rating, 'recommendation' => $recommendation]),
                $interviewer->is($actor) ? null : __('Recorded by :name.', ['name' => $actor->name]),
                $score !== null ? __('Interview score: :score', ['score' => rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.')]) : null,
            ]));

            return $assignment->refresh();
        });
    }

    /**
     * The application's interview score: the average of every submitted
     * rating (1-5) on its scheduled/completed interviews, x 20 (0-100). Left
     * unchanged while there is no feedback, so a score typed in by hand stays.
     */
    public function recomputeInterviewScore(Applicant $applicant): ?float
    {
        $average = InterviewInterviewer::query()
            ->whereNotNull('submitted_at')
            ->whereNotNull('rating')
            ->whereHas('interview', fn (Builder $query): Builder => $query
                ->where('applicant_id', $applicant->id)
                ->whereIn('status', InterviewStatus::feedbackValues()))
            ->avg('rating');

        if ($average === null) {
            return null;
        }

        $score = round((float) $average * 20, 2);
        $applicant->forceFill(['interview_score' => $score])->saveQuietly();

        return $score;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Other scheduled interviews of these interviewers at the same time
     * (any company: a person cannot be in two places). Only the person and
     * the time are reported.
     *
     * @param  array<int, int|string>  $userIds
     * @return array<int, array{user_id: int, user_name: string, starts_at: Carbon, ends_at: Carbon}>
     */
    public function findOverlaps(array $userIds, Carbon $start, int $durationMinutes, ?int $ignoreInterviewId = null): array
    {
        $userIds = array_values(array_unique(array_map('intval', array_filter($userIds))));

        if ($userIds === []) {
            return [];
        }

        $end = $start->copy()->addMinutes(max(1, $durationMinutes));

        return InterviewInterviewer::query()
            ->with(['user:id,name', 'interview:id,starts_at,ends_at'])
            ->whereIn('user_id', $userIds)
            ->whereHas('interview', fn (Builder $query): Builder => $query
                ->where('status', InterviewStatus::SCHEDULED->value)
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->when($ignoreInterviewId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreInterviewId)))
            ->get()
            ->filter(fn (InterviewInterviewer $assignment): bool => $assignment->interview !== null)
            ->map(fn (InterviewInterviewer $assignment): array => [
                'user_id'   => (int) $assignment->user_id,
                'user_name' => (string) ($assignment->user?->name ?? __('An interviewer')),
                'starts_at' => $assignment->interview->starts_at,
                'ends_at'   => $assignment->interview->ends_at,
            ])
            ->sortBy(fn (array $overlap): int => $overlap['starts_at']->getTimestamp())
            ->values()
            ->all();
    }

    /** Active users of the company, for the interviewer pickers. */
    public function interviewerQuery(int $companyId): Builder
    {
        return CurrentCompany::scopeUsersOf(User::query(), $companyId)->where('is_active', true);
    }

    public function defaultTitle(Applicant $applicant): string
    {
        $applicant->loadMissing('job');

        return $applicant->job
            ? __('Interview: :job', ['job' => $applicant->job->name])
            : __('Interview');
    }

    protected function close(Interview $interview, User $actor, InterviewStatus $status): Interview
    {
        $interview->loadMissing(['applicant', 'assignments']);
        $this->assertCanManage($actor, $interview->applicant);
        $this->assertScheduled($interview, __('Only a scheduled interview can be marked :status.', ['status' => mb_strtolower($status->getLabel())]));

        if (! $interview->hasStarted()) {
            throw new RuntimeException(__('The interview has not started yet (:when).', ['when' => $interview->whenLabel()]));
        }

        if ($status === InterviewStatus::NO_SHOW && $interview->hasSubmittedFeedback()) {
            throw new RuntimeException(__('Feedback has already been given for this interview. Mark it completed instead.'));
        }

        $interview->forceFill(['status' => $status])->save();

        $this->log($interview, $actor, $status === InterviewStatus::COMPLETED ? __('Interview completed') : __('Candidate did not attend the interview'));

        return $interview;
    }

    /**
     * @throws AuthorizationException
     */
    /**
     * The application was refused or archived: cancel its upcoming interviews
     * so interviewers lose the calendar entry. The candidate is not emailed
     * (the refusal, if any, says it all); interviews that already have
     * feedback are left as they are. Returns how many were cancelled.
     */
    public function cancelForClosedApplication(Applicant $applicant, string $reason, User $actor): int
    {
        $cancelled = 0;

        $interviews = Interview::query()
            ->where('applicant_id', $applicant->id)
            ->where('status', InterviewStatus::SCHEDULED)
            ->where('starts_at', '>', now())
            ->get();

        foreach ($interviews as $interview) {
            if ($interview->hasSubmittedFeedback()) {
                continue;
            }

            $this->cancel($interview, $reason, $actor, notifyCandidate: false);
            $cancelled++;
        }

        return $cancelled;
    }

    protected function assertCanManage(User $actor, ?Applicant $applicant): void
    {
        if (! $applicant) {
            throw new RuntimeException(__('The application of this interview no longer exists.'));
        }

        if (CurrentCompany::id($actor) !== (int) $applicant->company_id) {
            throw new AuthorizationException(__('Switch to :company to change interviews of this application.', ['company' => $this->companyName($applicant->company_id)]));
        }

        if (! Gate::forUser($actor)->allows('update', $applicant)) {
            throw new AuthorizationException(__('You are not allowed to change interviews of this application.'));
        }
    }

    protected function assertScheduled(Interview $interview, string $message): void
    {
        if ($interview->status !== InterviewStatus::SCHEDULED) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validated(array $data, bool $requireInterviewers): array
    {
        if (($data['mode'] ?? null) instanceof InterviewMode) {
            $data['mode'] = $data['mode']->value;
        }

        $mode = InterviewMode::tryFrom((string) ($data['mode'] ?? ''));

        return Validator::make($data, [
            'starts_at'         => ['required', 'date'],
            'duration_minutes'  => ['required', 'integer', 'min:'.self::MIN_DURATION, 'max:'.self::MAX_DURATION],
            'mode'              => ['required', Rule::enum(InterviewMode::class)],
            'location'          => array_values(array_filter([
                $mode?->requiresLocation() ? 'required' : 'nullable',
                'string',
                'max:500',
                $mode === InterviewMode::VIDEO ? 'url:http,https' : null,
            ])),
            'interviewer_ids'   => [$requireInterviewers ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.self::MAX_INTERVIEWERS],
            'interviewer_ids.*' => ['integer', 'distinct'],
            'title'             => ['nullable', 'string', 'max:255'],
            'notes'             => ['nullable', 'string', 'max:5000'],
        ], [], [
            'starts_at'        => __('start'),
            'duration_minutes' => __('duration'),
            'interviewer_ids'  => __('interviewers'),
            'location'         => mb_strtolower($mode?->locationLabel() ?? __('location')),
        ])->validate();
    }

    /** The start in the application timezone (UTC), which must be in the future. */
    protected function futureStart(mixed $value): Carbon
    {
        $start = Carbon::parse($value)->setTimezone(config('app.timezone'))->startOfMinute();

        if (! $start->isFuture()) {
            throw ValidationException::withMessages(['starts_at' => __('The interview must start in the future.')]);
        }

        return $start;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return EloquentCollection<int, User>
     */
    protected function companyInterviewers(array $ids, int $companyId): EloquentCollection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $users = $this->interviewerQuery($companyId)->whereKey($ids)->orderBy('name')->get();

        if ($ids === [] || $users->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'interviewer_ids' => __('Interviewers must be active users of :company.', ['company' => $this->companyName($companyId)]),
            ]);
        }

        return $users;
    }

    protected function withinReminderWindow(Carbon $start): bool
    {
        return $start->lessThanOrEqualTo(now()->addHours(max(1, (int) config('recruitment.interviews.reminder_hours', 24))));
    }

    protected function cleanLocation(mixed $location): ?string
    {
        $location = trim((string) $location);

        return $location === '' ? null : $location;
    }

    protected function companyName(mixed $companyId): string
    {
        return (string) (Company::withTrashed()->whereKey((int) $companyId)->value('name') ?? __('that company'));
    }

    /**
     * Writes the change to the application's chatter (internal note).
     *
     * @param  array<int, string>  $lines
     */
    protected function log(Interview $interview, User $actor, string $headline, array $lines = []): void
    {
        $applicant = $interview->applicant;

        if (! $applicant) {
            return;
        }

        $summary = collect([
            $interview->title,
            $interview->whenLabel(),
            $interview->mode->getLabel().($interview->location ? ': '.$interview->location : ''),
        ])->map(fn (string $line): string => e($line))->implode('<br>');

        $body = '<p><strong>'.e($headline).'</strong></p><p>'.$summary.'</p>'
            .($lines === [] ? '' : '<p>'.collect($lines)->map(fn (string $line): string => e($line))->implode('<br>').'</p>');

        try {
            $applicant->addMessage([
                'type'        => 'note',
                'is_internal' => true,
                'body'        => $body,
                'company_id'  => $applicant->company_id,
                'causer_type' => $actor->getMorphClass(),
                'causer_id'   => $actor->id,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
