<?php

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\BankStatementLine;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Enums\BankImportStatus;
use Webkul\Accounting\Enums\BankPostingStatus;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Accounting\Services\Bank\BankMatchingPriorityService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function priorityMatchFixture(): array
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
    $bank = $account('PMBANK-', AccountType::ASSET_CASH, true);
    $receivable = $account('PMAR-', AccountType::ASSET_RECEIVABLE, true);
    $payable = $account('PMAP-', AccountType::LIABILITY_PAYABLE, true);
    $revenue = $account('PMREV-', AccountType::INCOME);
    $expense = $account('PMEXP-', AccountType::EXPENSE);

    $saleJournal = Journal::factory()->create([
        'company_id'         => $company->id, 'currency_id' => $currency->id, 'creator_id' => $user->id,
        'default_account_id' => $revenue->id, 'code' => 'PMSALE'.$company->id, 'name' => 'Sales', 'type' => JournalType::SALE,
    ]);
    $purchaseJournal = Journal::factory()->create([
        'company_id'         => $company->id, 'currency_id' => $currency->id, 'creator_id' => $user->id,
        'default_account_id' => $expense->id, 'code' => 'PMPUR'.$company->id, 'name' => 'Purchases', 'type' => JournalType::PURCHASE,
    ]);
    $bankJournal = Journal::factory()->create([
        'company_id'         => $company->id, 'currency_id' => $currency->id, 'creator_id' => $user->id,
        'default_account_id' => $bank->id, 'code' => 'PMBANKJ'.$company->id, 'name' => 'Bank', 'type' => JournalType::BANK,
    ]);

    $customer = Partner::query()->create([
        'company_id' => $company->id, 'account_type' => 'company', 'sub_type' => 'customer',
        'reference'  => 'PM-CUSTOMER-'.$company->id, 'name' => 'Priority match customer', 'customer_rank' => 1,
        'property_account_receivable_id' => $receivable->id,
    ]);
    $vendor = Partner::query()->create([
        'company_id' => $company->id, 'account_type' => 'company', 'sub_type' => 'vendor',
        'reference'  => 'PM-VENDOR-'.$company->id, 'name' => 'Priority match vendor', 'supplier_rank' => 1,
        'property_account_payable_id' => $payable->id,
    ]);

    return compact(
        'currency', 'company', 'user', 'bank', 'receivable', 'payable', 'revenue', 'expense',
        'saleJournal', 'purchaseJournal', 'bankJournal', 'customer', 'vendor',
    );
}

function priorityMatchInvoice(array $fixture, string $bookingId, float $amount): Move
{
    $invoice = Move::query()->create([
        'company_id'  => $fixture['company']->id, 'journal_id' => $fixture['saleJournal']->id, 'partner_id' => $fixture['customer']->id,
        'currency_id' => $fixture['currency']->id, 'move_type' => MoveType::OUT_INVOICE, 'state' => MoveState::DRAFT,
        'date'        => '2026-08-01', 'invoice_date' => '2026-08-01', 'invoice_date_due' => '2026-08-31',
        'booking_id'  => $bookingId,
    ]);
    MoveLine::query()->create([
        'move_id' => $invoice->id, 'account_id' => $fixture['revenue']->id, 'partner_id' => $fixture['customer']->id,
        'currency_id' => $fixture['currency']->id, 'display_type' => DisplayType::PRODUCT,
        'name' => 'Freight service', 'quantity' => 1, 'price_unit' => $amount,
    ]);

    return AccountFacade::confirmMove($invoice->fresh());
}

function priorityMatchBill(array $fixture, string $bookingId, float $amount): Move
{
    $bill = Move::query()->create([
        'company_id'  => $fixture['company']->id, 'journal_id' => $fixture['purchaseJournal']->id, 'partner_id' => $fixture['vendor']->id,
        'currency_id' => $fixture['currency']->id, 'move_type' => MoveType::IN_INVOICE, 'state' => MoveState::DRAFT,
        'date'        => '2026-08-01', 'invoice_date' => '2026-08-01', 'invoice_date_due' => '2026-08-31',
        'booking_id'  => $bookingId,
    ]);
    MoveLine::query()->create([
        'move_id' => $bill->id, 'account_id' => $fixture['expense']->id, 'partner_id' => $fixture['vendor']->id,
        'currency_id' => $fixture['currency']->id, 'display_type' => DisplayType::PRODUCT,
        'name' => 'Fuel expense', 'quantity' => 1, 'price_unit' => $amount,
    ]);

    return AccountFacade::confirmMove($bill->fresh());
}

