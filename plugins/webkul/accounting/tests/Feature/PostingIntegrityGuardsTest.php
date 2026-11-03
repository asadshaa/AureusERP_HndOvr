<?php

/**
 * Regression coverage for the accounting-side posting integrity guards:
 * posted_by/posted_at on direct-write posting paths, period locks on
 * opening-balance and CoA migration journals, bank matching residuals,
 * transfer detection respecting reviewer decisions, the company-scoped
 * clearing-account guard, FX approval re-dating, the ReconciliationFailed
 * statement recovery path, the bank-mapping approval threshold and the
 * posted journal entry delete policy.
 */

use App\Console\Commands\SeedAccountingDemo;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Account\Models\Payment;
use Webkul\Account\Models\PaymentMethodLine;
use Webkul\Account\Services\PeriodLockService;
use Webkul\Accounting\Data\Coa\CoaRow;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Enums\ExchangeRateApprovalStatus;
use Webkul\Accounting\Enums\ExchangeRateSource;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Enums\ImportFailurePolicy;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Models\CoaImportBatch;
use Webkul\Accounting\Models\ExchangeRate;
use Webkul\Accounting\Models\ImportProfile;
use Webkul\Accounting\Models\ImportRun;
use Webkul\Accounting\Models\JournalEntry;
use Webkul\Accounting\Models\JournalItem;
use Webkul\Accounting\Policies\JournalEntryPolicy;
use Webkul\Accounting\Policies\JournalItemPolicy;
use Webkul\Accounting\Services\Bank\BankJournalService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\Bank\BankMatchingPriorityService;
use Webkul\Accounting\Services\Bank\BankStatementImportService;
use Webkul\Accounting\Services\Bank\BankTransferMatchingService;
use Webkul\Accounting\Services\Coa\MigrationJournalService;
use Webkul\Accounting\Services\Currency\ExchangeRateApprovalService;
use Webkul\Accounting\Services\Import\ImportExecutionService;
use Webkul\Accounting\Services\Import\ImportPreviewService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

function piFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $account = function (string $code, AccountType $type, bool $reconcile = false) use ($company, $currency, $user): Account {
        $account = Account::factory()->create([
            'code'        => $code.$company->id, 'name' => $code, 'account_type' => $type,
            'currency_id' => $currency->id, 'creator_id' => $user->id, 'reconcile' => $reconcile,
            'is_group'    => false, 'deprecated' => false,
        ]);
        $account->companies()->attach($company->id);

        return $account;
    };

    $bank = $account('PIBANK-', AccountType::ASSET_CASH, true);
    $otherBank = $account('PIBANK2-', AccountType::ASSET_CASH, true);
    $receivable = $account('PIAR-', AccountType::ASSET_RECEIVABLE, true);
    $revenue = $account('PIREV-', AccountType::INCOME);
    $expense = $account('PIEXP-', AccountType::EXPENSE);
    $equity = $account('PIEQ-', AccountType::EQUITY);

    $journal = fn (string $code, JournalType $type, Account $default): Journal => Journal::factory()->create([
        'company_id'         => $company->id, 'currency_id' => $currency->id, 'creator_id' => $user->id,
        'default_account_id' => $default->id, 'code' => $code.$company->id, 'name' => $code, 'type' => $type,
    ]);

    $saleJournal = $journal('PIS', JournalType::SALE, $revenue);
    $bankJournal = $journal('PIB', JournalType::BANK, $bank);
    $generalJournal = $journal('PIG', JournalType::GENERAL, $expense);

    $customer = Partner::query()->create([
        'company_id'                     => $company->id, 'account_type' => 'company', 'sub_type' => 'customer',
        'reference'                      => 'PI-CUSTOMER-'.$company->id, 'name' => 'Posting integrity customer', 'customer_rank' => 1,
        'property_account_receivable_id' => $receivable->id,
    ]);

    return compact(
        'currency', 'company', 'user', 'bank', 'otherBank', 'receivable', 'revenue', 'expense', 'equity',
        'saleJournal', 'bankJournal', 'generalJournal', 'customer',
    );
}

