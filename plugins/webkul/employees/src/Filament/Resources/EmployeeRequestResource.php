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
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages\ManageEmployeeRequests;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Services\ApprovalEngine;

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

    public static function isFinanceUser(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasAnyRole([
            'Admin',
            'Super Admin',
            'accountant',
            'Accountant',
            'accounting_manager',
            'Accounting_manager',
            'controller',
            'Controller',
            'tax_officer',
            'Tax_officer',
            'finance_operator',
            'Finance_operator',
            'vp_finance',
            'Vp_finance',
            'cfo',
            'Cfo',
        ]) || $user->can('hr_process_financial_requests');
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
                    ->label('Approval Type')
                    ->relationship('requestType', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', $companyId)->where('is_active', true))
                    ->required()->searchable()->preload()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('nature_of_expense', null)),
                Placeholder::make('approval_type_display')
                    ->label('Approval Category')
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
                        ->label('Claim/budget')
                        ->numeric()->minValue(0)
                        ->required($isFinancial)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateNetPayment($set, $get)),
                    TextInput::make('tax_deduction_rate')
                        ->label('Tax deduction rate (%)')
                        ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateNetPayment($set, $get)),
                    TextInput::make('income_tax_deduction')
                        ->label('Deduction amount of income tax')
                        ->numeric()->minValue(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateNetPayment($set, $get)),
                    TextInput::make('sales_tax_deduction')
                        ->label('Deduction amount of sales tax')
                        ->numeric()->minValue(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateNetPayment($set, $get)),
                ]),

            TextInput::make('amount')
                ->label(fn (Get $get): string => $isFinancial($get) ? 'Net payment' : 'Amount')
                ->numeric()->minValue(0)
                ->required($isFinancial)
                ->readOnly($isFinancial)
                ->helperText(fn (Get $get): ?string => $isFinancial($get) ? 'Claim/budget minus tax deductions -- calculated automatically.' : null),

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
            TextColumn::make('requestType.name')->label('Approval type')->searchable(),
            TextColumn::make('nature_of_expense')->label('Nature of expense')->searchable()->limit(25),
            TextColumn::make('title')->searchable()->limit(30),
            TextColumn::make('billed_amount')->label('Claim/budget')->money(fn (EmployeeRequest $record): string => $record->currency?->code ?? 'PKR')->placeholder('—')->sortable(),
            TextColumn::make('tax_deduction_rate')->label('Tax rate')->suffix('%')->placeholder('—')->sortable(),
            TextColumn::make('income_tax_deduction')->label('Income tax')->money(fn (EmployeeRequest $record): string => $record->currency?->code ?? 'PKR')->placeholder('—')->sortable(),
            TextColumn::make('sales_tax_deduction')->label('Sales tax')->money(fn (EmployeeRequest $record): string => $record->currency?->code ?? 'PKR')->placeholder('—')->sortable(),
            TextColumn::make('amount')->label('Net payment')->money(fn (EmployeeRequest $record): string => $record->currency?->code ?? 'PKR')->placeholder('—')->sortable(),
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
            Action::make('adjust_tax')
                ->label('Review & Edit Tax')
                ->icon('heroicon-o-calculator')
                ->color('warning')
                ->visible(fn (EmployeeRequest $record): bool => $record->status === 'pending_approval'
                    && static::isFinanceUser(Auth::user())
                    && (bool) $record->requestType?->is_financial
                )
                ->fillForm(fn (EmployeeRequest $record): array => [
                    'billed_amount'        => $record->billed_amount,
                    'tax_deduction_rate'   => $record->tax_deduction_rate,
                    'income_tax_deduction' => $record->income_tax_deduction,
                    'sales_tax_deduction'  => $record->sales_tax_deduction,
                    'amount'               => $record->amount,
                ])
                ->schema([
                    TextInput::make('billed_amount')
                        ->label('Claim/budget')
                        ->numeric()
                        ->readOnly(),
                    TextInput::make('tax_deduction_rate')
                        ->label('Tax deduction rate (%)')
                        ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get): void {
                            $billed = (float) ($get('billed_amount') ?? 0);
                            $rate = $get('tax_deduction_rate');
                            if (filled($rate)) {
                                $set('income_tax_deduction', round($billed * ((float) $rate / 100), 4));
                            }
                            $inc = (float) ($get('income_tax_deduction') ?? 0);
                            $sal = (float) ($get('sales_tax_deduction') ?? 0);
                            $set('amount', round($billed - $inc - $sal, 4));
                        }),
                    TextInput::make('income_tax_deduction')
                        ->label('Deduction amount of income tax')
                        ->numeric()->minValue(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get): void {
                            $billed = (float) ($get('billed_amount') ?? 0);
                            $inc = (float) ($get('income_tax_deduction') ?? 0);
                            $sal = (float) ($get('sales_tax_deduction') ?? 0);
                            $set('amount', round($billed - $inc - $sal, 4));
                        }),
                    TextInput::make('sales_tax_deduction')
                        ->label('Deduction amount of sales tax')
                        ->numeric()->minValue(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get): void {
                            $billed = (float) ($get('billed_amount') ?? 0);
                            $inc = (float) ($get('income_tax_deduction') ?? 0);
                            $sal = (float) ($get('sales_tax_deduction') ?? 0);
                            $set('amount', round($billed - $inc - $sal, 4));
                        }),
                    TextInput::make('amount')
                        ->label('Net payment')
                        ->numeric()
                        ->readOnly()
                        ->helperText('Claim/budget minus tax deductions -- calculated automatically.'),
                ])
                ->action(function (EmployeeRequest $record, array $data): void {
                    $billed = (float) ($record->billed_amount ?? 0);
                    $incomeTax = (float) ($data['income_tax_deduction'] ?? 0);
                    $salesTax = (float) ($data['sales_tax_deduction'] ?? 0);
                    $net = round($billed - $incomeTax - $salesTax, 4);

                    $record->update([
                        'tax_deduction_rate'   => $data['tax_deduction_rate'] ?? null,
                        'income_tax_deduction' => $incomeTax,
                        'sales_tax_deduction'  => $salesTax,
                        'amount'               => $net,
                    ]);

                    if ($record->approval_request_id) {
                        $record->approvalRequest?->update([
                            'amount' => (string) $net,
                        ]);
                    }

                    Notification::make()
                        ->success()
                        ->title('Tax deductions updated')
                        ->body("Net payment recalculated to {$net}")
                        ->send();
                }),
            Action::make('approve_request')
                ->label('Approve')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (EmployeeRequest $record): bool => $record->status === 'pending_approval'
                    && $record->approvalRequest !== null
                    && Auth::user() !== null
                    && app(ApprovalEngine::class)->canAct($record->approvalRequest, Auth::user())
                )
                ->schema([
                    Textarea::make('reason')->label('Approval note'),
                ])
                ->action(function (EmployeeRequest $record, array $data): void {
                    try {
                        app(EmployeeRequestService::class)->approve($record, Auth::user(), $data['reason'] ?? null);
                        Notification::make()->success()->title('Request approved successfully')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Could not approve')->body($e->getMessage())->send();
                    }
                }),
            Action::make('reject_request')
                ->label('Reject')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (EmployeeRequest $record): bool => $record->status === 'pending_approval'
                    && $record->approvalRequest !== null
                    && Auth::user() !== null
                    && app(ApprovalEngine::class)->canAct($record->approvalRequest, Auth::user())
                )
                ->schema([
                    Textarea::make('reason')->label('Rejection reason')->required(),
                ])
                ->action(function (EmployeeRequest $record, array $data): void {
                    try {
                        app(EmployeeRequestService::class)->reject($record, Auth::user(), (string) $data['reason']);
                        Notification::make()->success()->title('Request rejected')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Could not reject')->body($e->getMessage())->send();
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
            EditAction::make()
                ->visible(fn (EmployeeRequest $record): bool => in_array($record->status, ['draft', 'rejected'], true)
                    || ($record->status === 'pending_approval' && static::isFinanceUser(Auth::user()))
                )
                ->after(function (EmployeeRequest $record): void {
                    if ($record->approval_request_id && $record->status === 'pending_approval') {
                        $record->approvalRequest?->update([
                            'amount' => (string) $record->amount,
                        ]);
                    }
                }),
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
        if (! $user) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        $companyId = (int) $user->default_company_id;

        if (
            $user->hasRole('Admin')
            || $user->hasRole('Super Admin')
            || $user->can('hr_view_all_records')
            || static::isFinanceUser($user)
        ) {
            return parent::getEloquentQuery()->where('company_id', $companyId);
        }

        $visible = app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId);

        // A step can be pinned to one specific named user (approver_user_id) rather
        // than a role -- e.g. the claims hierarchy's Level 1/3/4 approvers. Someone
        // who is only the named approver on a pending step (not a manager, not HR,
        // not Finance) still needs to be able to SEE the request in order to act on
        // it, even though it falls outside their normal reporting-tree visibility.
        $pendingOnMe = DB::table('support_approval_requests as ar')
            ->join('support_approval_steps as s', function ($join) {
                $join->on('s.workflow_id', '=', 'ar.workflow_id')
                    ->on('s.sequence', '=', 'ar.current_step_sequence');
            })
            ->where('ar.status', 'pending')
            ->where('s.approver_user_id', $user->id)
            ->pluck('ar.id');

        // Once a request is fully approved/rejected it no longer has a "current
        // step" for anyone to be pinned to, so $pendingOnMe alone would make it
        // vanish even for someone who actually approved/rejected a step on it --
        // keep it visible to them afterwards too, using the real decision record.
        $decidedByMe = DB::table('support_approval_decisions')
            ->where('actor_id', $user->id)
            ->pluck('request_id');

        return parent::getEloquentQuery()->where('company_id', $companyId)
            ->where(fn (Builder $query) => $query
                ->whereIn('employee_id', $visible)
                ->orWhereIn('approval_request_id', $pendingOnMe)
                ->orWhereIn('approval_request_id', $decidedByMe));
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmployeeRequests::route('/')];
    }
}
