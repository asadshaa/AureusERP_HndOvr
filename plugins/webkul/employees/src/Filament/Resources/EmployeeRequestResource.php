<?php

namespace Webkul\Employee\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages\ManageEmployeeRequests;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Support\Enums\NavigationGroup;

class EmployeeRequestResource extends Resource
{
    protected static ?string $model = EmployeeRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?int $navigationSort = 13;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Employee Requests';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::formComponents());
    }

    /**
     * Shared between the resource's own Create/Edit form and the "Submit"
     * header action (Section 8) so both stay in sync without duplicating
     * the claim-specific fields (billed amount / tax / bank details /
     * nature of expense) twice. Every field beyond the original generic
     * set is conditionally visible+required on the selected request type's
     * `is_financial` flag, not a hard-coded category/type name -- a
     * non-financial request (e.g. Attendance Time Change) still gets the
     * original plain form.
     *
     * @return array<int, Component>
     */
    protected static function formComponents(): array
    {
        $user = Auth::user();
        $companyId = (int) $user?->default_company_id;
        $visibleEmployeeIds = $user ? app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId) : collect();

        $isFinancial = fn (Get $get): bool => (bool) static::selectedRequestType($get)?->is_financial;
        $canSeeBankDetails = function (Get $get) use ($user): bool {
            if (! $user) {
                return false;
            }
            if ((int) $get('employee_id') === (int) $user->employee?->id) {
                return true;
            }

            return $user->can(HrPermissions::ViewSensitiveEmployeeData);
        };

        return [
            Hidden::make('company_id')->default($companyId),
            Hidden::make('requested_by')->default(fn (): ?int => Auth::id()),
            Hidden::make('status')->default('draft'),
            Section::make('Request Type')->columns(2)->schema([
                Select::make('employee_id')
                    ->relationship('employee', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', $companyId)->whereIn('id', $visibleEmployeeIds))
                    ->default(fn (): ?int => Auth::user()?->employee?->id)
                    ->required()->searchable()->preload()->live(),
                Select::make('request_type_id')
                    ->label('Type')
                    ->relationship('requestType', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', $companyId)->where('is_active', true))
                    ->required()->searchable()->preload()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('nature_of_expense', null)),
                Placeholder::make('approval_type_display')
                    ->label('Approval Type')
                    ->content(fn (Get $get): string => $isFinancial($get) ? 'Claims Approval' : ($get('request_type_id') ? 'Standard Approval' : '—'))
                    ->visible(fn (Get $get): bool => filled($get('request_type_id'))),
                Select::make('nature_of_expense')
                    ->label(fn (Get $get): string => 'What is the nature of expense'.(($name = static::selectedRequestType($get)?->name) ? " for {$name}?" : '?'))
                    ->options(fn (Get $get): array => array_combine(
                        $natures = static::selectedRequestType($get)?->getExpenseNatures() ?? [],
                        $natures,
                    ))
                    ->visible(fn (Get $get): bool => (static::selectedRequestType($get)?->getExpenseNatures() ?? []) !== [])
                    ->required(fn (Get $get): bool => (static::selectedRequestType($get)?->getExpenseNatures() ?? []) !== [])
                    ->searchable(),
            ]),
            TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('description')->columnSpanFull(),
            Select::make('currency_id')
                ->relationship('currency', 'name')
                ->default(fn (): ?int => Auth::user()?->defaultCompany?->currency_id)
                ->searchable()->preload(),
            FileUpload::make('attachments')
                ->multiple()->directory('employees/requests')->visibility('private')->columnSpanFull(),
            KeyValue::make('payload')
                ->label('Additional request details')->columnSpanFull()
                ->visible(fn (Get $get): bool => ! $isFinancial($get)),

            Section::make('Request Details')->columns(2)
                ->visible($isFinancial)
                ->schema([
                    TextInput::make('billed_amount')
                        ->label('What is the billed amount?')
                        ->numeric()->minValue(0)
                        ->required($isFinancial)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateNetPayment($set, $get)),
                    TextInput::make('tax_deduction_rate')
                        ->label('Tax Deduction Rate')
                        ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateNetPayment($set, $get)),
                    TextInput::make('income_tax_deduction')
                        ->label('Deduction amount of Income Tax')
                        ->numeric()->minValue(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateNetPayment($set, $get)),
                    TextInput::make('sales_tax_deduction')
                        ->label('Deduction amount of Sales Tax')
                        ->numeric()->minValue(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateNetPayment($set, $get)),
                ]),

            TextInput::make('amount')
                ->label(fn (Get $get): string => $isFinancial($get) ? 'Net payment' : 'Amount')
                ->numeric()->minValue(0)
                ->required($isFinancial)
                ->readOnly($isFinancial)
                ->helperText(fn (Get $get): ?string => $isFinancial($get) ? 'Billed amount minus tax deductions -- calculated automatically.' : null),

            Section::make('Bank Details')->columns(3)
                ->visible(fn (Get $get): bool => $isFinancial($get) && $canSeeBankDetails($get))
                ->schema([
                    TextInput::make('account_title')->label('Account Title')->required($isFinancial)->maxLength(255),
                    TextInput::make('iban')->label('IBAN')->required($isFinancial)->maxLength(50),
                    TextInput::make('bank_name')->label('Bank Name')->required($isFinancial)->maxLength(255),
                ]),
        ];
    }

    protected static function selectedRequestType(Get $get): ?EmployeeRequestType
    {
        $id = $get('request_type_id');

        return $id ? EmployeeRequestType::find($id) : null;
    }

    /**
     * Net payment ("amount") is always billed_amount minus both tax
     * deductions, recalculated on every relevant keystroke rather than left
     * for the user to add up -- the income-tax suggestion from the rate is
     * a convenience only and never overwrites a value already typed
     * directly into that field.
     */
    protected static function recalculateNetPayment(Set $set, Get $get): void
    {
        $billed = (float) ($get('billed_amount') ?? 0);
        $rate = $get('tax_deduction_rate');
        if (filled($rate) && blank($get('income_tax_deduction'))) {
            $set('income_tax_deduction', round($billed * ((float) $rate / 100), 4));
        }
        $incomeTax = (float) ($get('income_tax_deduction') ?? 0);
        $salesTax = (float) ($get('sales_tax_deduction') ?? 0);
        $set('amount', round($billed - $incomeTax - $salesTax, 4));
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->placeholder('Draft'),
            TextColumn::make('employee.name')->searchable()->sortable(),
            TextColumn::make('requestType.name')->label('Request type')->searchable(),
            TextColumn::make('title')->searchable()->limit(40),
            TextColumn::make('billed_amount')->money(fn (EmployeeRequest $record): string => $record->currency?->code ?? 'PKR')->placeholder('—'),
            TextColumn::make('amount')->label('Net payment')->money(fn (EmployeeRequest $record): string => $record->currency?->code ?? 'PKR')->placeholder('—'),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                'approved' => 'success', 'rejected' => 'danger', 'pending_approval' => 'warning', default => 'gray',
            }),
            TextColumn::make('accountingMove.name')->label('Accounting draft')->placeholder('—'),
            TextColumn::make('submitted_at')->dateTime()->placeholder('Not submitted'),
        ])->filters([
            SelectFilter::make('status')->options([
                'draft'    => 'Draft', 'pending_approval' => 'Pending approval',
                'approved' => 'Approved', 'rejected' => 'Rejected',
            ]),
        ])->recordActions([
            Action::make('submit')
                ->icon('heroicon-o-paper-airplane')->color('primary')->requiresConfirmation()
                ->visible(fn (EmployeeRequest $record): bool => in_array($record->status, ['draft', 'rejected'], true))
                ->action(function (EmployeeRequest $record): void {
                    try {
                        app(EmployeeRequestService::class)->submit($record, Auth::user());
                        Notification::make()->success()->title('Employee request submitted for approval')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Could not submit')->body($e->getMessage())->send();
                    }
                }),
            Action::make('refresh_approval')
                ->label('Refresh approval')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (EmployeeRequest $record): bool => $record->approval_request_id !== null && $record->status === 'pending_approval')
                ->action(function (EmployeeRequest $record): void {
                    app(EmployeeRequestService::class)->synchronize($record);
                    Notification::make()->success()->title('Approval status refreshed')->send();
                }),
            EditAction::make()->visible(fn (EmployeeRequest $record): bool => in_array($record->status, ['draft', 'rejected'], true)),
            DeleteAction::make()->visible(fn (EmployeeRequest $record): bool => $record->status === 'draft'),
        ])->headerActions([
            CreateAction::make()->label('Save Draft'),
            Action::make('submit_new')
                ->label('Submit')
                ->icon('heroicon-o-paper-airplane')->color('primary')
                ->schema(fn (): array => static::formComponents())
                ->action(function (array $data): void {
                    $record = EmployeeRequest::query()->create($data);
                    try {
                        app(EmployeeRequestService::class)->submit($record, Auth::user());
                        Notification::make()->success()->title('Request submitted for approval')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Saved as draft -- could not submit')->body($e->getMessage())->send();
                    }
                }),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $companyId = (int) $user?->default_company_id;
        $visible = $user ? app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId) : collect();

        return parent::getEloquentQuery()->where('company_id', $companyId)->whereIn('employee_id', $visible);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmployeeRequests::route('/')];
    }
}
