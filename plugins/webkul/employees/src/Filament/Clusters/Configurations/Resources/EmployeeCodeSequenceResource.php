<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources;

use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Employee\Filament\Clusters\Configurations;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\EmployeeCodeSequenceResource\Pages\ListEmployeeCodeSequences;
use Webkul\Employee\Models\EmployeeCodeSequence;
use Webkul\Security\Services\CurrentCompany;

/**
 * Employee code format of the active company: PREFIX-0001. New employees
 * without a code get the next one; existing codes are never changed.
 */
class EmployeeCodeSequenceResource extends Resource
{
    protected static ?string $model = EmployeeCodeSequence::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $cluster = Configurations::class;

    protected static ?string $modelLabel = 'Employee code';

    protected static ?string $navigationLabel = 'Employee codes';

    public static function getNavigationGroup(): string
    {
        return __('employees::filament/clusters/configurations/resources/departure-reason.navigation.group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', CurrentCompany::id() ?? 0);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('prefix')
                    ->required()
                    ->maxLength(10)
                    ->regex('/^[A-Za-z0-9]{1,10}$/')
                    ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state))
                    ->helperText('Letters or digits, e.g. TII. Codes look like TII-0001.'),
                TextInput::make('padding')
                    ->label('Digits')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(8)
                    ->required(),
                TextInput::make('next_number')
                    ->label('Next number')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText('Numbers already used in this company are skipped.'),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')->label('Company'),
                TextColumn::make('prefix'),
                TextColumn::make('padding')->label('Digits'),
                TextColumn::make('next_number')->label('Next number'),
                TextColumn::make('example')
                    ->label('Next code')
                    ->state(fn (EmployeeCodeSequence $record): string => $record->format($record->next_number)),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeCodeSequences::route('/'),
        ];
    }
}
