<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\ManualAdjustmentStatus;
use Webkul\Accounting\Filament\Clusters\Accounting;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\ManualAdjustmentResource\Pages\CreateManualAdjustment;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\ManualAdjustmentResource\Pages\EditManualAdjustment;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\ManualAdjustmentResource\Pages\ListManualAdjustments;
use Webkul\Accounting\Models\ManualAdjustment;
use Webkul\Accounting\Services\ManualAdjustmentService;
use Webkul\Accounting\Support\AccountingPermissions;

class ManualAdjustmentResource extends Resource
{
    protected static ?string $model = ManualAdjustment::class;

    protected static ?string $cluster = Accounting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return 'Manual Non-Bank Adjustments';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Auth::user()?->default_company_id);
    }

    public static function form(Schema $schema): Schema
    {
        $companyId = Auth::user()?->default_company_id;
        $accountSearch = fn (string $search): array => Account::query()->postable()->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
            ->orderBy('code')->limit(50)->get()
            ->mapWithKeys(fn (Account $account): array => [$account->id => "{$account->code} {$account->name}"])->all();
        $accountLabel = function ($value) use ($companyId): ?string {
            $account = Account::query()->whereKey($value)
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))->first();

            return $account ? "{$account->code} {$account->name}" : null;
        };

        return $schema->components([
            Section::make('Non-bank entry')->columns(2)->schema([
                Select::make('company_id')->options([$companyId => Auth::user()?->defaultCompany?->name])->default($companyId)->disabled()->dehydrated()->required(),
                Select::make('journal_id')->label('General journal')->searchable()
                    ->options(fn (): array => Journal::query()->where('company_id', $companyId)->where('type', JournalType::GENERAL)
                        ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                    ->getSearchResultsUsing(fn (string $search): array => Journal::query()
                        ->where('company_id', $companyId)->where('type', JournalType::GENERAL)
                        ->where('name', 'like', "%{$search}%")->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Journal::query()
                        ->where('company_id', $companyId)->where('type', JournalType::GENERAL)->find($value)?->name),
                DatePicker::make('date')->required()->default(now()),
                TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                Select::make('debit_account_id')->label('Debit account')->searchable()
                    ->options(fn (): array => $accountSearch(''))->getSearchResultsUsing($accountSearch)->getOptionLabelUsing($accountLabel)->required(),
                Select::make('credit_account_id')->label('Credit account')->searchable()
                    ->options(fn (): array => $accountSearch(''))->getSearchResultsUsing($accountSearch)->getOptionLabelUsing($accountLabel)->required(),
                Textarea::make('description')->required()->columnSpanFull(),
                TextInput::make('supporting_reference')->label('Supporting document/reference'),
                TextInput::make('tax_treatment'),
                Select::make('source_classification')->required()->options([
                    'accrual'          => 'Accrual', 'unpaid_invoice' => 'Unpaid invoice', 'unpaid_bill' => 'Unpaid supplier bill',
                    'depreciation'     => 'Depreciation', 'amortization' => 'Amortization', 'prepayment' => 'Prepayment',
                    'payroll_accrual'  => 'Payroll accrual', 'provision' => 'Provision', 'tax' => 'Tax entry',
                    'opening_balances' => 'Opening balances',
                ]),
                Select::make('approval_status')->options([
                    'draft' => 'Draft', 'approved' => 'Approved', 'rejected' => 'Rejected', 'posted' => 'Posted',
                ])->default('draft')->disabled()->dehydrated(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('adjustment_reference')->label('Entry ID')->searchable(),
            TextColumn::make('date')->date()->sortable(),
            TextColumn::make('description')->limit(45)->searchable(),
            TextColumn::make('debitAccount.code')->label('Debit GL'),
            TextColumn::make('creditAccount.code')->label('Credit GL'),
            TextColumn::make('amount')->numeric(2)->alignRight(),
            TextColumn::make('tax_treatment')->placeholder('—'),
            TextColumn::make('company.name')->label('Entity'),
            TextColumn::make('supporting_reference')->label('Supporting document')->placeholder('—'),
            TextColumn::make('approval_status')->badge(),
            TextColumn::make('source_classification')->label('Source classification')->badge(),
            TextColumn::make('cash_flow_category')->label('Cash Flow Impact')->badge(),
        ])->recordActions([
            EditAction::make()->authorize(AccountingPermissions::ManageManualAdjustments)
                ->visible(fn (ManualAdjustment $record) => $record->approval_status === ManualAdjustmentStatus::Draft),
            Action::make('submit_approval')->label('Submit for approval')->icon('heroicon-o-paper-airplane')
                ->authorize(AccountingPermissions::ManageManualAdjustments)
                ->visible(fn (ManualAdjustment $record) => $record->approval_status === ManualAdjustmentStatus::Draft
                    && app(ManualAdjustmentService::class)->requiresConfiguredApproval($record))
                ->action(function (ManualAdjustment $record): void {
                    $request = app(ManualAdjustmentService::class)->submit($record, Auth::user());
                    Notification::make()->success()->title("Approval request APR-{$request->id} is in the shared approval queue.")->send();
                }),
            Action::make('approve')->icon('heroicon-o-check')->color('success')
                ->authorize(AccountingPermissions::ApproveJournal)
                ->visible(fn (ManualAdjustment $record) => $record->approval_status === ManualAdjustmentStatus::Draft)
                ->action(function (ManualAdjustment $record): void {
                    app(ManualAdjustmentService::class)->approve($record, Auth::user());
                    Notification::make()->success()->title('Manual adjustment approved.')->send();
                }),
            Action::make('reject')->icon('heroicon-o-x-mark')->color('danger')->requiresConfirmation()
                ->authorize(AccountingPermissions::ApproveJournal)
                ->visible(fn (ManualAdjustment $record) => $record->approval_status === ManualAdjustmentStatus::Draft)
                ->action(fn (ManualAdjustment $record) => $record->update([
                    'approval_status' => ManualAdjustmentStatus::Rejected,
                    'reviewer_id'     => Auth::id(),
                    'reviewed_at'     => now(),
                ])),
            Action::make('draftJournal')->label('Generate draft')->icon('heroicon-o-document-plus')
                ->authorize(AccountingPermissions::GenerateJournal)
                ->visible(fn (ManualAdjustment $record) => $record->approval_status === ManualAdjustmentStatus::Approved && $record->move_id === null)
                ->action(function (ManualAdjustment $record): void {
                    app(ManualAdjustmentService::class)->createDraft($record);
                    Notification::make()->success()->title('Balanced draft adjustment journal created.')->send();
                }),
            Action::make('post')->icon('heroicon-o-lock-closed')->color('danger')->requiresConfirmation()
                ->authorize(AccountingPermissions::PostJournal)
                ->visible(fn (ManualAdjustment $record) => $record->approval_status === ManualAdjustmentStatus::Approved && $record->move_id !== null)
                ->action(function (ManualAdjustment $record): void {
                    app(ManualAdjustmentService::class)->post($record, Auth::user());
                    Notification::make()->success()->title('Manual adjustment posted.')->send();
                }),
        ])->defaultSort('date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListManualAdjustments::route('/'),
            'create' => CreateManualAdjustment::route('/create'),
            'edit'   => EditManualAdjustment::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        // ManageManualAdjustments alone used to gate this -- meaning
        // there was no way to grant a role (e.g. Internal Auditor, VP
        // Finance, CFO) read-only visibility without also handing it
        // Edit/Submit-for-approval access. ViewManualAdjustments closes
        // that gap without widening what ManageManualAdjustments-holders
        // can already do.
        return $user !== null && ($user->can(AccountingPermissions::ManageManualAdjustments)
            || $user->can(AccountingPermissions::ViewManualAdjustments));
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageManualAdjustments) ?? false;
    }

    /**
     * There is no Policy class for ManualAdjustment, and Filament allows
     * an action by default when nothing gates it -- so canEdit()/
     * canDelete() were previously wide open to ANY authenticated user
     * navigating directly to the edit URL, completely bypassing the
     * EditAction button's own ->authorize(ManageManualAdjustments) check
     * on the list. Found live during manual testing (a read-only VP
     * Finance test user could open a real edit form). Company scoping on
     * getEloquentQuery() already prevents cross-company access; this
     * closes the same-company over-permission gap.
     */
    public static function canEdit($record): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageManualAdjustments) ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageManualAdjustments) ?? false;
    }
}