function piBankMapping(array $fx, float $amount, string $direction, array $line = [], array $statement = [], array $mapping = []): BankTransactionMapping
{
    $isCredit = $direction === 'credit';
    $debit = $isCredit ? 0 : $amount;
    $credit = $isCredit ? $amount : 0;
    $tag = uniqid();

    $statementModel = BankStatement::query()->create(array_merge([
        'company_id'              => $fx['company']->id, 'journal_id' => $fx['bankJournal']->id,
        'currency_id'             => $fx['currency']->id, 'company_currency_id' => $fx['currency']->id,
        'bank_gl_account_id'      => $fx['bank']->id, 'name' => 'PI statement '.$tag, 'reference' => 'PI-STMT-'.$tag,
        'date'                    => '2026-10-15', 'statement_start_date' => '2026-10-15', 'statement_end_date' => '2026-10-15',
        'opening_balance'         => 0, 'total_debits' => $debit, 'total_credits' => $credit, 'closing_balance' => $credit - $debit,
        'balance_start'           => 0, 'balance_end' => $credit - $debit, 'balance_end_real' => $credit - $debit,
        'company_opening_balance' => 0, 'company_total_debits' => $debit, 'company_total_credits' => $credit,
        'company_closing_balance' => $credit - $debit, 'conversion_status' => ConversionStatus::Complete,
        'bank_name'               => 'Integrity Bank', 'bank_account_number' => 'PI-ACCT-'.$tag, 'account_title' => 'Operating',
        'original_filename'       => 'bank.csv', 'file_hash' => hash('sha256', 'pi-'.$tag),
        'parser'                  => 'test', 'import_status' => BankImportStatus::Validated->value,
    ], $statement));

    $lineModel = BankStatementLine::query()->create(array_merge([
        'journal_id'              => $fx['bankJournal']->id, 'company_id' => $fx['company']->id,
        'statement_id'            => $statementModel->id, 'currency_id' => $fx['currency']->id,
        'original_currency_id'    => $fx['currency']->id, 'company_currency_id' => $fx['currency']->id,
        'transaction_date'        => '2026-10-15', 'value_date' => '2026-10-15',
        'description'             => 'Bank line '.$tag, 'reference' => 'PI-REF-'.$tag,
        'debit'                   => $debit, 'credit' => $credit, 'original_debit' => $debit, 'original_credit' => $credit,
        'original_signed_amount'  => $credit - $debit, 'company_debit' => $debit, 'company_credit' => $credit,
        'company_signed_amount'   => $credit - $debit, 'amount' => $credit - $debit, 'amount_currency' => $credit - $debit,
        'amount_residual'         => $credit - $debit, 'exchange_rate' => 1, 'rate_date' => '2026-10-15',
        'rate_source'             => 'identity', 'rate_type' => 'transaction', 'conversion_status' => ConversionStatus::Complete,
        'transaction_type'        => $direction, 'source_row' => 1,
        'transaction_fingerprint' => hash('sha256', 'pi-line-'.$tag),
        'import_status'           => BankImportStatus::Validated->value,
    ], $line));

    return BankTransactionMapping::query()->create(array_merge([
        'company_id'          => $fx['company']->id, 'statement_line_id' => $lineModel->id,
        'bank_gl_account_id'  => $statementModel->bank_gl_account_id, 'original_currency_id' => $fx['currency']->id,
        'company_currency_id' => $fx['currency']->id, 'exchange_rate' => 1, 'rate_date' => '2026-10-15',
        'rate_source'         => 'identity', 'rate_type' => 'transaction', 'conversion_status' => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::Unmapped, 'posting_status' => BankPostingStatus::NotPosted,
    ], $mapping));
}

function piInvoice(array $fx, string $bookingId, float $amount): Move
{
    $invoice = Move::query()->create([
        'company_id'  => $fx['company']->id, 'journal_id' => $fx['saleJournal']->id, 'partner_id' => $fx['customer']->id,
        'currency_id' => $fx['currency']->id, 'move_type' => MoveType::OUT_INVOICE, 'state' => MoveState::DRAFT,
        'date'        => '2026-10-01', 'invoice_date' => '2026-10-01', 'invoice_date_due' => '2026-10-31',
        'booking_id'  => $bookingId,
    ]);
    MoveLine::query()->create([
        'move_id'      => $invoice->id, 'account_id' => $fx['revenue']->id, 'partner_id' => $fx['customer']->id,
        'currency_id'  => $fx['currency']->id, 'display_type' => DisplayType::PRODUCT,
        'name'         => 'Freight', 'quantity' => 1, 'price_unit' => $amount,
    ]);

    return AccountFacade::confirmMove($invoice->fresh());
}

