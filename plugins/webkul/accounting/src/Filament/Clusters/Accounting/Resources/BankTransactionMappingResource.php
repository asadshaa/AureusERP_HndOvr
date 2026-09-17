<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Resources;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\CashFlowCategory;
use Webkul\Accounting\Filament\Clusters\Accounting;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankTransactionMappingResource\Pages\ListBankTransactionMappings;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Account\CanonicalAccountCreationService;
use Webkul\Accounting\Services\Bank\BankJournalService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\Bank\BankTransferMatchingService;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class BankTransactionMappingResource extends Resource
{
    protected static ?string $model = BankTransactionMapping::class;

    protected static ?string $cluster = Accounting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return 'Transaction Mapping';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Auth::user()?->default_company_id)
            ->with(['statementLine.statement', 'bankGlAccount', 'offsetAccount', 'fsTag.account', 'mappingRule', 'reviewer', 'transferMatch']);
    }

    public static function form(Schema $schema): Schema
    {
        $companyId = Auth::user()?->default_company_id;
        $accountSearch = fn (string $search): array => Account::query()->postable()->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
            ->orderBy('code')->limit(50)->get()->mapWithKeys(fn (Account $account): array => [$account->id => "{$account->code} {$account->name}"])->all();
        $accountLabel = function ($value) use ($companyId): ?string {
            $account = Account::query()
                ->postable()
                ->where('deprecated', false)
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
                ->find($value);

            return $account ? "{$account->code} {$account->name}" : null;
        };
        $bankAccountSearch = fn (string $search): array => Account::query()
            ->postable()
            ->where('deprecated', false)
            ->where('account_type', AccountType::ASSET_CASH)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Account $account): array => [$account->id => "{$account->code} {$account->name}"])
            ->all();
        $bankAccountLabel = function ($value) use ($companyId): ?string {
            $account = Account::query()
                ->postable()
                ->where('deprecated', false)
                ->where('account_type', AccountType::ASSET_CASH)
                ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
                ->find($value);

            return $account ? "{$account->code} {$account->name}" : null;
        };
        $fsTagQuery = fn (): Builder => FsTag::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNotNull('account_id')
            ->whereHas('account', fn ($query) => $query->postable()->where('deprecated', false)
                ->whereHas('companies', fn ($companyQuery) => $companyQuery->where('companies.id', $companyId)));
        $fsTagLabel = fn (FsTag $tag): string => "{$tag->code} {$tag->name}";

        return $schema->components([
            Section::make('Mapping review')->columns(2)->schema([
                TextInput::make('transaction_type'),
                TextInput::make('counterparty'),
                TextInput::make('supporting_document')->label('Supporting document/reference'),
                Select::make('bank_gl_account_id')->label('Bank GL')->searchable()
                    ->options(fn (): array => $bankAccountSearch(''))
                    ->getSearchResultsUsing($bankAccountSearch)->getOptionLabelUsing($bankAccountLabel)->required(),
                Select::make('fs_tag_id')->label('FS Tag')->searchable()->live()
                    ->helperText('Selecting a mapped FS Tag supplies the offset GL, cash-flow category and tax treatment.')
                    ->afterStateUpdated(function (Set $set, $state) use ($companyId): void {
                        if (! $state) {
                            return;
                        }

                        $tag = FsTag::query()->where('company_id', $companyId)->find($state);
                        if ($tag) {
                            if ($tag->account_id) {
                                $set('offset_account_id', $tag->account_id);
                            }
                            if ($tag->cash_flow_category) {
                                $set('cash_flow_category', $tag->cash_flow_category);
                            }
                            if ($tag->tax_treatment) {
                                $set('tax_treatment', $tag->tax_treatment);
                            }
                        }
                    })
                    ->options(fn (): array => $fsTagQuery()->orderBy('code')->limit(50)->get()->mapWithKeys(fn (FsTag $tag): array => [$tag->id => $fsTagLabel($tag)])->all())
                    ->getSearchResultsUsing(fn (string $search): array => $fsTagQuery()
                        ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                        ->orderBy('code')->limit(50)->get()->mapWithKeys(fn (FsTag $tag): array => [$tag->id => $fsTagLabel($tag)])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($tag = $fsTagQuery()->find($value)) ? $fsTagLabel($tag) : null)
                    ->createOptionForm([
                        TextInput::make('code')->label('FS Tag code')->helperText('Leave blank for automatic numbering.')->maxLength(60),
                        TextInput::make('name')->label('FS Tag name')->required()->maxLength(255),
                        Select::make('account_id')->label('Existing GL account')->searchable()
                            ->options(fn (): array => $accountSearch(''))
                            ->getSearchResultsUsing($accountSearch)->getOptionLabelUsing($accountLabel),
                        Toggle::make('create_account')->label('Create a new GL account')->live(),
                        TextInput::make('account_code')->label('New GL code')->helperText('Leave blank for automatic numbering.')
                            ->visible(fn (Get $get): bool => (bool) $get('create_account')),
                        TextInput::make('account_name')->label('New GL title')
                            ->required(fn (Get $get): bool => (bool) $get('create_account'))
                            ->visible(fn (Get $get): bool => (bool) $get('create_account')),
                        Select::make('account_type')->options(collect([
                            AccountType::EXPENSE,
                            AccountType::EXPENSE_DEPRECIATION,
                            AccountType::EXPENSE_DIRECT_COST,
                            AccountType::INCOME,
                            AccountType::INCOME_OTHER,
                            AccountType::ASSET_CURRENT,
                            AccountType::ASSET_NON_CURRENT,
                            AccountType::ASSET_PREPAYMENTS,
                            AccountType::ASSET_FIXED,
                            AccountType::LIABILITY_CURRENT,
                            AccountType::LIABILITY_NON_CURRENT,
                            AccountType::EQUITY,
                        ])->mapWithKeys(fn (AccountType $type): array => [$type->value => $type->getLabel()])->all())
                            ->default(AccountType::EXPENSE->value)
                            ->required(fn (Get $get): bool => (bool) $get('create_account'))
                            ->visible(fn (Get $get): bool => (bool) $get('create_account')),
                        Select::make('currency_id')->label('GL currency')->searchable()
                            ->options(fn (): array => Currency::query()->active()
                                ->whereHas('enabledCompanies', fn ($query) => $query->where('companies.id', $companyId))
                                ->orderBy('display_order')->limit(50)->get()
                                ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name])->all())
                            ->visible(fn (Get $get): bool => (bool) $get('create_account')),
                        Select::make('cash_flow_category')->options(CashFlowCategory::options())->required(),
                        TextInput::make('tax_treatment'),
                        Toggle::make('is_active')->default(true)->disabled()->dehydrated(),
                    ])
                    ->createOptionAction(fn (Action $action) => $action->modalHeading('Create FS Tag')
                        ->visible(Auth::user()?->can(AccountingPermissions::ManageFsTags) ?? false))
                    ->createOptionUsing(function (array $data) use ($companyId): int {
                        abort_unless(Auth::user()?->can(AccountingPermissions::ManageFsTags), 403);

                        return app(FsTagService::class)->create(Company::query()->findOrFail($companyId), $data)->id;
                    }),
                Select::make('offset_account_id')->label('Offset GL')->searchable()
                    ->options(fn (): array => $accountSearch(''))
                    ->getSearchResultsUsing($accountSearch)->getOptionLabelUsing($accountLabel)
                    ->required(fn (Get $get): bool => ! filled($get('fs_tag_id')))
                    ->createOptionForm([
                        TextInput::make('code')->label('GL code')->required()->maxLength(64),
                        TextInput::make('name')->label('Title')->required()->maxLength(255),
                        Select::make('currency_id')
                            ->label('Currency behavior')
                            ->helperText('Leave blank to use the company base currency.')
                            ->searchable()
                            ->options(fn () => Currency::query()->active()
                                ->whereHas('enabledCompanies', fn ($query) => $query->where('companies.id', $companyId))
                                ->orderBy('display_order')->limit(50)->get()
                                ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name])),
                        Select::make('parent_id')
                            ->label('Parent account')
                            ->searchable()
                            ->options(fn (): array => app(CanonicalAccountCreationService::class)
                                ->offsetParentAccountQuery(Company::query()->findOrFail($companyId), null)
                                ->orderBy('code')->limit(50)->get()
                                ->mapWithKeys(fn (Account $account): array => [$account->id => trim("{$account->code} {$account->name}")])->all())
                            ->getSearchResultsUsing(fn (string $search): array => app(CanonicalAccountCreationService::class)
                                ->offsetParentAccountQuery(Company::query()->findOrFail($companyId), null)
                                ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                                ->orderBy('code')->limit(50)->get()
                                ->mapWithKeys(fn (Account $account): array => [$account->id => trim("{$account->code} {$account->name}")])->all())
                            ->getOptionLabelUsing(function ($value) use ($companyId): ?string {
                                $account = app(CanonicalAccountCreationService::class)
                                    ->offsetParentAccountQuery(Company::query()->findOrFail($companyId), null)->find($value);

                                return $account ? trim("{$account->code} {$account->name}") : null;
                            }),
                        Select::make('account_type')
                            ->options(collect([
                                AccountType::EXPENSE,
                                AccountType::EXPENSE_DEPRECIATION,
                                AccountType::EXPENSE_DIRECT_COST,
                                AccountType::INCOME,
                                AccountType::INCOME_OTHER,
                                AccountType::ASSET_CURRENT,
                                AccountType::ASSET_NON_CURRENT,
                                AccountType::ASSET_PREPAYMENTS,
                                AccountType::ASSET_FIXED,
                                AccountType::LIABILITY_CURRENT,
                                AccountType::LIABILITY_NON_CURRENT,
                                AccountType::EQUITY,
                            ])->mapWithKeys(fn (AccountType $type): array => [$type->value => $type->getLabel()])->all())
                            ->required(),
                        TextInput::make('nature'),
                        TextInput::make('classification_1')->label('Classification 1'),
                        TextInput::make('classification_2')->label('Classification 2'),
                        TextInput::make('classification_3')->label('Classification 3'),
                        TextInput::make('classification_4')->label('Classification 4'),
                        TextInput::make('classification_5')->label('Classification 5'),
                        TextInput::make('classification_6')->label('Classification 6'),
                        TextInput::make('classification_7')->label('Classification 7'),
                        Toggle::make('is_group')->label('Group account')->default(false)->disabled()->dehydrated(),
                        Toggle::make('active')->default(true),
                        Textarea::make('description'),
                    ])
                    ->createOptionAction(fn (Action $action) => $action
                        ->modalHeading('Create Offset GL')
                        ->visible(Auth::user()?->can(AccountingPermissions::CreateOffsetAccount) ?? false))
                    ->createOptionUsing(function (array $data): int {
                        abort_unless(Auth::user()?->can(AccountingPermissions::CreateOffsetAccount), 403);

                        return app(CanonicalAccountCreationService::class)
                            ->createOffsetAccount(Company::query()->findOrFail(Auth::user()?->default_company_id), $data)
                            ->id;
                    }),
                TextInput::make('tax_treatment'),
                Select::make('cash_flow_category')->options(CashFlowCategory::options())->required(),
                TextInput::make('confidence')->numeric()->minValue(0)->maxValue(1)->disabled(),
                Select::make('review_status')->options(BankReviewStatus::options())->disabled(),
                TextInput::make('map_reference')->disabled(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('map_reference')->label('Map ID')->searchable()->sortable(),
                TextColumn::make('statementLine.statement.bank_name')->label('Bank'),
                TextColumn::make('statementLine.transaction_date')->label('Date')->date()->sortable(),
                TextColumn::make('statementLine.description')->label('Description')->limit(45)->searchable()->tooltip(fn (BankTransactionMapping $record) => $record->statementLine?->description),
                TextColumn::make('statementLine.reference')->label('Reference')->searchable(),
                TextColumn::make('statementLine.debit')->label('Debit')->numeric(2)->alignRight(),
                TextColumn::make('statementLine.credit')->label('Credit')->numeric(2)->alignRight(),
                TextColumn::make('transaction_type')->label('Transaction type')->placeholder('—'),
                TextColumn::make('counterparty')->placeholder('—')->toggleable(),
                TextColumn::make('supporting_document')->label('Supporting document')->placeholder('—')->toggleable(),
                TextColumn::make('bankGlAccount.code')->label('Bank GL'),
                TextColumn::make('offsetAccount.code')->label('Offset GL')->placeholder('—'),
                TextColumn::make('matchedMove.name')->label('Matched invoice/bill')->placeholder('—')->toggleable(),
                TextColumn::make('fsTag.code')
                    ->label('FS Tag')
                    // A resolved tag shows its own code. An unresolved one shows
                    // the raw text the user actually typed (marked unrecognized)
                    // so it reads differently from a genuinely blank cell — the
                    // two used to look identical.
                    //
                    // This must be ->state(), not ->formatStateUsing(): Filament's
                    // TextColumn checks blank($rawState) BEFORE ever calling
                    // formatStateUsing() and renders only the placeholder if so —
                    // formatStateUsing() only reformats an already-non-blank state,
                    // it can't substitute a value for a blank one. ->state()
                    // overrides what "raw state" even is, so a resolved-vs-not
                    // outcome is computed before that blank check ever runs.
                    ->state(fn (BankTransactionMapping $record): string => $record->fsTag?->code
                        ?? ($record->fs_tag_raw_code !== null ? "{$record->fs_tag_raw_code} (unrecognized)" : '—'))
                    ->color(fn (BankTransactionMapping $record): ?string => $record->fs_tag_id === null && $record->fs_tag_raw_code !== null ? 'danger' : null)
                    ->tooltip(fn (BankTransactionMapping $record): ?string => $record->fs_tag_issue),
                TextColumn::make('tax_treatment')->placeholder('—')->toggleable(),
                TextColumn::make('company.name')->label('Entity')->toggleable(),
                TextColumn::make('review_status')->badge(),
                TextColumn::make('transferMatch.match_reference')->label('Transfer Match ID')->placeholder('—'),
                TextColumn::make('posting_status')->badge(),
                TextColumn::make('cash_flow_category')->label('Cash Flow Category')->placeholder('—'),
                TextColumn::make('confidence')->numeric(2)->toggleable(),
                TextColumn::make('mappingRule.name')->label('Mapping rule used')->placeholder('—')->toggleable(),
                TextColumn::make('reviewer.name')->placeholder('—')->toggleable(),
                TextColumn::make('reviewed_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('review_status')->options(BankReviewStatus::options()),
                SelectFilter::make('posting_status')->options([
                    'not_posted'          => 'Not Posted',
                    'draft'               => 'Draft',
                    'review'              => 'Review',
                    'posted'              => 'Posted',
                    'matched_do_not_post' => 'Matched — Do Not Post',
                    'do_not_post'         => 'Do Not Post',
                ]),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (BankTransactionMapping $record) => $record->move_id === null
                    && ! in_array($record->posting_status, [BankPostingStatus::Posted, BankPostingStatus::MatchedDoNotPost], true))
                    ->authorize(AccountingPermissions::ReviewBankTransactions),
                Action::make('submit_approval')
                    ->label('Submit for approval')
                    ->authorize(AccountingPermissions::ReviewBankTransactions)
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (BankTransactionMapping $record) => $record->transfer_match_id === null
                        && $record->review_status !== BankReviewStatus::Posted
                        && $record->review_status !== BankReviewStatus::Approved
                        && app(BankMappingService::class)->requiresConfiguredApproval($record))
                    ->action(function (BankTransactionMapping $record): void {
                        $request = app(BankMappingService::class)->submit($record, Auth::user());
                        Notification::make()->success()->title("Approval request APR-{$request->id} is in the shared approval queue.")->send();
                    }),
                Action::make('approve')
                    ->authorize(AccountingPermissions::ReviewBankTransactions)
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (BankTransactionMapping $record) => $record->transfer_match_id === null && $record->review_status !== BankReviewStatus::Posted)
                    ->action(function (BankTransactionMapping $record): void {
                        app(BankMappingService::class)->approve($record, Auth::user());
                        Notification::make()->success()->title('Mapping approved; learned rule updated.')->send();
                    }),
                Action::make('approveTransfer')
                    ->authorize(AccountingPermissions::ReviewBankTransactions)
                    ->label('Approve transfer')
                    ->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (BankTransactionMapping $record) => $record->transferMatch?->status === 'suggested'
                        && $record->transferMatch?->outgoing_statement_line_id === $record->statement_line_id)
                    ->action(function (BankTransactionMapping $record): void {
                        app(BankTransferMatchingService::class)->approve($record->transferMatch, Auth::user());
                        Notification::make()->success()->title('Transfer pair approved.')->send();
                    }),
                Action::make('draft')
                    ->authorize(AccountingPermissions::GenerateJournal)
                    ->label('Generate draft')
                    ->icon('heroicon-o-document-plus')
                    ->visible(fn (BankTransactionMapping $record) => $record->move_id === null
                        && ($record->review_status === BankReviewStatus::Approved || $record->transferMatch?->status === 'approved'))
                    ->action(function (BankTransactionMapping $record): void {
                        app(BankJournalService::class)->createDraft($record);
                        Notification::make()->success()->title('Balanced draft journal created.')->send();
                    }),
                Action::make('post')
                    ->authorize(AccountingPermissions::PostJournal)
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (BankTransactionMapping $record) => $record->posting_status === BankPostingStatus::Draft)
                    ->action(function (BankTransactionMapping $record): void {
                        app(BankJournalService::class)->post($record, Auth::user());
                        Notification::make()->success()->title('Journal posted to the ledger.')->send();
                    }),
                Action::make('doNotPost')
                    ->authorize(AccountingPermissions::ReviewBankTransactions)
                    ->label('Do not post')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (BankTransactionMapping $record) => $record->move_id === null)
                    ->action(fn (BankTransactionMapping $record) => $record->update([
                        'review_status'  => BankReviewStatus::DoNotPost,
                        'posting_status' => BankPostingStatus::DoNotPost,
                        'reviewer_id'    => Auth::id(),
                        'reviewed_at'    => now(),
                    ])),
                Action::make('reject')
                    ->authorize(AccountingPermissions::ReviewBankTransactions)
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (BankTransactionMapping $record) => $record->move_id === null
                        && ! in_array($record->review_status, [BankReviewStatus::Rejected, BankReviewStatus::Posted], true))
                    ->action(fn (BankTransactionMapping $record) => $record->update([
                        'review_status'  => BankReviewStatus::Rejected,
                        'posting_status' => BankPostingStatus::DoNotPost,
                        'reviewer_id'    => Auth::id(),
                        'reviewed_at'    => now(),
                    ])),
            ])
            ->defaultSort('statement_line_id');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(AccountingPermissions::BankTransactions) ?? false;
    }

    public static function getPages(): array
    {
        return ['index' => ListBankTransactionMappings::route('/')];
    }
}
