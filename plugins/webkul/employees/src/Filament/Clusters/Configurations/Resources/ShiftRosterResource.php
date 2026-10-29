<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Webkul\Employee\Filament\Clusters\Configurations;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\ShiftRosterResource\Pages\ListShiftRosters;
use Webkul\Employee\Models\Shift;
use Webkul\Employee\Models\ShiftRoster;
use Webkul\Security\Services\CurrentCompany;

/**
 * Rotating roster patterns of the active company (HR parity plan 3.4):
 * ordered steps of a shift (or Off) for a number of days, repeated from
 * each assignment's start date. Assigned to employees or departments on
 * Attendance -> Shift roster. HR only (ShiftRosterPolicy).
 */
class ShiftRosterResource extends Resource
{
    protected static ?string $model = ShiftRoster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $cluster = Configurations::class;

    protected static ?string $modelLabel = 'Roster pattern';

    protected static ?string $navigationLabel = 'Roster patterns';

    protected static ?int $navigationSort = 51;

    /** @var array<int, array<int, string>> company id => shift id => code, for the table */
    private static array $codes = [];

    public static function getNavigationGroup(): string
    {
        return 'Attendance';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', CurrentCompany::id() ?? 0)->withCount('assignments');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(120)
                    ->placeholder('e.g. Warehouse 3 shifts'),
                Repeater::make('pattern')
                    ->label('Pattern (repeats from the start date)')
                    ->schema([
                        Select::make('shift_id')
                            ->label('Shift')
                            ->placeholder('Off (day off)')
                            ->options(fn (): array => Shift::query()->where('company_id', CurrentCompany::id() ?? 0)->active()->orderBy('sort')->orderBy('name')->get()
                                ->mapWithKeys(fn (Shift $shift): array => [(int) $shift->id => $shift->label()])->all()),
                        TextInput::make('days')
                            ->label('Days')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(ShiftRoster::MaxStepDays)
                            ->default(5)
                            ->required(),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->reorderable()
                    ->addActionLabel('Add step')
                    ->default([['shift_id' => null, 'days' => 5]])
                    ->columnSpanFull(),
                TextInput::make('description')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('A switched-off pattern keeps its existing assignments but cannot be assigned again.'),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('pattern')->label('Pattern')
                    ->state(fn (ShiftRoster $record): string => $record->label(static::codes((int) $record->company_id)))
                    ->wrap(),
                TextColumn::make('cycle_days')->label('Cycle')->suffix(' days'),
                TextColumn::make('assignments_count')->label('Assignments'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (ShiftRoster $record, array $data, EditAction $action): ?Model => ShiftResource::guarded($action, fn (): ShiftRoster => tap($record)->update($data))),
                DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    /** @return array<int, string> */
    public static function codes(int $companyId): array
    {
        return static::$codes[$companyId] ??= Shift::query()->where('company_id', $companyId)->pluck('code', 'id')->map(fn ($code): string => (string) $code)->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShiftRosters::route('/'),
        ];
    }
}