function piPayment(array $fx, Company $company, Journal $journal, PaymentType $type, string $state, string $reference, float $amount, Account $outstanding): Payment
{
    $lines = $type === PaymentType::RECEIVE
        ? Journal::getDefaultInboundPaymentMethodLines()
        : Journal::getDefaultOutboundPaymentMethodLines();

    foreach ($lines as $data) {
        PaymentMethodLine::query()->firstOrCreate(array_merge($data, ['journal_id' => $journal->id]));
    }

    return Payment::query()->create([
        'company_id'             => $company->id,
        'journal_id'             => $journal->id,
        'payment_method_line_id' => PaymentMethodLine::query()->where('journal_id', $journal->id)->value('id'),
        'currency_id'            => $fx['currency']->id,
        'payment_type'           => $type,
        'partner_type'           => $type === PaymentType::RECEIVE ? 'customer' : 'supplier',
        'state'                  => $state,
        'date'                   => '2026-10-15',
        'amount'                 => $amount,
        'payment_reference'      => $reference,
        'outstanding_account_id' => $outstanding->id,
        'destination_account_id' => $fx['receivable']->id,
    ]);
}

function piGrant(User $user, array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->givePermissionTo($permissions);

    return $user->fresh();
}

function piCsv(array $headers, array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'aureus-pi-import-');
    $handle = fopen($path, 'w');
    fputcsv($handle, $headers);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

function piOpeningRun(array $fx, string $date): ImportRun
{
    $profile = ImportProfile::query()->create([
        'company_id'     => $fx['company']->id,
        'name'           => 'PI opening balances '.uniqid(),
        'entity_type'    => 'opening_balance',
        'file_type'      => 'csv',
        'failure_policy' => ImportFailurePolicy::RejectFile->value,
        'version'        => 1,
        'is_active'      => true,
    ]);
    $profile->mappings()->createMany([
        ['source_header' => 'Journal Code', 'target_field' => 'journal_code', 'position' => 1, 'is_required' => true],
        ['source_header' => 'GL Code', 'target_field' => 'gl_code', 'position' => 2, 'is_required' => true],
        ['source_header' => 'Date', 'target_field' => 'date', 'position' => 3, 'is_required' => true],
        ['source_header' => 'Debit', 'target_field' => 'debit', 'position' => 4],
        ['source_header' => 'Credit', 'target_field' => 'credit', 'position' => 5],
        ['source_header' => 'Currency', 'target_field' => 'currency', 'position' => 6],
    ]);

    $path = piCsv(['Journal Code', 'GL Code', 'Date', 'Debit', 'Credit', 'Currency'], [
        [$fx['generalJournal']->code, $fx['bank']->code, $date, '1000', '0', 'PKR'],
        [$fx['generalJournal']->code, $fx['equity']->code, $date, '0', '1000', 'PKR'],
    ]);

    try {
        return app(ImportPreviewService::class)->preview($profile, $path, 'opening.csv', $fx['user']->id);
    } finally {
        @unlink($path);
    }
}

/** @return array<int, CoaRow> */
function piCoaRows(array $fx): array
{
    $row = fn (int $sheetRow, Account $account, float $openingDebit, float $openingCredit, float $movementDebit, float $movementCredit): CoaRow => new CoaRow(
        sheetRow: $sheetRow, nature: 'B/S', classifications: ['Assets'], code: (string) $account->code, title: (string) $account->name,
        openingDebit: $openingDebit, openingCredit: $openingCredit, movementDebit: $movementDebit, movementCredit: $movementCredit,
        adjustmentDebit: 0, adjustmentCredit: 0, closingDebit: 0, closingCredit: 0,
    );

    return [
        $row(1, $fx['bank'], 500, 0, 200, 0),
        $row(2, $fx['equity'], 0, 500, 0, 200),
    ];
}

function piCoaBatch(array $fx): CoaImportBatch
{
    return CoaImportBatch::query()->create([
        'company_id' => $fx['company']->id, 'currency_id' => $fx['currency']->id,
        'mode'       => 'with_journals', 'status' => 'pending', 'options' => [],
    ]);
}

