<?php

namespace Webkul\Recruitment\Filament\Schemas;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use Webkul\Recruitment\Enums\InterviewMode;
use Webkul\Recruitment\Enums\InterviewRecommendation;
use Webkul\Recruitment\Enums\InterviewStatus;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Interview;
use Webkul\Recruitment\Models\InterviewInterviewer;
use Webkul\Recruitment\Services\InterviewService;
use Webkul\Recruitment\Support\InterviewScheduleResult;
use Webkul\Security\Models\User;

/**
 * Interview table columns and actions (Stage 2.3), shared by the
 * "Interviews" tab of an application and the Recruitment > Applications >
 * Interviews list. Visibility only hides buttons; InterviewService checks
 * every action again (permission, company, status, timing).
 */
class InterviewComponents
{
    /** @return array<int, int|string> */
    public static function durationOptions(): array
    {
        return [
            15  => __('15 minutes'),
            30  => __('30 minutes'),
            45  => __('45 minutes'),
            60  => __('1 hour'),
            90  => __('1.5 hours'),
            120 => __('2 hours'),
            180 => __('3 hours'),
            240 => __('4 hours'),
        ];
    }

    /** @return array<int, TextColumn> */
    public static function columns(bool $withApplicant): array
    {
        $timezone = Interview::displayTimezone();

        return array_values(array_filter([
            TextColumn::make('starts_at')
                ->label(__('When'))
                ->dateTime('D j M Y, H:i', $timezone)
                ->description(fn (Interview $record): string => __(':minutes min', ['minutes' => $record->duration_minutes]).' · '.$timezone)
                ->sortable(),
            $withApplicant
                ? TextColumn::make('applicant.candidate.name')
                    ->label(__('Candidate'))
                    ->description(fn (Interview $record): ?string => $record->job?->name)
                    ->url(fn (Interview $record): ?string => $record->applicant && (static::user()?->can('view', $record->applicant) ?? false)
                        ? ApplicantResource::getUrl('view', ['record' => $record->applicant])
                        : null)
                    ->searchable()
                : null,
            TextColumn::make('title')
                ->label(__('Interview'))
                ->limit(40)
                ->toggleable(isToggledHiddenByDefault: $withApplicant),
            TextColumn::make('mode')
                ->label(__('Format'))
                ->badge()
                ->color('gray'),
            TextColumn::make('location')
                ->label(__('Where'))
                ->limit(40)
                ->tooltip(fn (Interview $record): ?string => $record->location)
                ->url(fn (Interview $record): ?string => $record->mode === InterviewMode::VIDEO ? $record->location : null, shouldOpenInNewTab: true)
                ->placeholder('—'),
            TextColumn::make('interviewer_names')
                ->label(__('Interviewers'))
                ->state(fn (Interview $record): array => $record->assignments
                    ->map(fn (InterviewInterviewer $assignment): string => ($assignment->user?->name ?? '?').($assignment->hasSubmitted() ? ' ✓' : ''))
                    ->all())
                ->badge()
                ->color('gray'),
            TextColumn::make('status')
                ->label(__('Status'))
                ->badge(),
            TextColumn::make('average_rating')
                ->label(__('Avg rating'))
                ->state(function (Interview $record): ?string {
                    $ratings = app(InterviewService::class)->visibleFeedback(static::user(), $record)
                        ->filter(fn (InterviewInterviewer $assignment): bool => $assignment->hasSubmitted())
                        ->pluck('rating');

                    return $ratings->isEmpty() ? null : number_format((float) $ratings->avg(), 1).' / 5';
                })
                ->placeholder('—'),
        ]));
    }

