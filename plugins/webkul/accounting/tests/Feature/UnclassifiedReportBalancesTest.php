<?php

/**
 * Posted lines on an account that is not linked to the company (or is not a
 * balance-sheet/P&L account of it) must not silently drop out of the Balance
 * Sheet or Trial Balance: they show under "Unclassified", raise a warning and
 * the totals still reconcile to the ledger. The report template validator
 * warns about accounts bound twice and accounts with balances bound to no line.
 */

use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Data\ValidationIssue;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\BalanceSheet;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineAccount;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\ReportTemplateValidator;
use Webkul\Accounting\Services\TrialBalanceService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function unclassifiedAccount(?Company $company, AccountType $type, string $code): Account
{
    $account = Account::factory()->create(['account_type' => $type, 'code' => $code, 'currency_id' => $company?->currency_id]);

    if ($company) {
        $account->companies()->attach($company->id);
    }

    return $account;
}

/** @param  array<int, array{0: Account, 1: float}>  $lines */
function unclassifiedPost(Company $company, Journal $journal, string $date, array $lines): void
{
    $move = Move::factory()->create([
        'company_id' => $company->id, 'journal_id' => $journal->id, 'currency_id' => $company->currency_id,
        'state'      => MoveState::POSTED, 'date' => $date,
    ]);

    foreach ($lines as [$account, $balance]) {
        MoveLine::factory()->create([
            'move_id'      => $move->id, 'journal_id' => $journal->id, 'company_id' => $company->id,
            'currency_id'  => $company->currency_id, 'account_id' => $account->id, 'balance' => $balance,
            'debit'        => $balance >= 0 ? $balance : 0, 'credit' => $balance < 0 ? -$balance : 0,
            'parent_state' => MoveState::POSTED, 'date' => $date,
        ]);
    }
}

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->bank = unclassifiedAccount($this->company, AccountType::ASSET_CASH, 'UNC-1000');
    $this->capital = unclassifiedAccount($this->company, AccountType::EQUITY, 'UNC-3000');
    $this->sales = unclassifiedAccount($this->company, AccountType::INCOME, 'UNC-4000');
    $this->stray = unclassifiedAccount(null, AccountType::ASSET_CURRENT, 'UNC-1900');
    $this->journal = Journal::factory()->create(['company_id' => $this->company->id, 'default_account_id' => $this->bank->id]);

    unclassifiedPost($this->company, $this->journal, '2025-03-01', [[$this->bank, 1000.0], [$this->capital, -1000.0]]);
    unclassifiedPost($this->company, $this->journal, '2025-03-02', [[$this->bank, 300.0], [$this->sales, -300.0]]);
    // Posted to an account that is not linked to the company at all.
    unclassifiedPost($this->company, $this->journal, '2025-03-03', [[$this->stray, 250.0], [$this->bank, -250.0]]);
});

it('shows unlinked balances as unclassified on the balance sheet, which still balances', function () {
    $user = User::factory()->create(['is_active' => true, 'default_company_id' => $this->company->id]);
    Permission::findOrCreate('page_accounting_balance_sheet', 'web');
    $user->givePermissionTo('page_accounting_balance_sheet');
    test()->actingAs($user);

    $data = Livewire::test(BalanceSheet::class)
        ->set('data.date_range', '01/01/2025 - 31/12/2025')
        ->instance()
        ->balanceSheetData;

    $sections = collect($data['sections'])->keyBy('title');
    $unclassified = $sections->get('Unclassified / not linked to this company');

    expect($unclassified)->not->toBeNull()
        ->and($unclassified['total'])->toBe(250.0)
        ->and(collect($unclassified['subsections'][0]['accounts'])->pluck('code')->all())->toBe(['UNC-1900'])
        // Assets 1050 (bank) + unclassified 250 = liabilities 0 + equity 1000 + earnings 300.
        ->and($data['balance_check']['assets'])->toBe(1050.0)
        ->and($data['balance_check']['liabilities_and_equity'])->toBe(1300.0)
        ->and($data['balance_check']['difference'])->toBe(0.0)
        ->and($data['balance_check']['balanced'])->toBeTrue()
        ->and(collect($data['warnings'])->contains(fn (string $warning): bool => str_starts_with($warning, 'Unclassified: 1 account(s)')))->toBeTrue();
});

it('includes unlinked balances in the trial balance totals and warns about them', function () {
    $result = app(TrialBalanceService::class)->compute($this->company->id, '2025-01-01', '2025-12-31');
    $rows = collect($result['rows'])->keyBy('code');

    expect($rows->get('UNC-1900'))->not->toBeNull()
        ->and($rows->get('UNC-1900')['closing_debit'])->toBe(250.0)
        ->and($rows->get('UNC-1900')['unclassified'])->toBeTrue()
        ->and($rows->get('UNC-1000')['unclassified'])->toBeFalse()
        ->and($result['totals']['closing_debit'])->toBe(1300.0)
        ->and($result['totals']['closing_credit'])->toBe(1300.0)
        ->and($result['totals']['difference'])->toBe(0.0)
        ->and($result['conversion_status'])->toBe('review_required')
        ->and(collect($result['warnings'])->contains(fn (string $warning): bool => str_contains($warning, 'UNC-1900')))->toBeTrue();
});

it('warns about accounts bound to two lines and accounts with balances bound to no line', function () {
    $template = ReportTemplate::factory()->create(['company_id' => $this->company->id]);
    $first = ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail', 'caption' => 'Cash']);
    $second = ReportLine::factory()->create(['report_template_id' => $template->id, 'line_type' => 'detail', 'caption' => 'Cash again']);
    ReportLineAccount::factory()->create(['report_line_id' => $first->id, 'account_id' => $this->bank->id]);
    ReportLineAccount::factory()->create(['report_line_id' => $second->id, 'account_id' => $this->bank->id]);

    $issues = app(ReportTemplateValidator::class)->validate($template);
    $byCode = $issues->keyBy(fn (ValidationIssue $issue) => $issue->code);

    expect($byCode->get('account_bound_twice')?->message)->toContain('UNC-1000')
        ->and($byCode->get('unbound_account_with_balance')?->message)->toContain('UNC-3000')->toContain('UNC-1900')
        // A balance-sheet-only template is not asked to bind P&L accounts.
        ->and($byCode->get('unbound_account_with_balance')?->message)->not->toContain('UNC-4000')
        ->and($byCode->get('unbound_account_with_balance')?->isError())->toBeFalse();
});
