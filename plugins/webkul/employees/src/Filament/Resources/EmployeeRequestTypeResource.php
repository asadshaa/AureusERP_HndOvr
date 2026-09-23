<?php

namespace Webkul\Employee\Filament\Resources;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\JournalType;
use Webkul\Employee\Filament\Resources\EmployeeRequestTypeResource\Pages\ManageEmployeeRequestTypes;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Support\Enums\NavigationGroup;

class EmployeeRequestTypeResource extends Resource
{
    protected static ?string $model = EmployeeRequestType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 14;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Employee Request Types';
    }

    public static function form(Schema $schema): Schema
    {
        $companyId = (int) Auth::user()?->default_company_id;

        return $schema->components([
            Hidden::make('company_id')->default($companyId),
            TextInput::make('code')->required()->maxLength(80),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('category')->options([
                'travel_entertainment'   => 'Travel & Entertainment',
                'professional_services'  => 'Professional Services',
                'tech'                   => 'Tech',
                'digital_marketing'      => 'Digital Marketing',
                'returns_waivers'        => 'Returns and Waivers',
                'financial_provisions'   => 'Financial Provisions',
                'people'                 => 'People',
                'real_estate'            => 'Real Estate',
                'others'                 => 'Others',
                'attendance_time_change' => 'Attendance Time Change',
                'reimbursement'          => 'Reimbursement',
                'expense_claim'          => 'Expense claim',
                'travel'                 => 'Travel',
                'salary_advance'         => 'Salary advance',
                'loan'                   => 'Employee loan',
                'equipment'              => 'Equipment / asset',
                'document'               => 'Document / certificate',
                'correction'             => 'Correction',
                'leave'                  => 'Leave-related',
                'custom'                 => 'Custom',
            ])->default('custom')->required(),
            TextInput::make('approval_request_type')
                ->helperText('Must match an active shared Approval Workflow request type.')
                ->required()->maxLength(100),
            Toggle::make('is_financial')->live(),
            Toggle::make('requires_amount'),
            Toggle::make('requires_document'),
            Toggle::make('is_active')->default(true),
            Select::make('journal_id')
                ->label('Purchase journal')
                ->helperText('Financial requests post as a real vendor Bill, which requires a Purchase-type journal.')
                ->relationship('journal', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', $companyId)->where('type', JournalType::PURCHASE))
                ->visible(fn ($get): bool => (bool) $get('is_financial'))->searchable()->preload(),
            Select::make('debit_account_id')
                ->label('Debit account (expense)')
                ->relationship('debitAccount', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query
                    ->postable()->where('deprecated', false)
                    ->whereHas('companies', fn (Builder $companies): Builder => $companies->where('companies.id', $companyId)))
                ->visible(fn ($get): bool => (bool) $get('is_financial'))->searchable()->preload(),
            Select::make('credit_account_id')
                ->label('Credit account (not used for posting)')
                ->helperText('Informational only. The posted Bill\'s payable line is resolved automatically (vendor\'s payable account, or the company\'s Accounts Payable account), the same way a real vendor Bill works.')
                ->relationship('creditAccount', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query
                    ->postable()->where('deprecated', false)
                    ->whereHas('companies', fn (Builder $companies): Builder => $companies->where('companies.id', $companyId)))
                ->visible(fn ($get): bool => (bool) $get('is_financial'))->searchable()->preload(),
            KeyValue::make('configuration')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('category')->badge(),
            TextColumn::make('approval_request_type')->label('Approval workflow type')->searchable(),
            IconColumn::make('is_financial')->boolean(),
            IconColumn::make('requires_document')->boolean(),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()->requiresConfirmation()])
            ->headerActions([CreateAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user !== null && ($user->can(HrPermissions::ManageEmployeeRequests) || $user->can(HrPermissions::ViewEmployeeRequestTypes));
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmployeeRequestTypes::route('/')];
    }
}
