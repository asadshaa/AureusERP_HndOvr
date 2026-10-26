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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Filament\Resources\ApprovalRequestResource\Pages\ListApprovalRequests;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;
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
        return parent::getEloquentQuery()
            ->where('company_id', Auth::user()?->default_company_id)
            ->with(['workflow', 'requester', 'decisions.actor', 'subject']);
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
                                        ->icon('heroicon-o-user-circle'),
                                    TextEntry::make('decisions_history')
                                        ->label('Decision History')
                                        ->getStateUsing(function (ApprovalRequest $record): string {
                                            $decisions = $record->decisions;
                                            if ($decisions->isEmpty()) {
                                                return 'No decisions recorded yet.';
                                            }

                                            return $decisions->map(function ($d): string {
                                                $who = $d->actor?->name ?? 'User #'.$d->actor_id;
                                                $action = strtoupper($d->decision);
                                                $date = $d->created_at?->format('d M Y, h:i A') ?? '';
                                                $note = $d->reason ? " (Note: {$d->reason})" : '';

                                                return "• [{$action}] by {$who} on {$date}{$note}";
                                            })->join("\n");
                                        })
                                        ->extraAttributes(['style' => 'white-space: pre-line;'])
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
                                        ->color(fn (string $state): string => match ($state) {
                                            'approved' => 'success',
                                            'rejected' => 'danger',
                                            default    => 'warning',
                                        }),
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
                                $att = AttendanceRecord::find($payload['attendance_record_id']);
                                $dateStr = $att?->attendance_date?->toDateString();
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
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', default => 'warning',
                }),
                TextColumn::make('submitted_at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->dateTime()->placeholder('Pending')->toggleable(),
            ])
            // Oldest pending first -- unlike a "new arrivals" queue, an
            // approval sitting unactioned the longest is the one most
            // likely to be blocking someone else's downstream work, so it
            // belongs at the top, not a freshly-submitted one.
            ->defaultSort('submitted_at', 'asc')
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected',
                ]),
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
                        app(ApprovalEngine::class)->approve($record, Auth::user(), $data['reason'] ?? null);
                        Notification::make()->success()->title('Approval recorded')->send();
                    }),
                Action::make('reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-mark')
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->required()])
                    ->visible(fn (ApprovalRequest $record): bool => Auth::user() !== null
                        && app(ApprovalEngine::class)->canAct($record, Auth::user()))
                    ->action(function (ApprovalRequest $record, array $data): void {
                        app(ApprovalEngine::class)->reject($record, Auth::user(), (string) $data['reason']);
                        Notification::make()->success()->title('Request rejected')->send();
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
