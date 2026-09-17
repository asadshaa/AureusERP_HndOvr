<?php

namespace Webkul\Employee\Filament\Resources;

use Filament\Actions\Action;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource\Pages\ManageAttendanceRecords;
use Webkul\Employee\Models\AttendanceRecord;
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
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Attendance';
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
            DatePicker::make('attendance_date')->required()->native(false),
            DateTimePicker::make('scheduled_start')->seconds(false),
            DateTimePicker::make('scheduled_end')->seconds(false),
            DateTimePicker::make('check_in')->seconds(false),
            DateTimePicker::make('check_out')->seconds(false),
            TextInput::make('overtime_hours')->numeric()->minValue(0)->default(0),
            Select::make('status')->options([
                'present' => 'Present', 'absent' => 'Absent', 'leave' => 'Leave',
                'holiday' => 'Holiday', 'remote' => 'Remote',
            ])->default('present')->required(),
            Select::make('source')->options([
                'manual' => 'Manual', 'import' => 'Import', 'biometric' => 'Biometric', 'api' => 'API',
            ])->default('manual')->required(),
            TextInput::make('source_reference')->maxLength(255),
            Textarea::make('notes')->columnSpanFull(),
        ])->columns(2);
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
            TextColumn::make('source')->badge(),
        ])->filters([
            SelectFilter::make('status')->options([
                'present' => 'Present', 'absent' => 'Absent', 'leave' => 'Leave',
                'holiday' => 'Holiday', 'remote' => 'Remote',
            ]),
        ])->recordActions([
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
                    Textarea::make('reason')->label('Reason for the change')->required(),
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
            EditAction::make(),
            DeleteAction::make(),
        ])
            ->headerActions([CreateAction::make()]);
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

    public static function getPages(): array
    {
        return ['index' => ManageAttendanceRecords::route('/')];
    }
}
