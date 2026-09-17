<?php

use Webkul\Account\AccountManager;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Services\Bank\BankJournalCreationService;
use Webkul\Accounting\Services\Bank\BankJournalService;
use Webkul\Accounting\Services\Bank\BankMappingService;
use Webkul\Accounting\Services\Bank\BankMatchingPriorityService;
use Webkul\Accounting\Services\Bank\BankTransferMatchingService;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function integrityFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $currency->update(['active' => true, 'is_iso_fiat' => true]);

    $company = Company::factory()->create([
        'currency_id' => $currency->id,
        'is_active'   => true,
    ]);

    $user = User::factory()->create([
        'default_company_id' => $company->id,
        'is_active'          => true,
    ]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);

    test()->actingAs($user);

    $bankGl = Account::factory()->create([
        'code'         => '110101'.$company->id,
        'name'         => 'Main Bank Account',
        'account_type' => AccountType::ASSET_CASH,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $bankGl->companies()->attach($company->id);

    $expenseGl = Account::factory()->create([
        'code'         => '610201'.$company->id,
        'name'         => 'Operational Expense',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $expenseGl->companies()->attach($company->id);

    $groupGl = Account::factory()->create([
        'code'         => 'GRP-'.$company->id,
        'name'         => 'Group Summary Account',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $currency->id,
        'is_group'     => true,
        'deprecated'   => false,
    ]);
    $groupGl->companies()->attach($company->id);

    $bankJournal = app(BankJournalCreationService::class)->create($company, [
        'currency_id'        => $currency->id,
        'default_account_id' => $bankGl->id,
        'name'               => 'Primary Bank Journal',
        'code'               => 'PBJ'.$company->id,
    ]);

    $generalJournal = Journal::query()->create([
        'company_id'         => $company->id,
        'currency_id'        => $currency->id,
        'default_account_id' => $expenseGl->id,
        'name'               => 'General Journal',
        'code'               => 'GEN'.$company->id,
        'type'               => 'general',
    ]);

    return compact('currency', 'company', 'user', 'bankGl', 'expenseGl', 'groupGl', 'bankJournal', 'generalJournal');
}

function createIntegrityStatementLine(array $fixture, string $reference): BankStatementLine
{
    $statement = BankStatement::query()->create([
        'company_id'              => $fixture['company']->id,
        'journal_id'              => $fixture['bankJournal']->id,
        'currency_id'             => $fixture['currency']->id,
        'company_currency_id'     => $fixture['currency']->id,
        'bank_gl_account_id'      => $fixture['bankGl']->id,
        'name'                    => 'Test Bank Statement',
        'reference'               => 'STMT-'.$reference,
        'date'                    => '2026-08-30',
        'statement_start_date'    => '2026-08-30',
        'statement_end_date'      => '2026-08-30',
        'opening_balance'         => 10000,
        'total_debits'            => 100,
        'total_credits'           => 0,
        'closing_balance'         => 9900,
        'balance_start'           => 10000,
        'balance_end'             => 9900,
        'balance_end_real'        => 9900,
        'company_opening_balance' => 10000,
        'company_total_debits'    => 100,
        'company_total_credits'   => 0,
        'company_closing_balance' => 9900,
        'conversion_status'       => ConversionStatus::Complete,
        'bank_name'               => 'Test Operating Bank',
        'bank_account_number'     => 'OP-'.$reference,
        'account_title'           => 'Operating Title',
        'original_filename'       => 'statement.csv',
        'file_hash'               => hash('sha256', $reference.$fixture['company']->id.uniqid()),
        'parser'                  => 'test',
        'import_status'           => BankImportStatus::Validated,
    ]);

    return BankStatementLine::query()->create([
        'journal_id'              => $fixture['bankJournal']->id,
        'company_id'              => $fixture['company']->id,
        'statement_id'            => $statement->id,
        'currency_id'             => $fixture['currency']->id,
        'original_currency_id'    => $fixture['currency']->id,
        'company_currency_id'     => $fixture['currency']->id,
        'transaction_date'        => '2026-08-30',
        'value_date'              => '2026-08-30',
        'description'             => 'Test transaction '.$reference,
        'reference'               => $reference,
        'debit'                   => 100,
        'credit'                  => 0,
        'original_debit'          => 100,
        'original_credit'         => 0,
        'original_signed_amount'  => -100,
        'company_debit'           => 100,
        'company_credit'          => 0,
        'company_signed_amount'   => -100,
        'amount'                  => 100,
        'amount_currency'         => 100,
        'amount_residual'         => 100,
        'exchange_rate'           => 1,
        'rate_date'               => '2026-08-30',
        'rate_source'             => 'identity',
        'rate_type'               => 'transaction',
        'conversion_status'       => ConversionStatus::Complete,
        'transaction_fingerprint' => hash('sha256', $reference.uniqid()),
        'import_status'           => BankImportStatus::Validated,
    ]);
}

it('rejects posting moves containing group accounts across AccountManager and BankJournalService (M1)', function (): void {
    $fixture = integrityFixture();

    // 1. Core AccountManager guard
    $move = Move::query()->create([
        'company_id'   => $fixture['company']->id,
        'journal_id'   => $fixture['generalJournal']->id,
        'currency_id'  => $fixture['currency']->id,
        'move_type'    => MoveType::ENTRY->value,
        'state'        => MoveState::DRAFT->value,
        'date'         => '2026-09-01',
        'name'         => 'JE-TEST-GROUP',
    ]);

    $move->lines()->create([
        'company_id'  => $fixture['company']->id,
        'journal_id'  => $fixture['generalJournal']->id,
        'account_id'  => $fixture['groupGl']->id, // Group account!
        'currency_id' => $fixture['currency']->id,
        'debit'       => 500,
        'credit'      => 0,
        'balance'     => 500,
    ]);

    $move->lines()->create([
        'company_id'  => $fixture['company']->id,
        'journal_id'  => $fixture['generalJournal']->id,
        'account_id'  => $fixture['expenseGl']->id,
        'currency_id' => $fixture['currency']->id,
        'debit'       => 0,
        'credit'      => 500,
        'balance'     => -500,
    ]);

    $freshMove = $move->fresh(['lines.account', 'journal', 'currency']);
    expect(fn () => app(AccountManager::class)->isConfirmAllowedForMove($freshMove))
        ->toThrow(Exception::class, 'group');

    // 2. BankJournalService post guard
    $stmtLine = createIntegrityStatementLine($fixture, 'GRP-TEST');
    $mapping = BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id,
        'statement_line_id'   => $stmtLine->id,
        'bank_gl_account_id'  => $fixture['bankGl']->id,
        'offset_account_id'   => $fixture['groupGl']->id, // Group account!
        'match_type'          => 'rule',
        'original_currency_id'=> $fixture['currency']->id,
        'company_currency_id' => $fixture['currency']->id,
        'exchange_rate'       => 1,
        'rate_date'           => '2026-08-30',
        'rate_source'         => 'identity',
        'rate_type'           => 'transaction',
        'conversion_status'   => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::Approved,
        'posting_status'      => BankPostingStatus::NotPosted,
    ]);

    expect(fn () => app(BankJournalService::class)->post($mapping, $fixture['user']))
        ->toThrow(RuntimeException::class, 'group');
});

it('handles recursive account hierarchies safely without infinite recursion (B16)', function (): void {
    $fixture = integrityFixture();

    $parentAccount = Account::factory()->create([
        'code'         => 'P-'.$fixture['company']->id,
        'name'         => 'Parent Account',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $fixture['currency']->id,
        'is_group'     => true,
    ]);
    $parentAccount->companies()->attach($fixture['company']->id);

    $childAccount = Account::factory()->create([
        'code'         => 'C-'.$fixture['company']->id,
        'name'         => 'Child Account',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $fixture['currency']->id,
        'parent_id'    => $parentAccount->id,
        'is_group'     => false,
    ]);
    $childAccount->companies()->attach($fixture['company']->id);

    // Verify normal descent
    $descendants = $parentAccount->getDescendantIds();
    expect($descendants)->toContain($childAccount->id);

    // Emulate circular relation: child points to parent
    $childAccount->setRelation('children', collect([$parentAccount]));
    $descendantsWithCycle = $childAccount->getDescendantIds();
    expect($descendantsWithCycle)->toBeArray();
});

it('validates duplicate FS Tag codes per company in FsTagService (M5)', function (): void {
    $fixture = integrityFixture();

    app(FsTagService::class)->create($fixture['company'], [
        'code'       => 'FS-UNIQUE-101',
        'name'       => 'First Unique Tag',
        'account_id' => $fixture['expenseGl']->id,
        'is_active'  => true,
    ]);

    expect(fn () => app(FsTagService::class)->create($fixture['company'], [
        'code'       => 'FS-UNIQUE-101',
        'name'       => 'Second Duplicate Tag',
        'account_id' => $fixture['expenseGl']->id,
        'is_active'  => true,
    ]))->toThrow(RuntimeException::class, 'already exists in this company');
});

it('prevents deleting an FS Tag in active use by mappings or moves (B9)', function (): void {
    $fixture = integrityFixture();

    $tag = app(FsTagService::class)->create($fixture['company'], [
        'code'       => 'FS-PROTECTED',
        'name'       => 'Protected Tag',
        'account_id' => $fixture['expenseGl']->id,
        'is_active'  => true,
    ]);

    $stmtLine = createIntegrityStatementLine($fixture, 'DEL-GUARD');
    BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id,
        'statement_line_id'   => $stmtLine->id,
        'bank_gl_account_id'  => $fixture['bankGl']->id,
        'fs_tag_id'           => $tag->id,
        'match_type'          => 'fs_tag',
        'original_currency_id'=> $fixture['currency']->id,
        'company_currency_id' => $fixture['currency']->id,
        'exchange_rate'       => 1,
        'rate_date'           => '2026-08-30',
        'rate_source'         => 'identity',
        'rate_type'           => 'transaction',
        'conversion_status'   => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::Unmapped,
        'posting_status'      => BankPostingStatus::NotPosted,
    ]);

    expect(fn () => $tag->delete())
        ->toThrow(RuntimeException::class, 'Cannot delete an FS Tag that is referenced');
});

