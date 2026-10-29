<?php

namespace Webkul\Support\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Support\Enums\ApprovalRequestStatus;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Filament\Resources\ApprovalRequestResource\Pages\ListApprovalRequests;
use Webkul\Support\Models\ApprovalReminder;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\ApprovalReminderService;
use Webkul\TimeOff\Models\Leave;

class ApprovalRequestResource extends Resource
{
    protected static ?string $model = ApprovalRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    protected static ?int $navigationSort = 81;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Setting;
    }

    public static function getNavigationLabel(): string
    {
        return 'Approval Queue';
    }

    /**
     * Counts only requests the CURRENT viewer can actually act on right
     * now -- not every pending request company-wide, which would often be
     * misleading (a request waiting on someone else's approval step isn't
     * something this user can do anything about). Reuses the exact same
     * canAct() check the row-level Approve/Reject buttons already gate on,
     * so the badge and what's actually clickable always agree.
     */
    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        $count = app(ApprovalEngine::class)->actionableRequestsFor($user, (int) $user->default_company_id)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        $employeeChain = ['employee.department.manager.user', 'employee.job', 'employee.user', 'employee.parent.user', 'employee.team.manager.user'];

        // Everything the columns and row actions read, once per page: each
        // subject with its employee (the "For Employee" / "Details" columns
        // and the engine's hierarchy routing), the requester chain (canAct),
        // decisions and reminders.
        return parent::getEloquentQuery()
            ->where('company_id', Auth::user()?->default_company_id)
            ->with([
                'workflow.steps.approverUser',
                'workflow.steps.approverRole',
                'requester.employee.parent.user',
                'requester.employee.department.manager.user',
                'requester.employee.team.manager.user',
                'decisions.actor',
                'decisions.step',
                'reminders',
                'subject' => fn (MorphTo $subject) => $subject->morphWith([
                    EmployeeRequest::class => $employeeChain,
                    Leave::class           => [...$employeeChain, 'holidayStatus'],
                    Employee::class        => ['department', 'job', 'user', 'parent.user', 'team.manager.user'],
                ]),
            ])
            ->afterQuery(function ($requests) {
                static::preloadAttendanceDates($requests);

                return $requests;
            });
    }

    /** @var array<int, ?string> attendance record id => date (Y-m-d), for the attendance corrections on screen */
    protected static array $attendanceDates = [];

    /** One query for the attendance dates of the attendance correction requests on the page. */
    protected static function preloadAttendanceDates(iterable $requests): void
    {
        $ids = collect($requests)
            ->map(fn ($request) => $request instanceof ApprovalRequest && $request->subject instanceof EmployeeRequest ? (array) ($request->subject->payload ?? []) : [])
            ->filter(fn (array $payload): bool => empty($payload['attendance_date']) && ! empty($payload['attendance_record_id']))
            ->map(fn (array $payload): int => (int) $payload['attendance_record_id'])
            ->reject(fn (int $id): bool => array_key_exists($id, static::$attendanceDates))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $dates = AttendanceRecord::query()->whereKey($ids->all())->get(['id', 'attendance_date'])
            ->mapWithKeys(fn (AttendanceRecord $record): array => [(int) $record->id => $record->attendance_date?->toDateString()]);

        foreach ($ids as $id) {
            static::$attendanceDates[$id] = $dates[$id] ?? null;
        }
    }

    protected static function attendanceDate(int $attendanceRecordId): ?string
    {
        if (! array_key_exists($attendanceRecordId, static::$attendanceDates)) {
            static::$attendanceDates[$attendanceRecordId] = AttendanceRecord::query()->find($attendanceRecordId)?->attendance_date?->toDateString();
        }

        return static::$attendanceDates[$attendanceRecordId];
    }

    /** "waiting for 26h" while pending at a step, else null. */
    public static function waitingText(ApprovalRequest $record): ?string
    {
        $hours = $record->waitingHours();

        return $hours === null ? null : "waiting for {$hours}h";
    }

    /** "reminded 2×", "reminded 3× · escalated" for the current step, else null. */
    public static function remindersText(ApprovalRequest $record): ?string
    {
        if ($record->status !== ApprovalRequestStatus::Pending->value) {
            return null;
        }

        $sent = $record->currentStepReminders();
        $count = $sent->where('kind', ApprovalReminder::KIND_REMINDER)->count();
        $escalated = $sent->contains('kind', ApprovalReminder::KIND_ESCALATION);

        if ($count === 0 && ! $escalated) {
            return null;
        }

        return "reminded {$count}×".($escalated ? ' · escalated' : '');
    }

    /** Every reminder and escalation of the request, oldest first. */
    public static function reminderHistoryText(ApprovalRequest $record): string
    {
        $record->loadMissing(['reminders.step']);

        if ($record->reminders->isEmpty()) {
            return 'No reminders sent.';
        }

        return $record->reminders->sortBy('id')->map(function (ApprovalReminder $reminder): string {
            $what = $reminder->kind === ApprovalReminder::KIND_ESCALATION ? 'Escalated to HR' : "Reminder {$reminder->reminder_number}";
            $step = $reminder->step ? "Step {$reminder->step->sequence}: {$reminder->step->name}" : 'Step #'.$reminder->step_id;
            $round = $reminder->submission_round > 1 ? " (round {$reminder->submission_round})" : '';
            $recipients = count((array) $reminder->recipient_ids);

            return "• {$what} at {$step}{$round} on ".$reminder->sent_at?->format('d M Y, h:i A')." to {$recipients} person(s)";
        })->join("\n");
    }

    /**
     * One line per decision, oldest first, naming the step it was taken at.
     * The same person can decide two steps in a row (HR covering the line
     * manager step of an employee without a manager, then the HR step), so
     * the step is what tells those decisions apart.
     */
    public static function decisionHistoryText(ApprovalRequest $record): string
    {
        $record->loadMissing(['decisions.actor', 'decisions.step']);

        if ($record->decisions->isEmpty()) {
            return 'No decisions recorded yet.';
        }

        return $record->decisions->sortBy('id')->map(function ($decision): string {
            $action = strtoupper((string) $decision->decision);
            $step = $decision->step
                ? "Step {$decision->step->sequence}: {$decision->step->name}"
                : 'Step #'.$decision->step_id;
            $who = $decision->actor?->name ?? 'User #'.$decision->actor_id;
            $date = ($decision->decided_at ?? $decision->created_at)?->format('d M Y, h:i A') ?? '';
            $note = $decision->reason ? " (Note: {$decision->reason})" : '';
            $round = (int) $decision->submission_round > 1 ? " (round {$decision->submission_round})" : '';
            $changes = $decision->decision === 'resubmitted' ? static::changesText((array) $decision->previous_values, (array) $decision->new_values) : '';

            return "• [{$action}] {$step}{$round} by {$who} on {$date}{$note}{$changes}";
        })->join("\n");
    }

    /**
     * " -- changed: Amount 100 → 80, Description ..." for a resubmission.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $new
     */
    public static function changesText(array $previous, array $new): string
    {
        $changes = collect($new)
            ->map(function (mixed $value, string $field) use ($previous): string {
                $format = fn (mixed $v): string => is_array($v) ? json_encode($v) : (filled($v) ? (string) $v : '—');

                return str($field)->headline()->toString().': '.Str::limit($format($previous[$field] ?? null), 40).' → '.Str::limit($format($value), 40);
            });

        return $changes->isEmpty() ? '' : ' — changed: '.$changes->join('; ');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 3])
                    ->schema([
                        Group::make([
                            Section::make('Request & Time Details')
                                ->schema([
                                    TextEntry::make('workflow.name')
                                        ->label('Approval Workflow')
                                        ->badge()
                                        ->color('primary')
                                        ->icon('heroicon-o-queue-list'),
                                    TextEntry::make('request_type')
                                        ->label('Request Category')
                                        ->badge()
                                        ->color('info'),
                                    TextEntry::make('day_of_week')
                                        ->label('Day of the Week')
                                        ->getStateUsing(function (ApprovalRequest $record): ?string {
                                            if ($record->subject instanceof EmployeeRequest) {
                                                $payload = (array) ($record->subject->payload ?? []);
                                                if (! empty($payload['day_of_week'])) {
                                                    return $payload['day_of_week'];
                                                }
                                                $dateStr = $payload['attendance_date'] ?? null;
                                                if (! $dateStr && isset($payload['attendance_record_id'])) {
                                                    $att = AttendanceRecord::find($payload['attendance_record_id']);
                                                    $dateStr = $att?->attendance_date?->toDateString();
                                                }

                                                return $dateStr ? Carbon::parse($dateStr)->format('l') : $record->created_at?->format('l');
                                            }
                                            if ($record->subject instanceof Leave) {
                                                return Carbon::parse($record->subject->request_date_from)->format('l');
                                            }

                                            return $record->submitted_at?->format('l');
                                        })
                                        ->badge()
                                        ->color('primary')
                                        ->icon('heroicon-o-calendar-days'),
                                    TextEntry::make('target_date')
                                        ->label('Target Date')
                                        ->getStateUsing(function (ApprovalRequest $record): ?string {
                                            if ($record->subject instanceof EmployeeRequest) {
                                                $payload = (array) ($record->subject->payload ?? []);
                                                $dateStr = $payload['attendance_date'] ?? null;
                                                if (! $dateStr && isset($payload['attendance_record_id'])) {
                                                    $att = AttendanceRecord::find($payload['attendance_record_id']);
                                                    $dateStr = $att?->attendance_date?->toDateString();
                                                }

                                                return $dateStr ? Carbon::parse($dateStr)->format('d F Y (l)') : $record->subject->created_at?->format('d F Y (l)');
                                            }
                                            if ($record->subject instanceof Leave) {
                                                $from = Carbon::parse($record->subject->request_date_from);
                                                $to = Carbon::parse($record->subject->request_date_to ?: $record->subject->request_date_from);
                                                if ($from->isSameDay($to)) {
                                                    return $from->format('d F Y (l)');
                                                }

                                                return $from->format('d M Y (D)').' to '.$to->format('d M Y (D)');
                                            }

                                            return $record->submitted_at?->format('d F Y (l)');
                                        })
                                        ->icon('heroicon-o-calendar'),
                                    TextEntry::make('times_info')
                                        ->label('Requested Time Changes')
                                        ->getStateUsing(function (ApprovalRequest $record): ?string {
                                            if ($record->subject instanceof EmployeeRequest) {
                                                $payload = (array) ($record->subject->payload ?? []);
                                                if (isset($payload['requested']['check_in'])) {
                                                    $reqIn = $payload['requested']['check_in'] ? Carbon::parse($payload['requested']['check_in'])->format('H:i') : '—';
                                                    $reqOut = ! empty($payload['requested']['check_out']) ? Carbon::parse($payload['requested']['check_out'])->format('H:i') : '—';
                                                    $origIn = ! empty($payload['original']['check_in']) ? Carbon::parse($payload['original']['check_in'])->format('H:i') : '—';
                                                    $origOut = ! empty($payload['original']['check_out']) ? Carbon::parse($payload['original']['check_out'])->format('H:i') : '—';
                                                    if (($payload['kind'] ?? '') === 'attendance_missing_day') {
                                                        return "Missed Day Requested: Check-In {$reqIn} | Check-Out {$reqOut}";
                                                    }

                                                    return "Check-In: {$origIn} → {$reqIn} | Check-Out: {$origOut} → {$reqOut}";
                                                }
                                            }

                                            return null;
                                        })
                                        ->visible(fn (ApprovalRequest $record): bool => $record->subject instanceof EmployeeRequest && isset($record->subject->payload['requested']))
                                        ->icon('heroicon-o-clock')
                                        ->columnSpanFull(),
                                    TextEntry::make('sensitive_change_diff')
                                        ->label('Requested Sensitive Changes')
                                        ->getStateUsing(function (ApprovalRequest $record): ?string {
                                            if ($record->request_type !== 'employee_sensitive_change') {
                                                return null;
                                            }
                                            $prev = (array) data_get($record->context, 'previous_values', []);
                                            $new = (array) data_get($record->context, 'new_values', []);
                                            $lines = [];
                                            foreach ($new as $key => $val) {
                                                $fieldLabel = ucwords(str_replace('_', ' ', $key));
                                                $oldVal = $prev[$key] ?? '—';
                                                $newVal = $val ?? '—';
                                                if (empty($oldVal)) {
                                                    $oldVal = '—';
                                                }
                                                if (empty($newVal)) {
                                                    $newVal = '—';
                                                }
                                                $lines[] = "• {$fieldLabel}: {$oldVal} → {$newVal}";
                                            }

                                            return implode("\n", $lines);
                                        })
                                        ->visible(fn (ApprovalRequest $record): bool => $record->request_type === 'employee_sensitive_change')
                                        ->extraAttributes(['style' => 'white-space: pre-line;'])
                                        ->icon('heroicon-o-shield-check')
                                        ->columnSpanFull(),
                                    TextEntry::make('subject_reason')
                                        ->label('Reason / Notes')
                                        ->getStateUsing(function (ApprovalRequest $record): ?string {
                                            if ($record->subject instanceof EmployeeRequest) {
                                                return $record->subject->description;
                                            }
                                            if ($record->subject instanceof Leave) {
                                                return $record->subject->private_name;
                                            }
                                            if ($record->request_type === 'employee_sensitive_change') {
                                                return data_get($record->context, 'reason');
                                            }

                                            return null;
                                        })
                                        ->placeholder('No reason provided')
                                        ->icon('heroicon-o-chat-bubble-bottom-center-text')
                                        ->columnSpanFull(),
                                    TextEntry::make('amount')
                                        ->label('Amount')
                                        ->numeric(decimalPlaces: 2)
                                        ->visible(fn (ApprovalRequest $record): bool => (float) ($record->amount ?? 0) > 0)
                                        ->weight(FontWeight::Bold),
                                ])
                                ->columns(2),

                            Section::make('Approval Trail & Decisions')
                                ->schema([
                                    TextEntry::make('current_step_sequence')
                                        ->label('Current Step Sequence')
                                        ->formatStateUsing(fn ($state): string => $state ? "Step #{$state}" : 'Completed')
                                        ->icon('heroicon-o-numbered-list'),
                                    TextEntry::make('current_approver_desc')
                                        ->label('Awaiting Action By')
                                        ->getStateUsing(fn (ApprovalRequest $record): string => app(ApprovalEngine::class)->describeCurrentApprover($record))
                                        ->badge()
                                        ->color('warning')
                                        ->icon('heroicon-o-user-circle')
                                        ->visible(fn (ApprovalRequest $record): bool => $record->status === ApprovalRequestStatus::Pending->value),
                                    TextEntry::make('returned_comment')
                                        ->label('Returned for changes')
                                        ->getStateUsing(function (ApprovalRequest $record): ?string {
                                            $return = $record->decisions->where('decision', 'returned')->sortBy('id')->last();

                                            return $return ? trim(($return->actor?->name ?? 'An approver').': '.$return->reason) : null;
                                        })
                                        ->badge()
                                        ->color('info')
                                        ->icon('heroicon-o-arrow-uturn-left')
                                        ->visible(fn (ApprovalRequest $record): bool => $record->status === ApprovalRequestStatus::Returned->value),
                                    TextEntry::make('waiting_for')
                                        ->label('Waiting')
                                        ->getStateUsing(fn (ApprovalRequest $record): ?string => static::waitingText($record))
                                        ->icon('heroicon-o-clock')
                                        ->visible(fn (ApprovalRequest $record): bool => $record->status === ApprovalRequestStatus::Pending->value),
                                    TextEntry::make('reminders_sent')
                                        ->label('Reminders')
                                        ->getStateUsing(fn (ApprovalRequest $record): ?string => static::remindersText($record))
                                        ->badge()
                                        ->color(fn (ApprovalRequest $record): string => str_contains((string) static::remindersText($record), 'escalated') ? 'danger' : 'warning')
                                        ->placeholder('None yet')
                                        ->visible(fn (ApprovalRequest $record): bool => $record->status === ApprovalRequestStatus::Pending->value),
                                    TextEntry::make('decisions_history')
                                        ->label('Decision History')
                                        ->getStateUsing(fn (ApprovalRequest $record): string => static::decisionHistoryText($record))
                                        ->extraAttributes(['style' => 'white-space: pre-line;'])
                                        ->columnSpanFull(),
                                    TextEntry::make('reminder_history')
                                        ->label('Reminder History')
                                        ->getStateUsing(fn (ApprovalRequest $record): string => static::reminderHistoryText($record))
                                        ->extraAttributes(['style' => 'white-space: pre-line;'])
                                        ->visible(fn (ApprovalRequest $record): bool => $record->reminders->isNotEmpty())
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                        ])->columnSpan(2),

                        Group::make([
                            Section::make('Who Sent The Request')
                                ->schema([
                                    TextEntry::make('employee_name')
                                        ->label('Target Employee')
                                        ->getStateUsing(function (ApprovalRequest $record): ?string {
                                            if ($record->subject instanceof EmployeeRequest) {
                                                return $record->subject->employee?->name;
                                            }
                                            if ($record->subject instanceof Leave) {
                                                return $record->subject->employee?->name;
                                            }
                                            if ($record->subject instanceof Employee) {
                                                return $record->subject->name;
                                            }

                                            return $record->requester?->name;
                                        })
                                        ->weight(FontWeight::Bold)
                                        ->icon('heroicon-o-user'),
                                    TextEntry::make('employee_department')
                                        ->label('Department')
                                        ->getStateUsing(function (ApprovalRequest $record): ?string {
                                            if ($record->subject instanceof EmployeeRequest) {
                                                return $record->subject->employee?->department?->name;
                                            }
                                            if ($record->subject instanceof Leave) {
                                                return $record->subject->employee?->department?->name;
                                            }
                                            if ($record->subject instanceof Employee) {
                                                return $record->subject->department?->name;
                                            }

                                            return null;
                                        })
                                        ->placeholder('—')
                                        ->icon('heroicon-o-building-office'),
                                    TextEntry::make('requester.name')
                                        ->label('Submitted By')
                                        ->icon('heroicon-o-paper-airplane')
                                        ->placeholder('System'),
                                    TextEntry::make('submitted_at')
                                        ->label('Submitted At')
                                        ->dateTime('d M Y, h:i A (l)')
                                        ->icon('heroicon-o-clock'),
                                ]),

                            Section::make('Status')
                                ->schema([
                                    TextEntry::make('status')
                                        ->badge()
                                        ->formatStateUsing(fn (?string $state): string => ApprovalRequestStatus::labelFor($state))
                                        ->color(fn (?string $state): string => ApprovalRequestStatus::colorFor($state)),
                                    TextEntry::make('completed_at')
                                        ->label('Completed At')
                                        ->dateTime('d M Y, h:i A')
                                        ->placeholder('Pending')
                                        ->visible(fn (ApprovalRequest $record): bool => filled($record->completed_at)),
                                ]),
                        ])->columnSpan(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Request')->formatStateUsing(fn ($state): string => 'APR-'.$state)->sortable(),
                TextColumn::make('target_employee')
                    ->label('For Employee')
                    ->getStateUsing(function (ApprovalRequest $record): ?string {
                        if ($record->subject instanceof EmployeeRequest) {
                            return $record->subject->employee?->name;
                        }
                        if ($record->subject instanceof Leave) {
                            return $record->subject->employee?->name;
                        }

                        return $record->requester?->name;
                    })
                    ->description(function (ApprovalRequest $record): ?string {
                        if ($record->subject instanceof EmployeeRequest) {
                            $dept = $record->subject->employee?->department?->name;
                            $job = $record->subject->employee?->job_title ?? $record->subject->employee?->job?->name;

                            return $dept && $job ? "{$job} • {$dept}" : ($job ?: $dept);
                        }
                        if ($record->subject instanceof Leave) {
                            $dept = $record->subject->employee?->department?->name;
                            $job = $record->subject->employee?->job_title ?? $record->subject->employee?->job?->name;

                            return $dept && $job ? "{$job} • {$dept}" : ($job ?: $dept);
                        }

                        return null;
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHasMorph('subject', [
                            EmployeeRequest::class,
                            Leave::class,
                        ], function (Builder $q) use ($search) {
                            $q->whereHas('employee', fn ($eq) => $eq->where('name', 'like', "%{$search}%"));
                        });
                    }),
                TextColumn::make('workflow.name')->label('Workflow')->searchable(),
                TextColumn::make('request_type')->badge()->searchable(),
                TextColumn::make('day_and_date')
                    ->label('Day & Date')
                    ->getStateUsing(function (ApprovalRequest $record): ?string {
                        if ($record->subject instanceof EmployeeRequest) {
                            $payload = (array) ($record->subject->payload ?? []);
                            $dateStr = $payload['attendance_date'] ?? null;
                            if (! $dateStr && isset($payload['attendance_record_id'])) {
                                $dateStr = static::attendanceDate((int) $payload['attendance_record_id']);
                            }
                            if ($dateStr) {
                                $c = Carbon::parse($dateStr);
                                $day = $payload['day_of_week'] ?? $c->format('l');

                                return $c->format('d M Y').' ('.$day.')';
                            }

                            return $record->subject->created_at?->format('d M Y (l)');
                        }
                        if ($record->subject instanceof Leave) {
                            $from = Carbon::parse($record->subject->request_date_from);
                            $to = Carbon::parse($record->subject->request_date_to ?: $record->subject->request_date_from);
                            if ($from->isSameDay($to)) {
                                return $from->format('d M Y').' ('.$from->format('l').')';
                            }

                            return $from->format('d M').' ('.$from->format('D').') – '.$to->format('d M Y').' ('.$to->format('D').')';
                        }

                        return $record->submitted_at?->format('d M Y (l)');
                    })
                    ->badge(fn ($state): bool => filled($state))
                    ->color('gray'),
                TextColumn::make('request_details')
                    ->label('Details / Times')
                    ->getStateUsing(function (ApprovalRequest $record): string {
                        if ($record->subject instanceof EmployeeRequest) {
                            $sub = $record->subject;
                            $payload = (array) ($sub->payload ?? []);
                            if (isset($payload['requested']['check_in'])) {
                                $reqIn = $payload['requested']['check_in'] ? Carbon::parse($payload['requested']['check_in'])->format('H:i') : '—';
                                $reqOut = ! empty($payload['requested']['check_out']) ? Carbon::parse($payload['requested']['check_out'])->format('H:i') : '—';
                                $origIn = ! empty($payload['original']['check_in']) ? Carbon::parse($payload['original']['check_in'])->format('H:i') : '—';
                                $origOut = ! empty($payload['original']['check_out']) ? Carbon::parse($payload['original']['check_out'])->format('H:i') : '—';
                                if (($payload['kind'] ?? '') === 'attendance_missing_day') {
                                    return "Missed Day [In: {$reqIn} | Out: {$reqOut}]";
                                }

                                return "In: {$origIn} → {$reqIn} | Out: {$origOut} → {$reqOut}";
                            }

                            return $sub->nature_of_expense ?: ($sub->title ?? '—');
                        }
                        if ($record->subject instanceof Leave) {
                            $leave = $record->subject;
                            $type = $leave->holidayStatus?->name ?? 'Leave';

                            return "{$type} ({$leave->number_of_days} days)";
                        }

                        return $record->context['summary'] ?? $record->workflow?->name ?? '—';
                    })
                    ->description(function (ApprovalRequest $record): ?string {
                        if ($record->subject instanceof EmployeeRequest) {
                            return $record->subject->description ? Str::limit($record->subject->description, 50) : null;
                        }
                        if ($record->subject instanceof Leave) {
                            return $record->subject->private_name ? Str::limit($record->subject->private_name, 50) : null;
                        }
                        if ($record->request_type === 'employee_sensitive_change') {
                            $r = data_get($record->context, 'reason');

                            return $r ? Str::limit($r, 50) : null;
                        }

                        return null;
                    })
                    ->wrap(),
                TextColumn::make('requester.name')->label('Requester')->placeholder('System'),
                TextColumn::make('amount')->numeric(decimalPlaces: 2)->placeholder('—')->toggleable(),
                TextColumn::make('current_step_sequence')->label('Step')->placeholder('Complete'),
                TextColumn::make('waiting_for')
                    ->label('Waiting')
                    ->getStateUsing(fn (ApprovalRequest $record): ?string => static::waitingText($record))
                    ->color(fn (ApprovalRequest $record): ?string => ($record->waitingHours() ?? 0) >= app(ApprovalReminderService::class)->settingsFor($record->workflow)['after_hours'] ? 'danger' : null)
                    ->placeholder('—'),
                TextColumn::make('reminders_sent')
                    ->label('Reminders')
                    ->getStateUsing(fn (ApprovalRequest $record): ?string => static::remindersText($record))
                    ->badge()
                    ->color(fn (?string $state): string => str_contains((string) $state, 'escalated') ? 'danger' : 'warning')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ApprovalRequestStatus::labelFor($state))
                    ->color(fn (?string $state): string => ApprovalRequestStatus::colorFor($state)),
                TextColumn::make('submitted_at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->dateTime()->placeholder('Pending')->toggleable(),
            ])
            // Oldest pending first -- unlike a "new arrivals" queue, an
            // approval sitting unactioned the longest is the one most
            // likely to be blocking someone else's downstream work, so it
            // belongs at the top, not a freshly-submitted one.
            ->defaultSort('submitted_at', 'asc')
            ->filters([
                SelectFilter::make('status')->options(ApprovalRequestStatus::options()),
            ])
            ->recordActions([
                ViewAction::make()->modalHeading('Approval Details'),
                Action::make('approve')
                    ->color('success')
                    ->icon('heroicon-o-check')
                    ->schema([Textarea::make('reason')->label('Approval note')])
                    ->visible(fn (ApprovalRequest $record): bool => Auth::user() !== null
                        && app(ApprovalEngine::class)->canAct($record, Auth::user()))
                    ->action(function (ApprovalRequest $record, array $data): void {
                        // A subject may refuse the decision (e.g. overtime with too few minutes
                        // recorded, a finalised attendance month): the whole decision is rolled
                        // back and the reason shown, not an error page.
                        try {
                            app(ApprovalEngine::class)->approve($record, Auth::user(), $data['reason'] ?? null);
                            Notification::make()->success()->title('Approval recorded')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Could not approve')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-mark')
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->required()])
                    ->visible(fn (ApprovalRequest $record): bool => Auth::user() !== null
                        && app(ApprovalEngine::class)->canAct($record, Auth::user()))
                    ->action(function (ApprovalRequest $record, array $data): void {
                        try {
                            app(ApprovalEngine::class)->reject($record, Auth::user(), (string) $data['reason']);
                            Notification::make()->success()->title('Request rejected')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Could not reject')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('return_to_requester')
                    ->label('Return to requester')
                    ->color('info')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->modalDescription('The requester is asked to change the request and resubmit it. It then starts again from the first approval step.')
                    ->schema([Textarea::make('comment')->label('What needs to change')->required()->maxLength(2000)])
                    ->visible(fn (ApprovalRequest $record): bool => Auth::user() !== null
                        && app(ApprovalEngine::class)->canReturn($record, Auth::user()))
                    ->action(function (ApprovalRequest $record, array $data): void {
                        try {
                            app(ApprovalEngine::class)->returnToRequester($record, Auth::user(), (string) $data['comment']);
                            Notification::make()->success()->title('Returned to the requester for changes')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Could not return the request')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListApprovalRequests::route('/')];
    }
}