    /** "Schedule interview" (header action of an application's Interviews tab). */
    public static function scheduleAction(Closure $applicant): Action
    {
        return Action::make('scheduleInterview')
            ->label(__('Schedule interview'))
            ->icon('heroicon-o-calendar-days')
            ->visible(function () use ($applicant): bool {
                $record = $applicant();

                return $record instanceof Applicant
                    && ! $record->refuse_reason_id
                    && $record->is_active
                    && static::service()->canManage(static::user(), $record);
            })
            ->modalHeading(__('Schedule interview'))
            ->modalSubmitActionLabel(__('Schedule and send invitations'))
            ->fillForm(fn (): array => static::scheduleDefaults($applicant()))
            ->schema(fn (): array => static::timeAndPlaceFields($applicant(), null))
            ->action(function (array $data, Action $action) use ($applicant): void {
                static::run($action, fn () => static::service()->schedule($applicant(), $data, static::user()), __('Interview scheduled'));
            });
    }

    /** @return array<int, Action> */
    public static function recordActions(): array
    {
        return [
            static::giveFeedbackAction(),
            static::viewFeedbackAction(),
            static::rescheduleAction(),
            static::completeAction(),
            static::noShowAction(),
            static::cancelAction(),
        ];
    }

    public static function rescheduleAction(): Action
    {
        return Action::make('reschedule')
            ->label(__('Reschedule'))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (Interview $record): bool => $record->status === InterviewStatus::SCHEDULED && static::canManage($record))
            ->modalHeading(__('Reschedule interview'))
            ->modalDescription(__('Everyone on the interview gets an updated calendar invitation.'))
            ->modalSubmitActionLabel(__('Reschedule and send updates'))
            ->fillForm(fn (Interview $record): array => [
                'starts_at'        => $record->starts_at,
                'duration_minutes' => $record->duration_minutes,
                'mode'             => $record->mode->value,
                'location'         => $record->location,
                'interviewer_ids'  => $record->assignments->pluck('user_id')->map(fn ($id): int => (int) $id)->all(),
                'notify_candidate' => true,
            ])
            ->schema(fn (Interview $record): array => static::timeAndPlaceFields($record->applicant, $record))
            ->action(function (Interview $record, array $data, Action $action): void {
                static::run($action, fn () => static::service()->reschedule($record, $data, static::user()), __('Interview rescheduled'));
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancelInterview')
            ->label(__('Cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Interview $record): bool => $record->status === InterviewStatus::SCHEDULED
                && ! $record->hasSubmittedFeedback()
                && static::canManage($record))
            ->modalHeading(__('Cancel interview'))
            ->modalDescription(__('Interviewers get a cancellation that removes it from their calendars.'))
            ->modalSubmitActionLabel(__('Cancel interview'))
            ->schema(fn (Interview $record): array => [
                Textarea::make('cancel_reason')
                    ->label(__('Reason'))
                    ->helperText(__('Shown to the interviewers and in the chatter, never to the candidate.'))
                    ->required()
                    ->maxLength(1000),
                Toggle::make('notify_candidate')
                    ->label(__('Email the candidate'))
                    ->default(true)
                    ->visible($record->candidate_notified_at !== null)
                    ->helperText(__('They were sent the invitation, so they receive a cancellation.')),
            ])
            ->action(function (Interview $record, array $data, Action $action): void {
                static::run($action, fn () => static::service()->cancel($record, (string) ($data['cancel_reason'] ?? ''), static::user(), (bool) ($data['notify_candidate'] ?? true)), __('Interview cancelled'));
            });
    }

    public static function completeAction(): Action
    {
        return Action::make('markCompleted')
            ->label(__('Mark completed'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Interview $record): bool => $record->status === InterviewStatus::SCHEDULED && $record->hasStarted() && static::canManage($record))
            ->requiresConfirmation()
            ->action(function (Interview $record, Action $action): void {
                static::run($action, fn () => static::service()->markCompleted($record, static::user()), __('Interview marked completed'));
            });
    }

    public static function noShowAction(): Action
    {
        return Action::make('markNoShow')
            ->label(__('No-show'))
            ->icon('heroicon-o-user-minus')
            ->color('warning')
            ->visible(fn (Interview $record): bool => $record->status === InterviewStatus::SCHEDULED
                && $record->hasStarted()
                && ! $record->hasSubmittedFeedback()
                && static::canManage($record))
            ->requiresConfirmation()
            ->modalDescription(__('Record that the candidate did not attend.'))
            ->action(function (Interview $record, Action $action): void {
                static::run($action, fn () => static::service()->markNoShow($record, static::user()), __('Interview marked as no-show'));
            });
    }

    /**
     * "Give feedback": an interviewer fills in their own scorecard; HR who
     * can edit the application may also record it for any interviewer.
     */
    public static function giveFeedbackAction(): Action
    {
        return Action::make('giveFeedback')
            ->label(fn (Interview $record): string => $record->assignmentFor((int) Auth::id())?->hasSubmitted() ? __('Edit my feedback') : __('Give feedback'))
            ->icon('heroicon-o-star')
            ->color('primary')
            ->visible(fn (Interview $record): bool => static::feedbackTargets($record) !== [])
            ->modalHeading(fn (Interview $record): string => __('Feedback: :title', ['title' => $record->title]))
            ->modalDescription(fn (Interview $record): string => $record->whenLabel())
            ->modalSubmitActionLabel(__('Save feedback'))
            ->fillForm(function (Interview $record): array {
                $targets = static::feedbackTargets($record);
                $userId = array_key_exists((int) Auth::id(), $targets) ? (int) Auth::id() : (int) array_key_first($targets);
                $assignment = $record->assignmentFor($userId);

                return [
                    'interviewer_id' => $userId,
                    'rating'         => $assignment?->rating,
                    'recommendation' => $assignment?->recommendation?->value,
                    'feedback'       => $assignment?->feedback,
                ];
            })
            ->schema(fn (Interview $record): array => [
                Select::make('interviewer_id')
                    ->label(__('Feedback of'))
                    ->options(static::feedbackTargets($record))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set) use ($record): void {
                        $assignment = $record->assignmentFor((int) $state);
                        $set('rating', $assignment?->rating);
                        $set('recommendation', $assignment?->recommendation?->value);
                        $set('feedback', $assignment?->feedback);
                    })
                    ->visible(count(static::feedbackTargets($record)) > 1 || ! array_key_exists((int) Auth::id(), static::feedbackTargets($record))),
                ToggleButtons::make('rating')
                    ->label(__('Rating'))
                    ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'])
                    ->helperText(__('1 = poor, 3 = meets the bar, 5 = outstanding.'))
                    ->inline()
                    ->required(),
                ToggleButtons::make('recommendation')
                    ->label(__('Recommendation'))
                    ->options(InterviewRecommendation::options())
                    ->colors(collect(InterviewRecommendation::cases())->mapWithKeys(fn (InterviewRecommendation $case): array => [$case->value => $case->getColor()])->all())
                    ->inline()
                    ->required(),
                Textarea::make('feedback')
                    ->label(__('Notes'))
                    ->helperText(__('Strengths, concerns and anything the hiring team should know.'))
                    ->rows(5)
                    ->maxLength(10000),
            ])
            ->action(function (Interview $record, array $data, Action $action): void {
                $actor = static::user();
                $interviewer = User::query()->find((int) ($data['interviewer_id'] ?? $actor?->getKey()));

                static::run($action, fn () => static::service()->submitFeedback($record, $actor, $data, $interviewer), __('Feedback saved'));
            });
    }

