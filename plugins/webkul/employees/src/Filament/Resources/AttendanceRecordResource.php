<?php

namespace Webkul\Employee\Filament\Resources;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource\Pages\ManageAttendanceRecords;
use Webkul\Employee\Models\AttendanceRecord;
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
                ->relationship('employee', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', Auth::user()?->default_company_id))
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
        ])->recordActions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
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