/* -------------------------------------------------------------------------
 * Bank journal posting (item 7)
 * ---------------------------------------------------------------------- */

it('records posted_by, posted_at and posted_before when a bank mapping journal is posted', function (): void {
    $fx = piFixture();
    $mapping = piBankMapping($fx, 400, 'debit', mapping: ['offset_account_id' => $fx['expense']->id]);
    $mapping = app(BankMappingService::class)->approve($mapping, $fx['user'], false);

    $move = app(BankJournalService::class)->post($mapping, $fx['user']);
    $row = DB::table('accounts_account_moves')->where('id', $move->id)->first();

    expect($row->state)->toBe(MoveState::POSTED->value)
        ->and((int) $row->posted_by_id)->toBe($fx['user']->id)
        ->and($row->posted_at)->not->toBeNull()
        ->and((bool) $row->posted_before)->toBeTrue();
});

/* -------------------------------------------------------------------------
 * Bank matching residuals (item 8)
 * ---------------------------------------------------------------------- */

it('does not suggest an invoice already held by an existing suggested mapping in a later run', function (): void {
    $fx = piFixture();
    $invoice = piInvoice($fx, 'PI-BKG-HELD', 900);

    $first = piBankMapping($fx, 900, 'credit', line: ['description' => 'Booking PI-BKG-HELD']);
    app(BankMatchingPriorityService::class)->run($fx['company']->id);
    expect($first->fresh()->matched_move_id)->toBe($invoice->id)
        ->and($first->fresh()->review_status)->toBe(BankReviewStatus::Suggested);

    $second = piBankMapping($fx, 900, 'credit', line: ['description' => 'Booking PI-BKG-HELD']);
    $result = app(BankMatchingPriorityService::class)->run($fx['company']->id);

    expect($result['obligations'])->toBe(0)
        ->and($second->fresh()->matched_move_id)->toBeNull()
        ->and($second->fresh()->review_status)->not->toBe(BankReviewStatus::Suggested);
});

it('only matches a registered payment in the bank line direction and in a posted state', function (string $direction, PaymentType $type, string $state, int $expectedMatches): void {
    $fx = piFixture();
    $outstanding = Account::factory()->create([
        'code'        => 'PIOUT-'.uniqid(), 'name' => 'Outstanding', 'account_type' => AccountType::ASSET_CURRENT,
        'currency_id' => $fx['currency']->id, 'is_group' => false, 'deprecated' => false, 'reconcile' => true,
    ]);
    $outstanding->companies()->attach($fx['company']->id);

    $reference = 'PI-PAY-'.uniqid();
    piPayment($fx, $fx['company'], $fx['bankJournal'], $type, $state, $reference, 300, $outstanding);
    $mapping = piBankMapping($fx, 300, $direction, line: ['reference' => $reference]);

    $result = app(BankMatchingPriorityService::class)->run($fx['company']->id);

    expect($result['payments'])->toBe($expectedMatches)
        ->and($mapping->fresh()->match_type === 'payment')->toBe($expectedMatches === 1);
})->with([
    'inbound payment, money in'            => ['credit', PaymentType::RECEIVE, 'in_process', 1],
    'inbound payment, money out'           => ['debit', PaymentType::RECEIVE, 'in_process', 0],
    'outbound payment, money in'           => ['credit', PaymentType::SEND, 'in_process', 0],
    'draft inbound payment, money in'      => ['credit', PaymentType::RECEIVE, 'draft', 0],
    'cancelled inbound payment, money in'  => ['credit', PaymentType::RECEIVE, 'canceled', 0],
]);

/* -------------------------------------------------------------------------
 * Transfer detection respects reviewer decisions (item 9)
 * ---------------------------------------------------------------------- */