    public static function viewFeedbackAction(): Action
    {
        return Action::make('viewFeedback')
            ->label(__('View feedback'))
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('gray')
            ->visible(fn (Interview $record): bool => static::service()->canView(static::user(), $record) && $record->hasSubmittedFeedback())
            ->modalHeading(fn (Interview $record): string => __('Feedback: :title', ['title' => $record->title]))
            ->modalDescription(function (Interview $record): string {
                $visible = static::service()->visibleFeedback(static::user(), $record);
                $hidden = $record->assignments->filter(fn (InterviewInterviewer $assignment): bool => $assignment->hasSubmitted())->count()
                    - $visible->filter(fn (InterviewInterviewer $assignment): bool => $assignment->hasSubmitted())->count();

                return $hidden > 0
                    ? __('The other interviewers\' feedback is shown once you have given yours.')
                    : $record->whenLabel();
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->schema(fn (Interview $record): array => [
                RepeatableEntry::make('scorecards')
                    ->hiddenLabel()
                    ->state(fn (): array => static::service()->visibleFeedback(static::user(), $record)
                        ->map(fn (InterviewInterviewer $assignment): array => [
                            'interviewer'    => $assignment->user?->name ?? '?',
                            'rating'         => $assignment->hasSubmitted() ? $assignment->rating.' / 5' : __('Not given yet'),
                            'recommendation' => $assignment->recommendation?->getLabel(),
                            'feedback'       => $assignment->feedback,
                            'submitted'      => $assignment->submitted_at?->copy()->setTimezone(Interview::displayTimezone())->format('j M Y, H:i'),
                        ])
                        ->all())
                    ->schema([
                        TextEntry::make('interviewer')->label(__('Interviewer'))->weight('bold'),
                        TextEntry::make('rating')->label(__('Rating')),
                        TextEntry::make('recommendation')->label(__('Recommendation'))->placeholder('—'),
                        TextEntry::make('submitted')->label(__('Given on'))->placeholder('—'),
                        TextEntry::make('feedback')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(4),
            ]);
    }

    /**
     * Fields shared by "Schedule interview" and "Reschedule".
     *
     * @return array<int, mixed>
     */
    public static function timeAndPlaceFields(Applicant $applicant, ?Interview $interview): array
    {
        $timezone = Interview::displayTimezone();
        $companyId = (int) $applicant->company_id;
        $candidateEmail = $applicant->candidate?->email_from;
        $mode = fn (Get $get): ?InterviewMode => ($value = $get('mode')) instanceof InterviewMode ? $value : InterviewMode::tryFrom((string) $value);

        return array_values(array_filter([
            Grid::make(2)->schema([
                DateTimePicker::make('starts_at')
                    ->label(__('Starts'))
                    ->timezone($timezone)
                    ->seconds(false)
                    ->native(false)
                    ->minDate(now()->setTimezone($timezone)->startOfDay())
                    ->helperText(__('Time in :timezone.', ['timezone' => $timezone]))
                    ->required()
                    ->live(onBlur: true),
                Select::make('duration_minutes')
                    ->label(__('Duration'))
                    ->options(static::durationOptions())
                    ->required()
                    ->live(),
            ]),
            ToggleButtons::make('mode')
                ->label(__('Format'))
                ->options(InterviewMode::class)
                ->inline()
                ->required()
                ->live(),
            TextInput::make('location')
                ->label(fn (Get $get): string => $mode($get)?->locationLabel() ?? __('Location'))
                ->required(fn (Get $get): bool => (bool) $mode($get)?->requiresLocation())
                ->url(fn (Get $get): bool => $mode($get) === InterviewMode::VIDEO)
                ->placeholder(fn (Get $get): ?string => match ($mode($get)) {
                    InterviewMode::VIDEO => 'https://meet.google.com/abc-defg-hij',
                    InterviewMode::PHONE => __('Number to call (optional)'),
                    default              => __('Office address, floor, room'),
                })
                ->helperText(fn (Get $get): ?string => $mode($get) === InterviewMode::PHONE ? __('Leave empty to call the candidate on the number in their application.') : null)
                ->maxLength(500),
            Select::make('interviewer_ids')
                ->label(__('Interviewers'))
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn (): array => static::service()->interviewerQuery($companyId)->orderBy('name')->pluck('name', 'id')->all())
                ->helperText(__('Active users of this company. Each gets a calendar invitation.'))
                ->required()
                ->maxItems(InterviewService::MAX_INTERVIEWERS)
                ->live(),
            TextEntry::make('overlap_warning')
                ->hiddenLabel()
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->state(fn (Get $get): ?string => static::overlapPreview($get, $interview?->getKey()))
                ->visible(fn (Get $get): bool => filled(static::overlapPreview($get, $interview?->getKey()))),
            $interview === null
                ? TextInput::make('title')
                    ->label(__('Title'))
                    ->placeholder(static::service()->defaultTitle($applicant))
                    ->helperText(__('Shown in the calendar invitation.'))
                    ->maxLength(255)
                : null,
            $interview === null
                ? Textarea::make('notes')
                    ->label(__('Internal notes'))
                    ->helperText(__('For the hiring team only; never sent to the candidate.'))
                    ->rows(2)
                    ->maxLength(5000)
                : null,
            Toggle::make('notify_candidate')
                ->label(__('Email the candidate'))
                ->helperText(filled($candidateEmail)
                    ? __('Sends the :kind with a calendar file to :email.', ['kind' => $interview === null ? __('invitation') : __('new time'), 'email' => $candidateEmail])
                    : __('This candidate has no email address, so they will not be emailed.')),
        ]));
    }

    /** @return array<string, mixed> */
    public static function scheduleDefaults(?Applicant $applicant): array
    {
        $applicant?->loadMissing(['job.interviewers', 'interviewer']);
        $companyId = (int) $applicant?->company_id;

        $suggested = collect($applicant?->interviewer?->pluck('id') ?? [])
            ->merge($applicant?->job?->interviewers?->pluck('id') ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $allowed = $suggested === [] ? [] : static::service()->interviewerQuery($companyId)->whereKey($suggested)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $start = now()->setTimezone(Interview::displayTimezone())->addDay()->setTime(10, 0);

        return [
            'starts_at'        => $start->copy()->setTimezone(config('app.timezone')),
            'duration_minutes' => 60,
            'mode'             => InterviewMode::IN_PERSON->value,
            'interviewer_ids'  => $allowed,
            'notify_candidate' => filled($applicant?->candidate?->email_from),
        ];
    }

    protected static function overlapPreview(Get $get, mixed $ignoreInterviewId = null): ?string
    {
        $userIds = array_filter((array) $get('interviewer_ids'));
        $start = $get('starts_at');
        $duration = (int) $get('duration_minutes');

        if ($userIds === [] || blank($start) || $duration <= 0) {
            return null;
        }

        try {
            $start = Carbon::parse($start)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        return InterviewScheduleResult::describeOverlaps(
            static::service()->findOverlaps($userIds, $start, $duration, $ignoreInterviewId ? (int) $ignoreInterviewId : null),
        );
    }

    /**
     * Interviewers the signed-in user may give feedback for on this
     * interview: themselves, or every interviewer for HR who can edit the
     * application.
     *
     * @return array<int, string> user id => name
     */
    public static function feedbackTargets(Interview $interview): array
    {
        $actor = static::user();

        if (! $actor) {
            return [];
        }

        $service = static::service();

        return $interview->assignments
            ->filter(fn (InterviewInterviewer $assignment): bool => $assignment->user !== null && $service->canGiveFeedback($actor, $interview, $assignment->user))
            ->mapWithKeys(fn (InterviewInterviewer $assignment): array => [(int) $assignment->user_id => (string) $assignment->user->name])
            ->all();
    }

    protected static function canManage(Interview $interview): bool
    {
        return static::service()->canManage(static::user(), $interview->applicant);
    }

    /** Runs a service call; refusals and validation problems become a message and keep the modal open. */
    protected static function run(Action $action, Closure $call, string $successTitle): void
    {
        try {
            $result = $call();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(__('Please check the details'))->body(collect($e->errors())->flatten()->implode(' '))->send();
            $action->halt();
        } catch (AuthorizationException|RuntimeException $e) {
            Notification::make()->danger()->title(__('Could not update the interview'))->body($e->getMessage())->send();
            $action->halt();
        }

        Notification::make()->success()->title($successTitle)->send();

        if ($result instanceof InterviewScheduleResult && $result->hasOverlaps()) {
            Notification::make()
                ->warning()
                ->title(__('Interviewers with another interview at that time'))
                ->body(e((string) $result->overlapMessage()))
                ->persistent()
                ->send();
        }
    }

    protected static function service(): InterviewService
    {
        return app(InterviewService::class);
    }

    protected static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
