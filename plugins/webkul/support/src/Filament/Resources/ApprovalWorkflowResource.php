<?php

namespace Webkul\Support\Filament\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Filament\Resources\ApprovalWorkflowResource\Pages\CreateApprovalWorkflow;
use Webkul\Support\Filament\Resources\ApprovalWorkflowResource\Pages\EditApprovalWorkflow;
use Webkul\Support\Filament\Resources\ApprovalWorkflowResource\Pages\ListApprovalWorkflows;
use Webkul\Support\Models\ApprovalWorkflow;

class ApprovalWorkflowResource extends Resource
{
    protected static ?string $model = ApprovalWorkflow::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 80;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Setting;
    }

    public static function getNavigationLabel(): string
    {
        return 'Approval Workflows';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function form(Schema $schema): Schema
    {
        $companyId = (int) Auth::user()?->default_company_id;

        return $schema->components([
            Section::make('Workflow')->columns(2)->schema([
                Select::make('company_id')
                    ->options([$companyId => Auth::user()?->defaultCompany?->name])
                    ->default($companyId)
                    ->disabled()
                    ->dehydrated()
                    ->required(),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('request_type')->required()->maxLength(100)->datalist([
                    'journal_posting', 'manual_mapping', 'account_master_change', 'party_master_change',
                    'exchange_rate_change', 'credit_note', 'write_off', 'reversal', 'import_deletion',
                    'employee_request', 'expense_claim', 'payroll_run',
                    'employee_sensitive_change', 'timesheet_submission', 'employee_expense_claim',
                    'employee_reimbursement', 'employee_travel', 'employee_loan', 'employee_salary_advance',
                ]),
                TextInput::make('priority')->numeric()->default(100)->required(),
                TextInput::make('minimum_amount')->numeric()->minValue(0),
                TextInput::make('maximum_amount')->numeric()->minValue(0)->gte('minimum_amount'),
                Toggle::make('is_active')->default(true),
            ]),
            Section::make('Workflow conditions')
                ->description('Every configured condition must match. Context fields may include department_id, location_id, account_id, role, or other submitted request metadata.')
                ->schema([
                    Repeater::make('conditions')->columns(3)->schema([
                        TextInput::make('field')->required(),
                        Select::make('operator')->options([
                            'equals' => 'Equals', 'not_equals' => 'Does not equal', 'in' => 'In list',
                            'gte'    => 'Greater than or equal', 'lte' => 'Less than or equal',
                        ])->default('equals')->required(),
                        TextInput::make('value')->required(),
                    ])->defaultItems(0),
                ]),
            Section::make('Approval steps')->schema([
                Repeater::make('steps')
                    ->relationship()
                    ->orderColumn('sequence')
                    ->columns(3)
                    ->schema([
                        TextInput::make('sequence')->numeric()->minValue(1)->required(),
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('required_approvals')->numeric()->minValue(1)->default(1)->required(),
                        Select::make('approver_user_id')
                            ->label('Specific approver')
                            ->searchable()
                            ->options(fn (): array => static::approverUserOptions($companyId)),
                        Select::make('approver_role_id')
                            ->label('Approver role')
                            ->searchable()
                            ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'id')->all()),
                        Select::make('hierarchy_route')->options([
                            'requester_manager'  => 'Requester manager',
                            'department_manager' => 'Department manager',
                            'team_manager'       => 'Team manager',
                        ]),
                        TextInput::make('sla_hours')->numeric()->minValue(1),
                        Repeater::make('conditions')->columns(3)->columnSpanFull()->schema([
                            TextInput::make('field')->required(),
                            Select::make('operator')->options([
                                'equals' => 'Equals', 'not_equals' => 'Does not equal', 'in' => 'In list',
                                'gte'    => 'Greater than or equal', 'lte' => 'Less than or equal',
                            ])->default('equals')->required(),
                            TextInput::make('value')->required(),
                        ])->defaultItems(0),
                    ])
                    ->minItems(1)
                    ->defaultItems(1),
            ]),
        ]);
    }

    /**
     * Active users who belong to the workflow's company (default or allowed),
     * the same rule the approval engine applies.
     *
     * @return array<int, string>
     */
    public static function approverUserOptions(?int $companyId = null): array
    {
        $companyId = $companyId ?: CurrentCompany::id();

        if (! $companyId) {
            return [];
        }

        return CurrentCompany::scopeUsersOf(User::query(), $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(100)
            ->pluck('name', 'id')
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('request_type')->badge()->searchable(),
                TextColumn::make('steps_count')->counts('steps')->label('Steps'),
                TextColumn::make('minimum_amount')->numeric(decimalPlaces: 2)->placeholder('Any'),
                TextColumn::make('maximum_amount')->numeric(decimalPlaces: 2)->placeholder('Any'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()->requiresConfirmation()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListApprovalWorkflows::route('/'),
            'create' => CreateApprovalWorkflow::route('/create'),
            'edit'   => EditApprovalWorkflow::route('/{record}/edit'),
        ];
    }
}