it('blocks re-approving already posted bank transaction mappings (B1)', function (): void {
    $fixture = integrityFixture();

    $tag = app(FsTagService::class)->create($fixture['company'], [
        'code'       => 'FS-REAPPROVE',
        'name'       => 'Reapprove Tag',
        'account_id' => $fixture['expenseGl']->id,
        'is_active'  => true,
    ]);

    $stmtLine = createIntegrityStatementLine($fixture, 'REAPPROVE-1');
    $mapping = BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id,
        'statement_line_id'   => $stmtLine->id,
        'bank_gl_account_id'  => $fixture['bankGl']->id,
        'fs_tag_id'           => $tag->id,
        'match_type'          => 'fs_tag',
        'original_currency_id'=> $fixture['currency']->id,
        'company_currency_id' => $fixture['currency']->id,
        'exchange_rate'       => 1,
        'rate_date'           => '2026-08-30',
        'rate_source'         => 'identity',
        'rate_type'           => 'transaction',
        'conversion_status'   => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::Unmapped,
        'posting_status'      => BankPostingStatus::NotPosted,
    ]);

    $approved = app(BankMappingService::class)->approve($mapping, $fixture['user'], false);
    app(BankJournalService::class)->createDraft($approved);
    app(BankJournalService::class)->post($approved->fresh(), $fixture['user']);

    expect($mapping->fresh()->posting_status)->toBe(BankPostingStatus::Posted);

    // Attempting to re-approve a posted mapping should throw an exception
    expect(fn () => app(BankMappingService::class)->approve($mapping->fresh(), $fixture['user'], false))
        ->toThrow(RuntimeException::class, 'Cannot approve an already posted');
});