function priorityMatchBankLine(array $fixture, string $bookingId, float $amount, string $direction, string $tag = ''): BankTransactionMapping
{
    $isCredit = $direction === 'credit';
    $debit = $isCredit ? 0 : $amount;
    $credit = $isCredit ? $amount : 0;

    $statement = BankStatement::query()->create([
        'company_id'              => $fixture['company']->id, 'journal_id' => $fixture['bankJournal']->id,
        'currency_id'             => $fixture['currency']->id, 'company_currency_id' => $fixture['currency']->id,
        'bank_gl_account_id'      => $fixture['bank']->id, 'name' => 'Priority match statement'.$tag, 'reference' => 'PM-STMT'.$tag,
        'date'                    => '2026-08-15', 'statement_start_date' => '2026-08-15', 'statement_end_date' => '2026-08-15',
        'opening_balance'         => 0, 'total_debits' => $debit, 'total_credits' => $credit, 'closing_balance' => $credit - $debit,
        'balance_start'           => 0, 'balance_end' => $credit - $debit, 'balance_end_real' => $credit - $debit,
        'company_opening_balance' => 0, 'company_total_debits' => $debit, 'company_total_credits' => $credit,
        'company_closing_balance' => $credit - $debit, 'conversion_status' => ConversionStatus::Complete,
        'bank_name'               => 'Acceptance Bank', 'bank_account_number' => 'PM-BANK'.$tag, 'account_title' => 'Operating',
        'original_filename'       => 'bank.csv', 'file_hash' => hash('sha256', $bookingId.$amount.$direction.$tag.$fixture['company']->id),
        'parser'                  => 'test', 'import_status' => BankImportStatus::Validated,
    ]);
    $line = BankStatementLine::query()->create([
        'journal_id'              => $fixture['bankJournal']->id, 'company_id' => $fixture['company']->id,
        'statement_id'            => $statement->id, 'currency_id' => $fixture['currency']->id,
        'original_currency_id'    => $fixture['currency']->id, 'company_currency_id' => $fixture['currency']->id,
        'transaction_date'        => '2026-08-15', 'value_date' => '2026-08-15',
        'description'             => "Booking {$bookingId}", 'reference' => 'PM-REF'.$tag,
        'debit'                   => $debit, 'credit' => $credit, 'original_debit' => $debit, 'original_credit' => $credit,
        'original_signed_amount'  => $credit - $debit, 'company_debit' => $debit, 'company_credit' => $credit,
        'company_signed_amount'   => $credit - $debit, 'amount' => $credit - $debit, 'amount_currency' => $credit - $debit,
        'amount_residual'         => $credit - $debit, 'exchange_rate' => 1, 'rate_date' => '2026-08-15',
        'rate_source'             => 'identity', 'rate_type' => 'transaction', 'conversion_status' => ConversionStatus::Complete,
        'transaction_type'        => $direction,
        'transaction_fingerprint' => hash('sha256', $bookingId.'-'.$amount.'-'.$direction.$tag.$fixture['company']->id),
        'import_status'           => BankImportStatus::Validated,
    ]);

    return BankTransactionMapping::query()->create([
        'company_id'          => $fixture['company']->id, 'statement_line_id' => $line->id,
        'bank_gl_account_id'  => $fixture['bank']->id, 'original_currency_id' => $fixture['currency']->id,
        'company_currency_id' => $fixture['currency']->id, 'exchange_rate' => 1, 'rate_date' => '2026-08-15',
        'rate_source'         => 'identity', 'rate_type' => 'transaction', 'conversion_status' => ConversionStatus::Complete,
        'review_status'       => BankReviewStatus::Unmapped, 'posting_status' => BankPostingStatus::NotPosted,
    ]);
}

it('does not suggest an incoming payment against an outstanding vendor bill', function (): void {
    $fixture = priorityMatchFixture();
    // Only a payable exists -- no receivable with this booking reference at
    // all -- so a bank credit (money coming in) referencing it must never
    // match, even though the amount and reference line up.
    priorityMatchBill($fixture, 'BKG-DIR-1', 500);
    $mapping = priorityMatchBankLine($fixture, 'BKG-DIR-1', 500, 'credit');

    $result = app(BankMatchingPriorityService::class)->run($fixture['company']->id);

    expect($result['obligations'])->toBe(0)
        ->and($mapping->fresh()->matched_move_id)->toBeNull()
        ->and($mapping->fresh()->review_status)->toBe(BankReviewStatus::Unmapped);
});

it('matches an outgoing payment against an outstanding vendor bill, not a same-reference receivable', function (): void {
    $fixture = priorityMatchFixture();
    // A receivable and a payable share the same booking reference and
    // amount -- only the payable is the correct match for money going out.
    priorityMatchInvoice($fixture, 'BKG-DIR-2', 700);
    $bill = priorityMatchBill($fixture, 'BKG-DIR-2', 700);
    $mapping = priorityMatchBankLine($fixture, 'BKG-DIR-2', 700, 'debit');

    $result = app(BankMatchingPriorityService::class)->run($fixture['company']->id);

    expect($result['obligations'])->toBe(1)
        ->and($mapping->fresh()->matched_move_id)->toBe($bill->id)
        ->and($mapping->fresh()->offset_account_id)->toBe($fixture['payable']->id);
});

it('does not let two bank lines both claim the same open invoice in one run', function (): void {
    $fixture = priorityMatchFixture();
    $invoice = priorityMatchInvoice($fixture, 'BKG-CLAIM-1', 1000);

    $firstMapping = priorityMatchBankLine($fixture, 'BKG-CLAIM-1', 1000, 'credit', 'A');
    $secondMapping = priorityMatchBankLine($fixture, 'BKG-CLAIM-1', 1000, 'credit', 'B');

    app(BankMatchingPriorityService::class)->run($fixture['company']->id);

    $firstMapping->refresh();
    $secondMapping->refresh();

    $matchedCount = collect([$firstMapping, $secondMapping])
        ->filter(fn (BankTransactionMapping $mapping) => $mapping->matched_move_id === $invoice->id)
        ->count();

    // Exactly one of the two must have claimed the invoice -- never both,
    // and never neither (the first one processed should still get it).
    expect($matchedCount)->toBe(1);

    $unclaimed = collect([$firstMapping, $secondMapping])->first(fn (BankTransactionMapping $mapping) => $mapping->matched_move_id === null);
    expect($unclaimed)->not->toBeNull()
        ->and($unclaimed->review_status)->not->toBe(BankReviewStatus::Suggested);
});