it('does not turn a line a reviewer rejected or marked do-not-post into a transfer', function (array $reviewerDecision): void {
    $fx = piFixture();
    $outgoing = piBankMapping($fx, 250, 'debit', line: ['description' => 'Transfer to payroll account'], mapping: $reviewerDecision);
    $incoming = piBankMapping($fx, 250, 'credit', line: ['description' => 'Transfer from operating account'], statement: ['bank_gl_account_id' => $fx['otherBank']->id]);

    $matches = app(BankTransferMatchingService::class)->detect($fx['company']->id);

    expect($matches)->toBe([])
        ->and($outgoing->fresh()->transfer_match_id)->toBeNull()
        ->and($outgoing->fresh()->review_status->value)->toBe($reviewerDecision['review_status']->value)
        ->and($incoming->fresh()->transfer_match_id)->toBeNull();
})->with([
    'rejected'    => [['review_status' => BankReviewStatus::Rejected]],
    'do not post' => [['review_status' => BankReviewStatus::DoNotPost, 'posting_status' => BankPostingStatus::DoNotPost]],
]);

it('still detects a transfer between two unreviewed lines', function (): void {
    $fx = piFixture();
    piBankMapping($fx, 250, 'debit', line: ['description' => 'Transfer to payroll account']);
    piBankMapping($fx, 250, 'credit', line: ['description' => 'Transfer from operating account'], statement: ['bank_gl_account_id' => $fx['otherBank']->id]);

    expect(app(BankTransferMatchingService::class)->detect($fx['company']->id))->toHaveCount(1);
});

/* -------------------------------------------------------------------------
 * Clearing-account guard is company scoped (item 10)
 * ---------------------------------------------------------------------- */

it('ignores another company\'s payments when deciding whether an account is a clearing account', function (): void {
    $fx = piFixture();
    $otherCompany = Company::factory()->create(['currency_id' => $fx['currency']->id, 'is_active' => true]);
    $shared = Account::factory()->create([
        'code'        => 'PISHARED-'.uniqid(), 'name' => 'Shared clearing', 'account_type' => AccountType::ASSET_CURRENT,
        'currency_id' => $fx['currency']->id, 'is_group' => false, 'deprecated' => false, 'reconcile' => true,
    ]);
    $shared->companies()->attach([$fx['company']->id, $otherCompany->id]);
    $otherJournal = Journal::factory()->create([
        'company_id' => $otherCompany->id, 'currency_id' => $fx['currency']->id, 'type' => JournalType::BANK,
        'code'       => 'PIOB'.$otherCompany->id, 'default_account_id' => $fx['bank']->id,
    ]);
    // Only the OTHER company uses the shared account as an inbound
    // clearing account; crediting it here must not be judged against that.
    piPayment($fx, $otherCompany, $otherJournal, PaymentType::RECEIVE, 'in_process', 'PI-OTHER-'.uniqid(), 999, $shared);

    $mapping = piBankMapping($fx, 500, 'credit', mapping: ['offset_account_id' => $shared->id]);
    $mapping = app(BankMappingService::class)->approve($mapping, $fx['user'], false);

    expect(app(BankJournalService::class)->post($mapping, $fx['user'])->state)->toBe(MoveState::POSTED);
});

it('still blocks a posting that would overdraw this company\'s own clearing account', function (): void {
    $fx = piFixture();
    $clearing = Account::factory()->create([
        'code'        => 'PICLR-'.uniqid(), 'name' => 'Outstanding receipts', 'account_type' => AccountType::ASSET_CURRENT,
        'currency_id' => $fx['currency']->id, 'is_group' => false, 'deprecated' => false, 'reconcile' => true,
    ]);
    $clearing->companies()->attach($fx['company']->id);
    piPayment($fx, $fx['company'], $fx['bankJournal'], PaymentType::RECEIVE, 'in_process', 'PI-OWN-'.uniqid(), 500, $clearing);

    $mapping = piBankMapping($fx, 500, 'credit', mapping: ['offset_account_id' => $clearing->id]);
    $mapping = app(BankMappingService::class)->approve($mapping, $fx['user'], false);

    expect(fn () => app(BankJournalService::class)->post($mapping, $fx['user']))
        ->toThrow(RuntimeException::class, 'payment-clearing account');
});

/* -------------------------------------------------------------------------
 * Bank mapping approval threshold (item 13)
 * ---------------------------------------------------------------------- */

