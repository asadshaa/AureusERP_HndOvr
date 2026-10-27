<?php

namespace Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Security\Services\CurrentCompany;

/**
 * Additional, temporary or approved-remote-day workplaces for an employee,
 * on top of their primary Employee.work_location_id. A "Home" assignment
 * for a date range is how an approved remote day is expressed.
 */
class WorkLocationAssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'workLocationAssignments';

    protected static ?string $title = 'Additional workplaces';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('work_location_id')
                ->label('Workplace')
                ->relationship(
                    'workLocation',
                    'name',
                    modifyQueryUsing: fn (Builder $query): Builder => $query
                        ->where('company_id', $this->getOwnerRecord()->company_id)
                        ->where('is_active', true),
                )
                ->searchable()
                ->preload()
                ->required(),
            DatePicker::make('valid_from')->native(false),
            DatePicker::make('valid_until')->native(false)->afterOrEqual('valid_from'),
            TextInput::make('reason')
                ->maxLength(255)
                ->helperText('Optional. For example: "Approved work from home", "Client site - Q4 audit".')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('workLocation.name')->label('Workplace'),
                TextColumn::make('workLocation.location_type')->label('Type')->badge(),
                TextColumn::make('valid_from')->date()->placeholder('Open'),
                TextColumn::make('valid_until')->date()->placeholder('Open'),
                TextColumn::make('reason')->limit(40),
                TextColumn::make('assigner.name')->label('Assigned by')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()->visible(fn (): bool => $this->canManageOwner()),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => $this->canManageOwner()),
                DeleteAction::make()->visible(fn (): bool => $this->canManageOwner()),
            ]);
    }

    /** Server-side: the person editing must be able to manage this employee (company + hierarchy). */
    private function canManageOwner(): bool
    {
        $user = Auth::user();
        $employee = $this->getOwnerRecord();

        return $user !== null
            && $employee instanceof Employee
            && (int) $employee->company_id === CurrentCompany::id()
            && $user->can('update_employee_employee')
            && app(HrHierarchyService::class)->canManage($user, $employee);
    }

    public function isReadOnly(): bool
    {
        return ! $this->canManageOwner();
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('view_employee_employee') ?? false;
    }
}