it('excludes approved, posted, and move-linked mappings from BankTransferMatchingService detection (B2)', function (): void {
    $fixture = integrityFixture();

    $stmtLine = createIntegrityStatementLine($fixture, 'TRF-EXCLUDE');
    BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id,
        'statement_line_id'   => $stmtLine->id,
        'bank_gl_account_id'  => $fixture['bankGl']->id,
        'offset_account_id'   => $fixture['expenseGl']->id,
        'match_type'          => 'rule',
        'original_currency_id'=> $fixture['currency']->id,
        'company_currency_id' => $fixture['currency']->id,
        'exchange_rate'       => 1,
        'rate_date'           => '2026-08-30',
        'rate_source'         => 'identity',
        'rate_type'           => 'transaction',
        'conversion_status'   => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::Approved, // Approved!
        'posting_status'      => BankPostingStatus::NotPosted,
    ]);

    $matches = app(BankTransferMatchingService::class)->detect($fixture['company']->id);
    expect($matches)->toBeEmpty();
});

it('rejects posting bank moves that have not been approved (B3)', function (): void {
    $fixture = integrityFixture();

    $stmtLine = createIntegrityStatementLine($fixture, 'UNAPPROVED-POST');
    $mapping = BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id,
        'statement_line_id'   => $stmtLine->id,
        'bank_gl_account_id'  => $fixture['bankGl']->id,
        'offset_account_id'   => $fixture['expenseGl']->id,
        'match_type'          => 'rule',
        'original_currency_id'=> $fixture['currency']->id,
        'company_currency_id' => $fixture['currency']->id,
        'exchange_rate'       => 1,
        'rate_date'           => '2026-08-30',
        'rate_source'         => 'identity',
        'rate_type'           => 'transaction',
        'conversion_status'   => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::NeedsReview, // Not approved!
        'posting_status'      => BankPostingStatus::NotPosted,
    ]);

    expect(fn () => app(BankJournalService::class)->post($mapping, $fixture['user']))
        ->toThrow(RuntimeException::class, 'Only approved transaction mappings can be posted.');
});

