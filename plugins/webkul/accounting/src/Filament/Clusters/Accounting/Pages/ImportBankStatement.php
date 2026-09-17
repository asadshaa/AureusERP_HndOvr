<?php

namespace Webkul\Accounting\Filament\Clusters\Accounting\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Filament\Clusters\Accounting;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Account\CanonicalAccountCreationService;
use Webkul\Accounting\Services\Bank\BankJournalCreationService;
use Webkul\Accounting\Services\Bank\BankStatementImportService;
use Webkul\Accounting\Services\Bank\BankStatementParserRegistry;
use Webkul\Accounting\Services\Bank\BankStatementPreviewService;
use Webkul\Accounting\Services\Coa\CoaUploadPathResolver;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class ImportBankStatement extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms;

    protected string $view = 'accounting::filament.clusters.accounting.pages.import-bank-statement';

    protected static ?string $cluster = Accounting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    public ?array $preview = null;

    protected static function getPagePermission(): ?string
    {
        return AccountingPermissions::ImportBankStatementPage;
    }

    public static function getNavigationLabel(): string
    {
        return 'Import Bank Statement';
    }

    public function getTitle(): string
    {
        return 'Import Bank Statement';
    }

    public function mount(): void
    {
        $company = Auth::user()?->defaultCompany;
        $this->form->fill([
            'company_id'  => $company?->id,
            'currency_id' => $company?->currency_id,
        ]);
    }

    protected function getFormSchema(): array
    {
        $companyId = Auth::user()?->default_company_id;
        $company = Company::query()->findOrFail($companyId);

        return [
            Section::make('Statement file')->schema([
                FileUpload::make('file')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->disk('local')
                    ->directory('bank-statement-imports')
                    ->storeFileNamesIn('file_original_name')
                    ->required(),
                Select::make('parser')
                    ->label('Bank format')
                    ->options(fn () => app(BankStatementParserRegistry::class)->options())
                    ->placeholder('Auto-detect')
                    ->searchable(),
                TextInput::make('sheet_name')
                    ->label('Worksheet name')
                    ->helperText('Optional. Use this when a workbook contains more than one bank statement sheet.'),
            ]),
            Section::make('Accounting target')->columns(2)->schema([
                Select::make('company_id')->options(Company::query()->whereKey($companyId)->pluck('name', 'id'))->required()->disabled()->dehydrated(),
                Select::make('currency_id')
                    ->label('Statement currency')
                    ->required()
                    ->searchable()
                    ->live()
                    ->helperText('Only currencies enabled for this company are available. A currency detected in the file is retained for audit and may be overridden here.')
                    ->options(fn (): array => Currency::query()
                        ->active()
                        ->whereHas('enabledCompanies', fn ($query) => $query
                            ->where('companies.id', $companyId)
                            ->where('accounting_company_currencies.transaction_enabled', true))
                        ->orderBy('display_order')->limit(50)->get()
                        ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name])->all())
                    ->getSearchResultsUsing(fn (string $search): array => Currency::query()
                        ->active()
                        ->whereHas('enabledCompanies', fn ($query) => $query
                            ->where('companies.id', $companyId)
                            ->where('accounting_company_currencies.transaction_enabled', true))
                        ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%"))
                        ->orderBy('display_order')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Currency::query()
                        ->active()
                        ->whereKey($value)
                        ->whereHas('enabledCompanies', fn ($query) => $query
                            ->where('companies.id', $companyId)
                            ->where('accounting_company_currencies.transaction_enabled', true))
                        ->first()?->display_name),
                Select::make('journal_id')
                    ->label('Bank journal')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => Journal::query()->where('company_id', $companyId)->where('type', JournalType::BANK)
                        ->orderBy('name')->limit(50)->get()->mapWithKeys(fn (Journal $journal): array => [$journal->id => "{$journal->code} {$journal->name}"])->all())
                    ->getSearchResultsUsing(fn (string $search): array => Journal::query()
                        ->where('company_id', $companyId)
                        ->where('type', JournalType::BANK)
                        ->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                        ->limit(50)
                        ->get()->mapWithKeys(fn (Journal $journal): array => [$journal->id => "{$journal->code} {$journal->name}"])
                        ->all())
                    ->getOptionLabelUsing(function ($value) use ($companyId): ?string {
                        $journal = Journal::query()->where('company_id', $companyId)->where('type', JournalType::BANK)->find($value);

                        return $journal ? "{$journal->code} {$journal->name}" : null;
                    })
                    ->createOptionForm(fn (Get $get): array => [
                        TextInput::make('name')->label('Bank journal name')->required()->maxLength(255),
                        TextInput::make('code')->label('Journal code')->required()->maxLength(20),
                        Select::make('company_id')->options([$company->id => $company->name])->default($company->id)->disabled()->dehydrated()->required(),
                        Select::make('currency_id')->options(Currency::query()->whereKey($get('currency_id'))->get()
                            ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name]))
                            ->default($get('currency_id'))->disabled()->dehydrated()->required(),
                        Select::make('default_account_id')->label('Linked Bank GL')->searchable()->required()
                            ->options(fn (): array => app(CanonicalAccountCreationService::class)->bankAccountQuery($company, (int) $get('currency_id'))
                                ->orderBy('code')->limit(50)->get()->mapWithKeys(fn (Account $account): array => [$account->id => trim("{$account->code} {$account->name}")])->all())
                            ->getSearchResultsUsing(fn (string $search): array => app(CanonicalAccountCreationService::class)
                                ->bankAccountQuery($company, (int) $get('currency_id'))
                                ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                                ->orderBy('code')->limit(50)->get()->mapWithKeys(fn (Account $account): array => [$account->id => trim("{$account->code} {$account->name}")])->all())
                            ->getOptionLabelUsing(function ($value) use ($company, $get): ?string {
                                $account = app(CanonicalAccountCreationService::class)->bankAccountQuery($company, (int) $get('currency_id'))->find($value);

                                return $account ? trim("{$account->code} {$account->name}") : null;
                            }),
                        Toggle::make('is_active')->label('Active/on dashboard')->default(true),
                    ])
                    ->createOptionAction(fn (Action $action) => $action->modalHeading('Create Bank Journal')
                        ->visible(Auth::user()?->can(AccountingPermissions::CreateBankJournal) ?? false))
                    ->createOptionUsing(function (array $data) use ($company): int {
                        abort_unless(Auth::user()?->can(AccountingPermissions::CreateBankJournal), 403);

                        return app(BankJournalCreationService::class)->create($company, $data)->id;
                    }),
                Select::make('bank_gl_account_id')
                    ->label('Bank GL account')
                    ->required()
                    ->searchable()
                    ->options(fn (Get $get): array => app(CanonicalAccountCreationService::class)
                        ->bankAccountQuery($company, (int) $get('currency_id'))
                        ->orderBy('code')->limit(50)->get()
                        ->mapWithKeys(fn (Account $account): array => [$account->id => trim("{$account->code} {$account->name}")])->all())
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => app(CanonicalAccountCreationService::class)
                        ->bankAccountQuery($company, (int) $get('currency_id'))
                        ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                        ->orderBy('code')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Account $account): array => [$account->id => "{$account->code} {$account->name}"])
                        ->all())
                    ->getOptionLabelUsing(function ($value, Get $get) use ($company): ?string {
                        $account = app(CanonicalAccountCreationService::class)
                            ->bankAccountQuery($company, (int) $get('currency_id'))
                            ->find($value);

                        return $account ? trim("{$account->code} {$account->name}") : null;
                    })
                    ->createOptionForm(fn (Get $get): array => [
                        TextInput::make('code')->label('GL code')->required()->maxLength(64),
                        TextInput::make('name')->label('Title')->required()->maxLength(255),
                        Select::make('currency_id')
                            ->options(Currency::query()->whereKey($get('currency_id'))->get()
                                ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name]))
                            ->default($get('currency_id'))->disabled()->dehydrated()->required(),
                        Select::make('parent_id')
                            ->label('Parent account')
                            ->searchable()
                            ->helperText('Choose an active Asset or Bank/Cash group, or create one here.')
                            ->options(fn (): array => app(CanonicalAccountCreationService::class)
                                ->bankParentAccountQuery($company, (int) $get('currency_id'))
                                ->orderBy('code')->limit(50)->get()
                                ->mapWithKeys(fn (Account $account): array => [$account->id => trim("{$account->code} {$account->name}")])->all())
                            ->getSearchResultsUsing(fn (string $search): array => app(CanonicalAccountCreationService::class)
                                ->bankParentAccountQuery($company, (int) $get('currency_id'))
                                ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                                ->orderBy('code')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Account $account): array => [$account->id => trim("{$account->code} {$account->name}")])
                                ->all())
                            ->getOptionLabelUsing(function ($value) use ($company, $get): ?string {
                                $account = app(CanonicalAccountCreationService::class)
                                    ->bankParentAccountQuery($company, (int) $get('currency_id'))
                                    ->find($value);

                                return $account ? trim("{$account->code} {$account->name}") : null;
                            })
                            ->createOptionForm([
                                TextInput::make('name')->label('Parent title')->required()->maxLength(255),
                                TextInput::make('code')->label('Parent GL code')->maxLength(64),
                                Select::make('company_id')
                                    ->label('Company')
                                    ->options([$company->id => $company->name])
                                    ->default($company->id)
                                    ->disabled()
                                    ->dehydrated()
                                    ->required(),
                                Select::make('currency_id')
                                    ->label('Currency')
                                    ->options(Currency::query()->whereKey($get('currency_id'))->get()
                                        ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name]))
                                    ->default($get('currency_id'))
                                    ->disabled()
                                    ->dehydrated()
                                    ->required(),
                                Select::make('account_type')
                                    ->label('Parent type')
                                    ->options([
                                        AccountType::ASSET_CURRENT->value => AccountType::ASSET_CURRENT->getLabel(),
                                        AccountType::ASSET_CASH->value    => AccountType::ASSET_CASH->getLabel(),
                                    ])
                                    ->default(AccountType::ASSET_CURRENT->value)
                                    ->required(),
                                Toggle::make('is_group')
                                    ->label('Non-postable group account')
                                    ->default(true)
                                    ->disabled()
                                    ->dehydrated(),
                            ])
                            ->createOptionAction(fn (Action $action) => $action
                                ->modalHeading('Create Bank Parent Account')
                                ->visible(Auth::user()?->can(AccountingPermissions::CreateBankAccount) ?? false))
                            ->createOptionUsing(function (array $data) use ($company): int {
                                abort_unless(Auth::user()?->can(AccountingPermissions::CreateBankAccount), 403);

                                return app(CanonicalAccountCreationService::class)
                                    ->createBankParentAccount($company, $data)
                                    ->id;
                            }),
                        TextInput::make('bank_name')->required()->maxLength(255),
                        TextInput::make('bank_account_number')->required()->maxLength(255),
                        TextInput::make('iban')->maxLength(255),
                        TextInput::make('branch_reference')->label('Branch/reference')->maxLength(255),
                        Toggle::make('active')->default(true)->disabled()->dehydrated(),
                    ])
                    ->createOptionAction(fn (Action $action) => $action
                        ->modalHeading('Create Bank GL')
                        ->visible(Auth::user()?->can(AccountingPermissions::CreateBankAccount) ?? false))
                    ->createOptionUsing(function (array $data): int {
                        abort_unless(Auth::user()?->can(AccountingPermissions::CreateBankAccount), 403);

                        return app(CanonicalAccountCreationService::class)
                            ->createBankAccount(Company::query()->findOrFail(Auth::user()?->default_company_id), $data)
                            ->id;
                    }),
            ]),
        ];
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview conversion')
                ->icon('heroicon-o-eye')
                ->authorize(AccountingPermissions::ImportBankStatementPage)
                ->action(function (): void {
                    try {
                        $state = $this->form->getState();
                        $path = app(CoaUploadPathResolver::class)->resolve($state['file'] ?? null, $state['file_original_name'] ?? null);
                        $this->preview = app(BankStatementPreviewService::class)->preview(
                            path: $path,
                            company: Company::findOrFail($state['company_id']),
                            currency: Currency::findOrFail($state['currency_id']),
                            parserKey: $state['parser'] ?: null,
                            sheetName: $state['sheet_name'] ?: null,
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()->danger()->title('Preview failed')->body($exception->getMessage())->persistent()->send();
                    }
                }),
            Action::make('import')
                ->authorize(AccountingPermissions::ImportBankStatementPage)
                ->label('Validate and import')
                ->icon('heroicon-o-check')
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        $state = $this->form->getState();
                        $path = app(CoaUploadPathResolver::class)->resolve($state['file'] ?? null, $state['file_original_name'] ?? null);
                        $statement = app(BankStatementImportService::class)->import(
                            path: $path,
                            company: Company::findOrFail($state['company_id']),
                            journal: Journal::findOrFail($state['journal_id']),
                            bankGlAccount: Account::findOrFail($state['bank_gl_account_id']),
                            currency: Currency::findOrFail($state['currency_id']),
                            parserKey: $state['parser'] ?: null,
                            sheetName: $state['sheet_name'] ?: null,
                            originalFilename: $state['file_original_name'] ?? null,
                        );

                        $failed = $statement->import_status === BankImportStatus::ReconciliationFailed->value;
                        $fsTagNote = $this->summarizeFsTagIssues($statement);
                        $needsAttention = $failed || $fsTagNote !== null;

                        $body = $failed
                            ? collect($statement->validation_errors)->pluck('message')->implode(' ')
                            : "{$statement->lines->count()} transactions imported as unposted mappings.";

                        if ($fsTagNote !== null) {
                            $body .= ' '.$fsTagNote;
                        }

                        Notification::make()
                            ->title($failed
                                ? 'Imported for review — reconciliation failed'
                                : ($fsTagNote !== null ? 'Imported — some FS Tags need attention' : 'Bank statement validated and imported'))
                            ->body($body)
                            ->{$needsAttention ? 'warning' : 'success'}()
                            ->persistent()
                            ->send();
                    } catch (\Throwable $exception) {
                        Notification::make()->danger()->title('Bank statement import failed')->body($exception->getMessage())->persistent()->send();
                    }
                }),
        ];
    }

    /**
     * Plain-language summary of anything wrong with FS Tags on this import,
     * shown right in the import notification instead of leaving the user to
     * discover it later on the Bank Transaction Mapping screen. Returns null
     * when there is nothing worth mentioning.
     */
    private function summarizeFsTagIssues(BankStatement $statement): ?string
    {
        $company = $statement->company;

        // If this company has never set up any FS Tags, it isn't using the
        // feature — a missing column is expected, not a mistake, so stay
        // quiet rather than warn about something the user never intended.
        $usesFsTags = $company && FsTag::query()->where('company_id', $company->id)->exists();

        if ($usesFsTags && BankStatementImportService::findFsTagColumnIndex($statement->raw_header ?? []) === false) {
            return 'No "FS Tag" column was recognized in this file, so none of these transactions were tagged. '
                .'If the file has one, check its header spelling.';
        }

        $unrecognized = $statement->lines
            ->pluck('mapping')
            ->filter(fn ($mapping) => $mapping && $mapping->fs_tag_id === null && $mapping->fs_tag_raw_code !== null);

        if ($unrecognized->isEmpty()) {
            return null;
        }

        $examples = $unrecognized->pluck('fs_tag_raw_code')->unique()->take(3)->implode('", "');
        $count = $unrecognized->count();
        $plural = $count === 1 ? 'transaction has' : 'transactions have';

        return "{$count} {$plural} an FS Tag we didn't recognize (e.g. \"{$examples}\") — open Bank Transaction Mapping to review and fix them.";
    }
}
