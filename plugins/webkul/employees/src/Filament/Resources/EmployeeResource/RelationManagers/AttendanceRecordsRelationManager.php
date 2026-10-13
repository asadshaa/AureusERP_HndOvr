<?php

namespace Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Webkul\Employee\Enums\AttendanceSource;
use Webkul\Employee\Enums\AttendanceVerificationStatus;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Services\Attendance\AttendanceScheduleResolver;
use Webkul\Employee\Support\HrPermissions;

class AttendanceRecordsRelationManager extends RelationManager
{
    protected static string $relationship = 'attendanceRecords';

    protected static ?string $title = 'Attendance Records';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clock';

    protected string $view = 'employees::filament.resources.employee.relation-managers.attendance-records';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getMonthlySummaryForView(): array
    {
        $filterMonth = $this->tableFilters['month']['value'] ?? null;

        return static::getMonthlySummary($this->getOwnerRecord(), $filterMonth);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->can(HrPermissions::ManageAttendance)
            || $user->can(HrPermissions::ViewAttendance)
            || ($ownerRecord->user_id !== null && (int) $ownerRecord->user_id === (int) $user->id);
    }

    public static function getMonthlySummary(Model $employee, ?string $yearMonth = null): array
    {
        $target = $yearMonth ? Carbon::createFromFormat('Y-m', $yearMonth) : now();
        $startOfMonth = $target->copy()->startOfMonth()->toDateString();
        $endOfMonth = $target->copy()->endOfMonth()->toDateString();
        $monthLabel = $target->format('F Y');

        $records = $employee->attendanceRecords()
            ->whereBetween('attendance_date', [$startOfMonth, $endOfMonth])
            ->get()
            ->reject(fn ($r): bool => $r->attendance_date ? Carbon::parse($r->attendance_date)->isWeekend() : false);

        $present = $records->where('status', 'present')->count();
        $halfDay = $records->where('status', 'half_day')->count();
        $absent = $records->where('status', 'absent')->count();
        $leave = $records->where('status', 'leave')->count();
        $lateMinutes = (int) $records->sum('late_minutes');
        $earlyMinutes = (int) $records->sum('early_departure_minutes');
        $workedHours = (float) $records->sum('worked_hours');
        $overtimeHours = (float) $records->sum('overtime_hours');

        // Total working days in this month (Monday - Friday)
        $workingDays = 0;
        $day = $target->copy()->startOfMonth();
        $end = $target->copy()->endOfMonth();
        while ($day->lte($end)) {
            if (! $day->isWeekend()) {
                $workingDays++;
            }
            $day->addDay();
        }

        return [
            'month'          => $monthLabel,
            'present'        => $present,
            'half_day'       => $halfDay,
            'absent'         => $absent,
            'leave'          => $leave,
            'late_minutes'   => $lateMinutes,
            'early_minutes'  => $earlyMinutes,
            'worked_hours'   => $workedHours,
            'overtime_hours' => $overtimeHours,
            'working_days'   => $workingDays,
        ];
    }

    public function table(Table $table): Table
    {
        $employee = $this->getOwnerRecord();

        return $table
            ->defaultSort('attendance_date', 'desc')
            ->columns([
                TextColumn::make('attendance_date')->date()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('check_in')->dateTime()->placeholder('—'),
                TextColumn::make('check_out')->dateTime()->placeholder('—'),
                TextColumn::make('worked_hours')->numeric(decimalPlaces: 2)->label('Worked (h)'),
                TextColumn::make('late_minutes')->label('Late (min)')->sortable(),
                TextColumn::make('early_departure_minutes')->label('Early (min)')->sortable(),
                TextColumn::make('source')->badge()
                    ->formatStateUsing(fn (?string $state): string => AttendanceSource::tryFrom((string) $state)?->label() ?? (string) $state),
                TextColumn::make('verification_status')
                    ->label('Verification')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => AttendanceVerificationStatus::tryFrom((string) $state)?->getLabel())
                    ->color(fn (?string $state): ?string => AttendanceVerificationStatus::tryFrom((string) $state)?->getColor()),
            ])
            ->filters([
                SelectFilter::make('month')
                    ->label('Month')
                    ->options(function () use ($employee): array {
                        $dates = $employee->attendanceRecords()->pluck('attendance_date');
                        $months = $dates->map(fn ($d): string => Carbon::parse($d)->format('Y-m'))->unique()->sortDesc();
                        $current = now()->format('Y-m');
                        if (! $months->contains($current)) {
                            $months->prepend($current);
                        }

                        return $months->mapWithKeys(fn (string $ym): array => [$ym => Carbon::createFromFormat('Y-m', $ym)->format('F Y')])->all();
                    })
                    ->query(function (Builder $query, array $data): void {
                        if (! empty($data['value'])) {
                            $start = Carbon::createFromFormat('Y-m', $data['value'])->startOfMonth()->toDateString();
                            $end = Carbon::createFromFormat('Y-m', $data['value'])->endOfMonth()->toDateString();
                            $query->whereBetween('attendance_date', [$start, $end]);
                        }
                    }),
                SelectFilter::make('status')->options([
                    'present'  => 'Present',
                    'half_day' => 'Half Day',
                    'absent'   => 'Absent',
                    'leave'    => 'Leave',
                    'holiday'  => 'Holiday',
                    'remote'   => 'Remote',
                ]),
                SelectFilter::make('verification_status')
                    ->label('Verification')
                    ->options(collect(AttendanceVerificationStatus::cases())->mapWithKeys(fn (AttendanceVerificationStatus $case): array => [$case->value => $case->getLabel()])->all()),
            ])
            ->recordActions([
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
            ])
            ->headerActions([
                Action::make('export_csv')
                    ->label('Export Attendance (CSV)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn (): bool => (bool) (Auth::user()?->can(HrPermissions::ManageAttendance) || Auth::user()?->can(HrPermissions::ViewAttendance)))
                    ->modalHeading('Export Attendance Records')
                    ->modalDescription('Download attendance records for this employee. Weekends (Saturday & Sunday) are automatically excluded.')
                    ->modalSubmitActionLabel('Download CSV')
                    ->schema([
                        DatePicker::make('from_date')
                            ->label('From Date')
                            ->placeholder('Start date (optional)'),
                        DatePicker::make('to_date')
                            ->label('To Date')
                            ->placeholder('End date (optional)'),
                    ])
                    ->action(function (array $data, $livewire) {
                        $employee = $livewire->getOwnerRecord();
                        $query = $livewire->getFilteredTableQuery();

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

                        $slug = Str::slug($employee?->name ?? 'employee');
                        $fromPart = ! empty($data['from_date']) ? Carbon::parse($data['from_date'])->format('Ymd') : 'start';
                        $toPart = ! empty($data['to_date']) ? Carbon::parse($data['to_date'])->format('Ymd') : now()->format('Ymd');
                        $filename = "attendance-{$slug}-{$fromPart}-to-{$toPart}.csv";

                        return response()->streamDownload(function () use ($records, $employee) {
                            $handle = fopen('php://output', 'w');
                            fputcsv($handle, [
                                'Date',
                                'Day',
                                'Employee Name',
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
                                    $employee?->name ?? '',
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
            ]);
    }
}