it('clears fs_tag_id when matching an open obligation to prevent tag carryover to receivables (B4)', function (): void {
    $fixture = integrityFixture();

    $tag = app(FsTagService::class)->create($fixture['company'], [
        'code'       => 'FS-COLLISION',
        'name'       => 'Collision Tag',
        'account_id' => $fixture['expenseGl']->id,
        'is_active'  => true,
    ]);

    $receivableGl = Account::factory()->create([
        'code'         => '120101-'.$fixture['company']->id,
        'name'         => 'Customer Receivable',
        'account_type' => AccountType::ASSET_RECEIVABLE,
        'currency_id'  => $fixture['currency']->id,
        'is_group'     => false,
    ]);
    $receivableGl->companies()->attach($fixture['company']->id);

    // Create an open customer invoice move
    $invoiceMove = Move::query()->create([
        'company_id'       => $fixture['company']->id,
        'journal_id'       => $fixture['bankJournal']->id,
        'currency_id'      => $fixture['currency']->id,
        'move_type'        => MoveType::IN_INVOICE->value,
        'state'            => MoveState::POSTED->value,
        'date'             => '2026-08-30',
        'reference'        => 'INV-MATCH-888',
        'name'             => 'INV-MATCH-888',
        'amount_residual'  => 100,
    ]);

    $invoiceMove->lines()->create([
        'company_id'   => $fixture['company']->id,
        'journal_id'   => $fixture['bankJournal']->id,
        'account_id'   => $receivableGl->id,
        'currency_id'  => $fixture['currency']->id,
        'debit'        => 100,
        'credit'       => 0,
        'balance'      => 100,
        'parent_state' => MoveState::POSTED->value,
    ]);

    // Create bank statement line with reference INV-MATCH-888. The shared
    // helper defaults to a debit (money out) line; this test is matching a
    // receivable (money owed to us), so it needs to be a credit (money in)
    // -- otherwise the direction check correctly refuses the match.
    $stmtLine = createIntegrityStatementLine($fixture, 'INV-MATCH-888');
    $stmtLine->update([
        'debit'                  => 0,
        'credit'                 => 100,
        'original_debit'         => 0,
        'original_credit'        => 100,
        'original_signed_amount' => 100,
        'company_debit'          => 0,
        'company_credit'         => 100,
        'company_signed_amount'  => 100,
    ]);
    $mapping = BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id,
        'statement_line_id'   => $stmtLine->id,
        'bank_gl_account_id'  => $fixture['bankGl']->id,
        'fs_tag_id'           => $tag->id,
        'match_type'          => 'fs_tag',
        'original_currency_id'=> $fixture['currency']->id,
        'company_currency_id' => $fixture['currency']->id,
        'exchange_rate'       => 1,
        'rate_date'           => '2026-08-30',
        'rate_source'         => 'identity',
        'rate_type'           => 'transaction',
        'conversion_status'   => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::Unmapped,
        'posting_status'      => BankPostingStatus::NotPosted,
    ]);

    app(BankMatchingPriorityService::class)->run($fixture['company']->id);

    $refreshed = $mapping->fresh();
    expect($refreshed->match_type)->toBe('obligation')
        ->and($refreshed->matched_move_id)->toBe($invoiceMove->id)
        ->and($refreshed->offset_account_id)->toBe($receivableGl->id)
        ->and($refreshed->fs_tag_id)->toBeNull(); // Cleanly cleared!
});
