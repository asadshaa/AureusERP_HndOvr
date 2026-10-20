<?php

namespace Webkul\Employee\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Enums\AttendanceSource;
use Webkul\Employee\Enums\AttendanceVerificationStatus;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource\Pages\ManageAttendanceRecords;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\AttendanceScheduleResolver;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Support\Enums\NavigationGroup;

class AttendanceRecordResource extends Resource
{
    protected static ?string $model = AttendanceRecord::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Attendance;
    }

    public static function getNavigationLabel(): string
    {
        return 'Attendance Register';
    }

    public static function getModelLabel(): string
    {
        return 'attendance record';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('company_id')->default(fn (): ?int => Auth::user()?->default_company_id),
            Select::make('employee_id')
                ->relationship('employee', 'name', modifyQueryUsing: function (Builder $query): Builder {
                    $user = Auth::user();
                    $companyId = (int) $user?->default_company_id;
                    $visible = $user ? app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId) : collect();

                    return $query->where('company_id', $companyId)->whereIn('id', $visible);
                })
                ->required()->searchable()->preload(),
            DatePicker::make('attendance_date')->required()->native(false)
                ->minDate(fn (): string => now()->subYears(2)->toDateString())
                ->maxDate(fn (): string => now()->addYear()->toDateString()),
            DateTimePicker::make('scheduled_start')->seconds(false),
            DateTimePicker::make('scheduled_end')->seconds(false)->after('scheduled_start'),
            DateTimePicker::make('check_in')->seconds(false),
            // An overnight shift simply has a check-out on the next date; a check-out
            // before the check-in is always a data-entry mistake (it used to be saved
            // silently with 0 worked hours).
            DateTimePicker::make('check_out')->seconds(false)->after('check_in')
                ->validationMessages(['after' => 'The check-out must be after the check-in.']),
            TextInput::make('overtime_hours')->numeric()->minValue(0)->default(0),
            Select::make('status')->options([
                'present' => 'Present', 'half_day' => 'Half Day', 'absent' => 'Absent', 'leave' => 'Leave',
                'holiday' => 'Holiday', 'remote' => 'Remote',
            ])->default('present')->required(),
            // GPS / self-service rows are backed by verification evidence. They can
            // only be created by the check-in flow, never picked by hand, and the
            // label on an existing one cannot be changed.
            Select::make('source')
                ->options(fn (?AttendanceRecord $record): array => self::isEvidenceBacked($record)
                    ? AttendanceSource::options()
                    : Arr::except(AttendanceSource::options(), AttendanceSource::evidenceBacked()))
                ->disabled(fn (?AttendanceRecord $record): bool => self::isEvidenceBacked($record))
                ->dehydrated(fn (?AttendanceRecord $record): bool => ! self::isEvidenceBacked($record))
                ->default('manual')
                ->required(),
            TextInput::make('source_reference')->maxLength(255)
                ->disabled(fn (?AttendanceRecord $record): bool => self::isEvidenceBacked($record))
                ->dehydrated(fn (?AttendanceRecord $record): bool => ! self::isEvidenceBacked($record)),
            Textarea::make('correction_reason')
                ->label('Reason for correcting this GPS-verified attendance')
                ->helperText('Required. Recorded with the before/after times in the verification audit trail.')
                ->minLength(10)
                ->visible(fn (?AttendanceRecord $record): bool => self::isEvidenceBacked($record))
                ->required(fn (?AttendanceRecord $record): bool => self::isEvidenceBacked($record))
                ->columnSpanFull(),
            Textarea::make('notes')->columnSpanFull(),
        ])->columns(2);
    }

    private static function isEvidenceBacked(?AttendanceRecord $record): bool
    {
        return $record !== null && in_array($record->source, AttendanceSource::evidenceBacked(), true);
    }

    /** A user can never edit or delete their OWN evidence-backed attendance. */
    private static function isOwnEvidenceBacked(AttendanceRecord $record): bool
    {
        return self::isEvidenceBacked($record)
            && (int) $record->employee?->user_id === (int) Auth::id();
    }

    private static function canReviewRecord(AttendanceRecord $record): bool
    {
        if ($record->verification_status !== AttendanceVerificationStatus::NeedsReview->value) {
            return false;
        }

        $pending = $record->verifications()->where('review_status', 'pending')->first();

        return $pending !== null && (bool) Auth::user()?->can('review', $pending);
    }

    /**
     * Applies an edit. Evidence-backed rows route their times through the
     * service so the change is audited with a reason; everything else keeps
     * the original plain update.
     *
     * @param  array<string, mixed>  $data
     */
    private static function applyEdit(AttendanceRecord $record, array $data): AttendanceRecord
    {
        $reason = (string) Arr::pull($data, 'correction_reason', '');

        if (! self::isEvidenceBacked($record)) {
            $times = Arr::only($data, ['check_in', 'check_out']);
            $hasTimeChange = false;
            foreach ($times as $key => $value) {
                $current = $record->{$key}?->format('Y-m-d H:i:s');
                if (($value !== null ? Carbon::parse($value)->format('Y-m-d H:i:s') : null) !== $current) {
                    $hasTimeChange = true;
                    break;
                }
            }

            $record->update($data);

            if ($hasTimeChange && Auth::check()) {
                $record->loadMissing('employee.user');
                if ($record->employee?->user && (int) $record->employee->user_id !== (int) Auth::id()) {
                    try {
                        $actor = Auth::user();
                        $dateStr = $record->attendance_date ? Carbon::parse($record->attendance_date)->format('d M Y') : 'your record';
                        $timesList = [];
                        if ($record->check_in) {
                            $timesList[] = 'Check-in: '.$record->check_in->format('H:i');
                        }
                        if ($record->check_out) {
                            $timesList[] = 'Check-out: '.$record->check_out->format('H:i');
                        }
                        $timeStr = ! empty($timesList) ? ' ('.implode(', ', $timesList).')' : '';

                        $title = 'HR updated your attendance time';
                        $body = "HR ({$actor->name}) updated your attendance time for {$dateStr}{$timeStr}.".($reason ? " Reason: {$reason}" : '');

                        $notification = Notification::make()
                            ->info()
                            ->icon('heroicon-o-clock')
                            ->title($title)
                            ->body($body);

                        $record->employee->user->notifyNow($notification->toDatabase());
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }

            return $record;
        }

        $times = Arr::only($data, ['check_in', 'check_out']);
        $others = Arr::except($data, ['check_in', 'check_out']);

        $changed = array_filter($times, function ($value, $key) use ($record): bool {
            $current = $record->{$key}?->format('Y-m-d H:i:s');

            return ($value !== null ? Carbon::parse($value)->format('Y-m-d H:i:s') : null) !== $current;
        }, ARRAY_FILTER_USE_BOTH);

        if ($changed !== []) {
            app(GeofencedAttendanceService::class)->recordHrCorrection($record, Auth::user(), $changed, $reason);
            $record->refresh();
        }

        if ($others !== []) {
            $record->update($others);
        }

        return $record;
    }

    private static function attempt(callable $callback, string $successTitle): void
    {
        try {
            $callback();
            Notification::make()->success()->title($successTitle)->send();
        } catch (AuthorizationException|InvalidArgumentException|RuntimeException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
        }
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('attendance_date')->date()->sortable(),
            TextColumn::make('employee.name')->searchable()->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('check_in')->dateTime()->placeholder('—'),
            TextColumn::make('check_out')->dateTime()->placeholder('—'),
            TextColumn::make('worked_hours')->numeric(decimalPlaces: 2),
            TextColumn::make('late_minutes')->label('Late (minutes)')->sortable(),
            TextColumn::make('early_departure_minutes')->label('Early (minutes)')->sortable(),
            TextColumn::make('overtime_hours')->numeric(decimalPlaces: 2),
            TextColumn::make('source')->badge()
                ->formatStateUsing(fn (?string $state): string => AttendanceSource::tryFrom((string) $state)?->label() ?? (string) $state),
            TextColumn::make('verification_status')
                ->label('Verification')
                ->badge()
                ->placeholder('—')
                ->formatStateUsing(fn (?string $state): ?string => AttendanceVerificationStatus::tryFrom((string) $state)?->getLabel())
                ->color(fn (?string $state): ?string => AttendanceVerificationStatus::tryFrom((string) $state)?->getColor()),
        ])->filters([
            SelectFilter::make('employee_id')
                ->label('Employee')
                ->relationship('employee', 'name', modifyQueryUsing: function (Builder $query): Builder {
                    $user = Auth::user();
                    $companyId = (int) $user?->default_company_id;
                    $visible = $user ? app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId) : collect();

                    return $query->where('company_id', $companyId)->whereIn('id', $visible);
                })
                ->searchable()
                ->preload(),
            SelectFilter::make('status')->options([
                'present' => 'Present', 'half_day' => 'Half Day', 'absent' => 'Absent', 'leave' => 'Leave',
                'holiday' => 'Holiday', 'remote' => 'Remote',
            ]),
            SelectFilter::make('verification_status')
                ->label('Verification')
                ->options(collect(AttendanceVerificationStatus::cases())->mapWithKeys(fn (AttendanceVerificationStatus $case): array => [$case->value => $case->getLabel()])->all()),
            SelectFilter::make('source')->options(AttendanceSource::options()),
        ])->recordActions([
            Action::make('verification_evidence')
                ->label('Verification')
                ->icon('heroicon-o-map-pin')
                ->color('gray')
                ->visible(fn (AttendanceRecord $record): bool => $record->verification_status !== null
                    && (bool) Auth::user()?->can('viewAny', AttendanceVerification::class))
                ->modalHeading('Verification evidence')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn (AttendanceRecord $record) => view('employees::filament.components.attendance-verification-evidence', [
                    'verifications' => $record->verifications()->with(['workLocation', 'reviewer', 'user'])->orderBy('server_recorded_at')->orderBy('id')->get()
                        ->filter(fn (AttendanceVerification $verification): bool => (bool) Auth::user()?->can('view', $verification))
                        ->values(),
                    'timezone' => app(AttendanceScheduleResolver::class)->timezoneFor($record->employee),
                ])),
            Action::make('approve_verification')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (AttendanceRecord $record): bool => self::canReviewRecord($record))
                ->schema([Textarea::make('note')->label('Review note')->required()])
                ->action(fn (AttendanceRecord $record, array $data) => self::attempt(
                    fn () => app(GeofencedAttendanceService::class)->reviewVerification($record, Auth::user(), true, (string) $data['note']),
                    'Verification approved',
                )),
            Action::make('reject_verification')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (AttendanceRecord $record): bool => self::canReviewRecord($record))
                ->schema([Textarea::make('note')->label('Review note')->required()])
                ->action(fn (AttendanceRecord $record, array $data) => self::attempt(
                    fn () => app(GeofencedAttendanceService::class)->reviewVerification($record, Auth::user(), false, (string) $data['note']),
                    'Verification rejected. The recorded times were not changed.',
                )),
            Action::make('request_time_change')
                ->label('Request Time Change')
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->visible(fn (AttendanceRecord $record): bool => (int) $record->employee?->user_id === (int) Auth::id()
                    || (bool) Auth::user()?->can(HrPermissions::ManageAttendance))
                ->schema([
                    DateTimePicker::make('requested_check_in')->seconds(false)
                        ->default(fn (AttendanceRecord $record) => $record->check_in),
                    DateTimePicker::make('requested_check_out')->seconds(false)
                        ->default(fn (AttendanceRecord $record) => $record->check_out),
                    Textarea::make('reason')
                        ->label('Reason for the change (optional)')
                        ->placeholder('e.g. Traffic delay, badge issue, forgot to clock in'),
                ])
                ->action(function (AttendanceRecord $record, array $data): void {
                    try {
                        app(EmployeeRequestService::class)->requestAttendanceTimeChange(
                            $record,
                            Auth::user(),
                            [
                                'check_in'  => $data['requested_check_in'] ?? null,
                                'check_out' => $data['requested_check_out'] ?? null,
                            ],
                            $data['reason'] ?? null,
                        );
                        Notification::make()->success()->title('Time change request submitted to your line manager')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Could not submit time change request')->body($e->getMessage())->send();
                    }
                }),
            EditAction::make()
                ->visible(fn (AttendanceRecord $record): bool => ! self::isOwnEvidenceBacked($record))
                ->using(function (AttendanceRecord $record, array $data, EditAction $action): AttendanceRecord {
                    try {
                        return self::applyEdit($record, $data);
                    } catch (AuthorizationException|InvalidArgumentException|RuntimeException $e) {
                        Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
                        $action->halt();
                    }
                }),
            // Plain delete for manual/imported days (unchanged behaviour).
            DeleteAction::make()
                ->visible(fn (AttendanceRecord $record): bool => ! self::isEvidenceBacked($record)),
            // GPS / self-service days: a reason is required and the deletion is
            // audited (the verification evidence itself is kept).
            Action::make('delete_verified_record')
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (AttendanceRecord $record): bool => self::isEvidenceBacked($record)
                    && ! self::isOwnEvidenceBacked($record)
                    && (bool) Auth::user()?->can(HrPermissions::ManageAttendance))
                ->requiresConfirmation()
                ->modalHeading('Delete GPS-verified attendance')
                ->modalDescription('The attendance day is removed, but its verification evidence and this deletion (with your reason) stay in the audit trail.')
                ->schema([
                    Textarea::make('reason')->label('Reason for deleting')->required()->minLength(10),
                ])
                ->action(fn (AttendanceRecord $record, array $data) => self::attempt(
                    fn () => app(GeofencedAttendanceService::class)->deleteRecord($record, Auth::user(), (string) $data['reason']),
                    'Attendance deleted. The evidence and your reason were kept in the audit trail.',
                )),
        ])
            ->headerActions([
                CreateAction::make()
                    ->after(function (AttendanceRecord $record): void {
                        if (Auth::check()) {
                            $record->loadMissing('employee.user');
                            if ($record->employee?->user && (int) $record->employee->user_id !== (int) Auth::id()) {
                                try {
                                    $actor = Auth::user();
                                    $dateStr = $record->attendance_date ? Carbon::parse($record->attendance_date)->format('d M Y') : 'your record';
                                    $timesList = [];
                                    if ($record->check_in) {
                                        $timesList[] = 'Check-in: '.$record->check_in->format('H:i');
                                    }
                                    if ($record->check_out) {
                                        $timesList[] = 'Check-out: '.$record->check_out->format('H:i');
                                    }
                                    $timeStr = ! empty($timesList) ? ' ('.implode(', ', $timesList).')' : '';

                                    $notification = Notification::make()
                                        ->info()
                                        ->icon('heroicon-o-clock')
                                        ->title('HR added your attendance record')
                                        ->body("HR ({$actor->name}) added an attendance record for {$dateStr}{$timeStr}.");

                                    $record->employee->user->notifyNow($notification->toDatabase());
                                } catch (\Throwable $e) {
                                    report($e);
                                }
                            }
                        }
                    }),
                Action::make('export_csv')
                    ->label('Export Attendance (CSV)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn (): bool => (bool) (Auth::user()?->can(HrPermissions::ManageAttendance) || Auth::user()?->can(HrPermissions::ViewAttendance)))
                    ->modalHeading('Export Attendance Records')
                    ->modalDescription('Download attendance records as CSV. Weekends (Saturday & Sunday) are automatically excluded.')
                    ->modalSubmitActionLabel('Download CSV')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Employee')
                            ->placeholder('All Employees (or select specific)')
                            ->options(function () {
                                $user = Auth::user();
                                $companyId = (int) $user?->default_company_id;
                                $visible = $user ? app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId) : collect();

                                return Employee::query()
                                    ->where('company_id', $companyId)
                                    ->whereIn('id', $visible)
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->preload(),
                        DatePicker::make('from_date')
                            ->label('From Date')
                            ->placeholder('Start date (optional)'),
                        DatePicker::make('to_date')
                            ->label('To Date')
                            ->placeholder('End date (optional)'),
                    ])
                    ->action(function (array $data, $livewire) {
                        $query = $livewire->getFilteredTableQuery()->with(['employee.department']);

                        if (! empty($data['employee_id'])) {
                            $query->where('employee_id', $data['employee_id']);
                        }

                        if (! empty($data['from_date'])) {
                            $query->whereDate('attendance_date', '>=', $data['from_date']);
                        }

                        if (! empty($data['to_date'])) {
                            $query->whereDate('attendance_date', '<=', $data['to_date']);
                        }

                        $records = $query->orderBy('attendance_date', 'desc')->get();

                        // Weekends (Saturday & Sunday) are strictly excluded
                        $records = $records->reject(function ($r): bool {
                            return $r->attendance_date ? Carbon::parse($r->attendance_date)->isWeekend() : false;
                        });

                        $empName = ! empty($data['employee_id'])
                            ? Employee::find($data['employee_id'])?->name
                            : null;

                        $prefix = $empName ? 'attendance-'.Str::slug($empName) : 'attendance-register';
                        $fromPart = ! empty($data['from_date']) ? Carbon::parse($data['from_date'])->format('Ymd') : 'start';
                        $toPart = ! empty($data['to_date']) ? Carbon::parse($data['to_date'])->format('Ymd') : now()->format('Ymd');
                        $filename = "{$prefix}-{$fromPart}-to-{$toPart}.csv";

                        return response()->streamDownload(function () use ($records) {
                            $handle = fopen('php://output', 'w');
                            fputcsv($handle, [
                                'Date',
                                'Day',
                                'Employee Name',
                                'Department',
                                'Check In',
                                'Check Out',
                                'Worked Hours',
                                'Late Minutes',
                                'Early Minutes',
                                'Overtime Hours',
                                'Status',
                                'Verification',
                                'Source',
                            ]);

                            foreach ($records as $r) {
                                $dt = $r->attendance_date ? Carbon::parse($r->attendance_date) : null;
                                fputcsv($handle, [
                                    $dt ? $dt->format('Y-m-d') : '',
                                    $dt ? $dt->format('l') : '',
                                    $r->employee?->name ?? '',
                                    $r->employee?->department?->name ?? '',
                                    $r->check_in ? Carbon::parse($r->check_in)->format('Y-m-d H:i:s') : '',
                                    $r->check_out ? Carbon::parse($r->check_out)->format('Y-m-d H:i:s') : '',
                                    number_format((float) $r->worked_hours, 2),
                                    (int) $r->late_minutes,
                                    (int) $r->early_departure_minutes,
                                    number_format((float) $r->overtime_hours, 2),
                                    ucfirst((string) $r->status),
                                    $r->verification_status,
                                    $r->source,
                                ]);
                            }

                            fclose($handle);
                        }, $filename, [
                            'Content-Type' => 'text/csv',
                        ]);
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_approve_verifications')
                        ->label('Approve Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Approve selected attendance records')
                        ->modalDescription('Only records that require review and match your permissions will be approved.')
                        ->schema([
                            Textarea::make('note')->label('Review note')->default('Bulk approved by HR')->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $user = Auth::user();
                            $service = app(GeofencedAttendanceService::class);
                            $approvedCount = 0;
                            $skippedCount = 0;

                            foreach ($records as $record) {
                                if (! self::canReviewRecord($record)) {
                                    $skippedCount++;

                                    continue;
                                }

                                try {
                                    $service->reviewVerification($record, $user, true, (string) $data['note']);
                                    $approvedCount++;
                                } catch (\Throwable) {
                                    $skippedCount++;
                                }
                            }

                            if ($approvedCount > 0) {
                                Notification::make()
                                    ->success()
                                    ->title("Approved {$approvedCount} record(s)".($skippedCount > 0 ? " ({$skippedCount} skipped)" : ''))
                                    ->send();
                            } else {
                                Notification::make()
                                    ->warning()
                                    ->title('No records were approved')
                                    ->body($skippedCount > 0 ? "{$skippedCount} record(s) could not be reviewed." : '')
                                    ->send();
                            }
                        })
                        ->visible(fn (): bool => (bool) (Auth::user()?->can(HrPermissions::ManageAttendance) || Auth::user()?->can(HrPermissions::ReviewAttendanceVerifications))),

                    BulkAction::make('bulk_reject_verifications')
                        ->label('Reject Selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Reject selected attendance records')
                        ->modalDescription('Only records that require review and match your permissions will be rejected.')
                        ->schema([
                            Textarea::make('note')->label('Review note')->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $user = Auth::user();
                            $service = app(GeofencedAttendanceService::class);
                            $rejectedCount = 0;
                            $skippedCount = 0;

                            foreach ($records as $record) {
                                if (! self::canReviewRecord($record)) {
                                    $skippedCount++;

                                    continue;
                                }

                                try {
                                    $service->reviewVerification($record, $user, false, (string) $data['note']);
                                    $rejectedCount++;
                                } catch (\Throwable) {
                                    $skippedCount++;
                                }
                            }

                            if ($rejectedCount > 0) {
                                Notification::make()
                                    ->success()
                                    ->title("Rejected {$rejectedCount} record(s)".($skippedCount > 0 ? " ({$skippedCount} skipped)" : ''))
                                    ->send();
                            } else {
                                Notification::make()
                                    ->warning()
                                    ->title('No records were rejected')
                                    ->body($skippedCount > 0 ? "{$skippedCount} record(s) could not be reviewed." : '')
                                    ->send();
                            }
                        })
                        ->visible(fn (): bool => (bool) (Auth::user()?->can(HrPermissions::ManageAttendance) || Auth::user()?->can(HrPermissions::ReviewAttendanceVerifications))),
                ]),
            ])
            ->defaultSort('attendance_date', 'desc');
    }

    /**
     * Company scoping alone let any user holding hr_manage_attendance see
     * every employee's attendance records. Attendance is personal data;
     * scope to the requesting user's HR hierarchy, same as the other
     * employee-data lists. Users granted hr_view_all_records still see
     * everything, via HrHierarchyService's own bypass.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $companyId = (int) $user?->default_company_id;
        $visible = $user ? app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId) : collect();

        return parent::getEloquentQuery()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $visible);
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user !== null && ($user->can(HrPermissions::ManageAttendance) || $user->can(HrPermissions::ViewAttendance));
    }

    public static function canCreate(): bool
    {
        return (bool) Auth::user()?->can(HrPermissions::ManageAttendance);
    }

    public static function canEdit(Model $record): bool
    {
        return (bool) Auth::user()?->can(HrPermissions::ManageAttendance);
    }

    public static function canDelete(Model $record): bool
    {
        return (bool) Auth::user()?->can(HrPermissions::ManageAttendance);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAttendanceRecords::route('/')];
    }
}