it('applies the bank-mapping approval amount threshold to the absolute company amount', function (): void {
    $fx = piFixture();
    ApprovalWorkflow::query()->create([
        'company_id'     => $fx['company']->id, 'name' => 'High value bank mappings',
        'request_type'   => 'bank_transaction_mapping', 'minimum_amount' => 1000, 'is_active' => true,
    ]);

    $largeOutflow = piBankMapping($fx, 5000, 'debit');
    $largeInflow = piBankMapping($fx, 5000, 'credit');
    $small = piBankMapping($fx, 500, 'debit');
    $service = app(BankMappingService::class);

    expect($service->requiresConfiguredApproval($largeOutflow))->toBeTrue()
        ->and($service->requiresConfiguredApproval($largeInflow))->toBeTrue()
        ->and($service->requiresConfiguredApproval($small))->toBeFalse();

    $largeOutflow->update(['offset_account_id' => $fx['expense']->id]);
    expect(fn () => $service->approve($largeOutflow->fresh(), $fx['user'], false))
        ->toThrow(RuntimeException::class, 'requires a completed approval workflow');
});

/* -------------------------------------------------------------------------
 * ReconciliationFailed statements (item 12)
 * ---------------------------------------------------------------------- */

it('re-validates a failed statement once its stored figures reconcile', function (): void {
    $fx = piFixture();
    $mapping = piBankMapping($fx, 700, 'credit', statement: [
        'import_status'     => BankImportStatus::ReconciliationFailed->value,
        'validation_errors' => [['code' => 'header_reconciliation', 'message' => 'Opening + credits - debits is 600, not 700.', 'source_row' => null]],
    ], line: ['import_status' => BankImportStatus::ReconciliationFailed->value]);
    $statement = $mapping->statementLine->statement;

    $statement = app(BankStatementImportService::class)->revalidate($statement);

    expect($statement->import_status)->toBe(BankImportStatus::Validated->value)
        ->and($statement->validation_errors)->toBe([])
        ->and($statement->lines()->where('import_status', BankImportStatus::Validated->value)->count())->toBe(1);
});

it('keeps a statement failed when re-validation still finds differences', function (): void {
    $fx = piFixture();
    $mapping = piBankMapping($fx, 700, 'credit', statement: [
        'import_status' => BankImportStatus::ReconciliationFailed->value,
        'total_credits' => 900, 'closing_balance' => 900,
    ], line: ['import_status' => BankImportStatus::ReconciliationFailed->value]);

    $statement = app(BankStatementImportService::class)->revalidate($mapping->statementLine->statement);

    expect($statement->import_status)->toBe(BankImportStatus::ReconciliationFailed->value)
        ->and(collect($statement->validation_errors)->pluck('code')->all())->toContain('credit_total');
});

it('lets an authorised reviewer accept reconciliation differences with a reason, and audits it', function (): void {
    $fx = piFixture();
    $mapping = piBankMapping($fx, 700, 'credit', statement: [
        'import_status'     => BankImportStatus::ReconciliationFailed->value,
        'total_credits'     => 900, 'closing_balance' => 900,
        'validation_errors' => [['code' => 'duplicate_row', 'message' => 'Duplicate of source row 1.', 'source_row' => 2]],
    ], line: ['import_status' => BankImportStatus::ReconciliationFailed->value], mapping: ['offset_account_id' => $fx['revenue']->id]);
    $statement = $mapping->statementLine->statement;
    $service = app(BankStatementImportService::class);

    expect(fn () => $service->acceptReconciliationDifferences($statement, $fx['user'], 'Bank confirmed the duplicate row is a display artefact.'))
        ->toThrow(RuntimeException::class, 'permission');

    $reviewer = piGrant($fx['user'], [AccountingPermissions::ReviewBankTransactions]);

    expect(fn () => $service->acceptReconciliationDifferences($statement, $reviewer, 'ok'))
        ->toThrow(RuntimeException::class, 'reason');

    $accepted = $service->acceptReconciliationDifferences($statement, $reviewer, 'Bank confirmed the duplicate row is a display artefact.');

    expect($accepted->import_status)->toBe(BankImportStatus::Validated->value)
        ->and((int) $accepted->reconciliation_override_by_id)->toBe($reviewer->id)
        ->and($accepted->reconciliation_override_at)->not->toBeNull()
        ->and($accepted->reconciliation_override_reason)->toBe('Bank confirmed the duplicate row is a display artefact.')
        ->and(collect($accepted->reconciliation_override_errors)->pluck('code')->all())->toContain('duplicate_row')
        ->and($accepted->lines()->where('import_status', BankImportStatus::Validated->value)->count())->toBe(1);

    $mapping = app(BankMappingService::class)->approve($mapping->fresh(), $reviewer, false);
    expect(app(BankJournalService::class)->createDraft($mapping)->state)->toBe(MoveState::DRAFT);
});

