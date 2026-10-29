<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Filament\Clusters\Configurations;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\ShiftResource\Pages\ListShifts;
use Webkul\Employee\Models\Shift;
use Webkul\Employee\Models\WorkSchedule;
use Webkul\Security\Services\CurrentCompany;

/**
 * Shifts of the active company (HR parity plan 3.4): General, Morning,
 * Evening, Night, ... with their hours, required minutes, unpaid break and
 * roster colour. Used by roster patterns and day changes on Attendance ->
 * Shift roster. HR only (ShiftPolicy); every save is validated by the model.
 */
class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $cluster = Configurations::class;

    protected static ?string $modelLabel = 'Shift';

    protected static ?string $navigationLabel = 'Shifts';

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): string
    {
        return 'Attendance';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', CurrentCompany::id() ?? 0);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(80)
                    ->placeholder('e.g. Night'),
                TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->maxLength(10)
                    ->regex('/^[A-Za-z0-9]{1,10}$/')
                    ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state)))
                    ->helperText('Shown in the roster grid, e.g. N.'),
                TimePicker::make('start_time')
                    ->label('Start')
                    ->seconds(false)
                    ->required(),
                TimePicker::make('end_time')
                    ->label('End')
                    ->seconds(false)
                    ->required()
                    ->helperText('An end before the start is an overnight shift (22:00–06:00), counted on the day it starts.'),
                TextInput::make('required_minutes')
                    ->label('Required minutes')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(1440)
                    ->default(450)
                    ->required()
                    ->helperText('Worked time expected per shift, after the unpaid break (8 h shift − 30 min break = 450).'),
                TextInput::make('break_minutes')
                    ->label('Unpaid break (minutes)')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(1439)
                    ->default(0)
                    ->helperText('Taken off the worked time when the employee did not check out for a break.'),
                TextInput::make('flexible_minutes')
                    ->label('Flexible window (minutes)')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(WorkSchedule::MaxFlexibleMinutes)
                    ->default(0),
                ColorPicker::make('color')
                    ->label('Roster colour')
                    ->default('#64748b')
                    ->regex('/^#[0-9a-fA-F]{6}$/')
                    ->required(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('A switched-off shift stays on rosters already made but cannot be picked again.'),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('code')->label('Code')->weight('bold'),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('hours')->label('Hours')->state(fn (Shift $record): string => $record->hours()),
                TextColumn::make('required_minutes')->label('Required')
                    ->formatStateUsing(fn ($state): string => intdiv((int) $state, 60).'h '.str_pad((string) ((int) $state % 60), 2, '0', STR_PAD_LEFT).'m'),
                TextColumn::make('break_minutes')->label('Unpaid break')
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? $state.' min' : '—'),
                TextColumn::make('flexible_minutes')->label('Flexible')
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? $state.' min' : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (Shift $record, array $data, EditAction $action): ?Model => static::guarded($action, fn (): Shift => tap($record)->update($data))),
                DeleteAction::make()
                    ->using(fn (Shift $record, DeleteAction $action): mixed => static::guarded($action, fn (): ?bool => $record->delete())),
            ])
            ->reorderable('sort')
            ->defaultSort('sort');
    }

    /** Shows a refused save (duplicate code, bad times, shift in use) as a notification and keeps the modal open. */
    public static function guarded(Action $action, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Not saved')->body((string) collect($e->errors())->flatten()->first())->send();
        }

        $action->halt();

        return null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShifts::route('/'),
        ];
    }
}
