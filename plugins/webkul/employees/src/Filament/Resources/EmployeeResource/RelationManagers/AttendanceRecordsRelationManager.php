<?php

namespace Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
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

    public function isReadOnly(): bool
    {
        return true;
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

    public function table(Table $table): Table
    {
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
            ]);
    }
}