/* -------------------------------------------------------------------------
 * Opening-balance imports (item 6)
 * ---------------------------------------------------------------------- */

it('records posted_by and posted_at on a posted opening-balance import', function (): void {
    $fx = piFixture();
    $run = piOpeningRun($fx, '2026-10-01');

    $run = app(ImportExecutionService::class)->confirm($run, $fx['user']->id);
    $move = Move::query()->where('accounting_source_type', ImportRun::class)->where('accounting_source_id', $run->id)->firstOrFail();

    expect($move->state)->toBe(MoveState::POSTED)
        ->and($move->posted_by_id)->toBe($fx['user']->id)
        ->and($move->posted_at)->not->toBeNull()
        ->and((bool) $move->posted_before)->toBeTrue();
});

it('refuses an opening-balance import dated in a locked period', function (): void {
    $fx = piFixture();
    app(PeriodLockService::class)->lockThrough($fx['company'], $fx['user'], now()->setDate(2026, 10, 31));
    $run = piOpeningRun($fx, '2026-10-01');

    expect(fn () => app(ImportExecutionService::class)->confirm($run, $fx['user']->id))
        ->toThrow(RuntimeException::class, 'closed accounting period');

    expect(Move::query()->where('company_id', $fx['company']->id)->whereNotNull('coa_migration_kind')->exists())->toBeFalse();
});

/* -------------------------------------------------------------------------
 * CoA migration journals (item 6)
 * ---------------------------------------------------------------------- */

it('asserts the period lock on CoA migration journals unless the initial-migration override is set', function (): void {
    $fx = piFixture();
    app(PeriodLockService::class)->lockThrough($fx['company'], $fx['user'], now()->setDate(2026, 10, 31));
    $service = app(MigrationJournalService::class);

    expect(fn () => $service->createForBatch(piCoaBatch($fx), piCoaRows($fx), $fx['company'], $fx['currency']->id, '2026-09-30', '2026-10-01', '2026-10-31'))
        ->toThrow(RuntimeException::class, 'closed accounting period');
    expect(Move::query()->where('company_id', $fx['company']->id)->whereNotNull('coa_migration_kind')->exists())->toBeFalse();

    test()->actingAs($fx['user']);
    $created = $service->createForBatch(piCoaBatch($fx), piCoaRows($fx), $fx['company'], $fx['currency']->id, '2026-09-30', '2026-10-01', '2026-10-31', overridePeriodLock: true);
    $opening = Move::query()->where('company_id', $fx['company']->id)->where('coa_migration_kind', 'opening')->firstOrFail();

    expect($created)->toBe(2)
        ->and($opening->state)->toBe(MoveState::POSTED)
        ->and($opening->posted_by_id)->toBe($fx['user']->id)
        ->and($opening->posted_at)->not->toBeNull();
});

it('reports, instead of silently skipping, a CoA opening journal blocked by an existing opening import', function (): void {
    $fx = piFixture();
    $run = app(ImportExecutionService::class)->confirm(piOpeningRun($fx, '2026-09-30'), $fx['user']->id);
    $existing = Move::query()->where('accounting_source_type', ImportRun::class)->where('accounting_source_id', $run->id)->firstOrFail();

    $service = app(MigrationJournalService::class);
    $created = $service->createForBatch(piCoaBatch($fx), piCoaRows($fx), $fx['company'], $fx['currency']->id, '2026-09-30', '2026-10-01', '2026-10-31');
    $skipped = $service->skippedJournals();

    expect($created)->toBe(1)
        ->and($skipped)->toHaveCount(1)
        ->and($skipped[0]['kind'])->toBe('opening')
        ->and($skipped[0]['existing_move_id'])->toBe($existing->id)
        ->and($skipped[0]['reason'])->toContain('already exists');
});

/* -------------------------------------------------------------------------
 * FX approval re-dating (item 11)
 * ---------------------------------------------------------------------- */

