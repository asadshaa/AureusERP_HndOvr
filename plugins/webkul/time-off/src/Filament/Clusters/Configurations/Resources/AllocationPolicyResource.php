<?php

namespace Webkul\TimeOff\Filament\Clusters\Configurations\Resources;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;
use Webkul\Security\Filament\Concerns\ScopedToActiveCompany;
use Webkul\Security\Services\CurrentCompany;
use Webkul\TimeOff\Filament\Clusters\Configurations;
use Webkul\TimeOff\Filament\Clusters\Configurations\Resources\AllocationPolicyResource\Pages\ListAllocationPolicies;
use Webkul\TimeOff\Models\LeaveAllocationPolicy;

/**
 * Time Off -> Configuration -> Yearly allocation: per leave type of the
 * active company, the days granted every 1 January and the carry-forward
 * cap used by time-off:yearly-allocation. Same permissions as the leave
 * types screen.
 */
class AllocationPolicyResource extends Resource
{
    use ScopedToActiveCompany;

    protected static ?string $model = LeaveAllocationPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $cluster = Configurations::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'yearly-allocation';

    public static function getModelLabel(): string
    {
        return 'Yearly allocation';
    }

    public static function getNavigationLabel(): string
    {
        return 'Yearly allocation';
    }

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->can('view_any_time_off_leave::type');
    }

    public static function canCreate(): bool
    {
        return (bool) Auth::user()?->can('update_time_off_leave::type');
    }

    public static function canEdit(Model $record): bool
    {
        return static::companyAllowsChange($record) && (bool) Auth::user()?->can('update_time_off_leave::type');
    }

    public static function canDelete(Model $record): bool
    {
        return static::companyAllowsChange($record) && (bool) Auth::user()?->can('update_time_off_leave::type');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('leave_type_id')
                    ->label('Leave type')
                    ->relationship(
                        'leaveType',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query
                            ->where('is_active', true)
                            ->where(fn (Builder $query): Builder => $query->where('company_id', CurrentCompany::id())->orWhereNull('company_id')),
                    )
                    ->unique(
                        table: 'time_off_allocation_policies',
                        column: 'leave_type_id',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('company_id', CurrentCompany::id()),
                    )
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('annual_days')
                    ->label('Days granted each year')
                    ->helperText('Given on 1 January; joiners get a share by months left in the year.')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(365)
                    ->step(0.5)
                    ->default(0)
                    ->required(),
                TextInput::make('carry_forward_cap')
                    ->label('Carry forward at most (days)')
                    ->helperText('Unused days of last year added to the new allocation, up to this cap. 0 = nothing carries forward.')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(365)
                    ->step(0.5)
                    ->default(0)
                    ->required(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('leaveType.name')
                    ->label('Leave type')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('annual_days')
                    ->label('Days each year')
                    ->numeric(decimalPlaces: 1)
                    ->sortable(),
                TextColumn::make('carry_forward_cap')
                    ->label('Carry-forward cap')
                    ->numeric(decimalPlaces: 1)
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No yearly allocation policy')
            ->emptyStateDescription('Running time-off:yearly-allocation adds Annual 12 (carry up to 6), Sick 8 and Casual 5 for this company\'s own leave types.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAllocationPolicies::route('/'),
        ];
    }
}