it('refuses to activate an exchange rate re-dated after its approval was granted', function (): void {
    $fx = piFixture();
    $foreign = Currency::query()->where('code', 'USD')->first() ?? Currency::factory()->create(['code' => 'PIU']);
    $rate = ExchangeRate::query()->create([
        'company_id'         => $fx['company']->id, 'source_currency_id' => $foreign->id, 'target_currency_id' => $fx['currency']->id,
        'effective_date'     => '2026-10-20', 'rate' => '280.000000000000000', 'rate_type' => ExchangeRateType::Transaction,
        'source'             => ExchangeRateSource::Manual, 'approval_status' => ExchangeRateApprovalStatus::Draft,
        'created_by'         => $fx['user']->id,
    ]);
    ApprovalWorkflow::query()->create([
        'company_id' => $fx['company']->id, 'name' => 'PI FX approval', 'request_type' => 'exchange_rate_change',
        'priority'   => 100, 'is_active' => true,
    ])->steps()->create([
        'sequence' => 1, 'name' => 'Controller', 'approver_user_id' => $fx['user']->id, 'required_approvals' => 1,
    ]);
    $service = app(ExchangeRateApprovalService::class);

    $request = $service->submit($rate, $fx['user']);
    app(ApprovalEngine::class)->approve($request, $fx['user'], 'Checked.');
    expect(ApprovalRequest::query()->find($request->id)->context['effective_date'])->toBe('2026-10-20');

    $rate->update(['effective_date' => '2026-09-01']);

    expect(fn () => $service->approve($rate->fresh(), $fx['user']))
        ->toThrow(RuntimeException::class, 'effective date was changed from "2026-10-20" to "2026-09-01"');
    expect($rate->fresh()->approval_status)->toBe(ExchangeRateApprovalStatus::Draft);

    $rate->update(['effective_date' => '2026-10-20']);
    expect($service->approve($rate->fresh(), $fx['user'])->approval_status)->toBe(ExchangeRateApprovalStatus::Approved);
});

/* -------------------------------------------------------------------------
 * Posted journal entry policy (item 2)
 * ---------------------------------------------------------------------- */

it('denies deleting a posted journal entry or its items through the policies', function (): void {
    $fx = piFixture();
    $user = piGrant($fx['user'], [
        'delete_accounting_journal::entry', 'force_delete_accounting_journal::entry',
        'delete_accounting_journal::item', 'force_delete_accounting_journal::item',
    ]);
    $posted = JournalEntry::query()->findOrFail(piInvoice($fx, 'PI-POLICY', 100)->id);
    $draft = JournalEntry::query()->findOrFail(Move::query()->create([
        'company_id' => $fx['company']->id, 'journal_id' => $fx['generalJournal']->id, 'currency_id' => $fx['currency']->id,
        'move_type'  => MoveType::ENTRY, 'state' => MoveState::DRAFT, 'date' => '2026-10-01', 'creator_id' => $fx['user']->id,
    ])->id);
    $postedItem = JournalItem::query()->where('move_id', $posted->id)->firstOrFail();
    $policy = new JournalEntryPolicy;
    $itemPolicy = new JournalItemPolicy;

    expect($policy->delete($user, $posted))->toBeFalse()
        ->and($policy->forceDelete($user, $posted))->toBeFalse()
        ->and($policy->delete($user, $draft))->toBeTrue()
        ->and($itemPolicy->delete($user, $postedItem))->toBeFalse()
        ->and($itemPolicy->forceDelete($user, $postedItem))->toBeFalse();
});

it('lets the demo seeder --fresh cleanup remove the demo companies\' posted moves, and only those', function (): void {
    $fx = piFixture();
    $fx['company']->update(['name' => 'Rider Demo']);
    $demoInvoice = piInvoice($fx, 'PI-DEMO-WIPE', 100);

    $other = piFixture();
    $otherInvoice = piInvoice($other, 'PI-KEEP', 100);

    $command = app(SeedAccountingDemo::class);
    (fn () => $this->cleanDemoData())->call($command);

    expect(Move::query()->whereKey($demoInvoice->id)->exists())->toBeFalse()
        ->and(Move::query()->whereKey($otherInvoice->id)->exists())->toBeTrue()
        ->and(fn () => $otherInvoice->fresh()->delete())->toThrow(RuntimeException::class, 'cannot be deleted');
});
