<?php

use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\ProfitLoss;
use Webkul\Accounting\Services\TrialBalanceService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function plAccount(Company $company, AccountType $type, string $code): Account
{
    $account = Account::factory()->create([
        'account_type' => $type,
        'code'         => $code,
        'currency_id'  => $company->currency_id,
    ]);
    $account->companies()->attach($company->id);

    return $account;
}

/**
 * Post one balanced entry: $lines maps account => signed balance (debit +).
 *
 * @param  array<int, array{0: Account, 1: float}>  $lines
 */
function plPost(Company $company, Journal $journal, string $date, array $lines): void
{
    $move = Move::factory()->create([
        'company_id'  => $company->id,
        'journal_id'  => $journal->id,
        'currency_id' => $company->currency_id,
        'state'       => MoveState::POSTED,
        'date'        => $date,
    ]);

    foreach ($lines as [$account, $balance]) {
        MoveLine::factory()->create([
            'move_id'      => $move->id,
            'journal_id'   => $journal->id,
            'company_id'   => $company->id,
            'currency_id'  => $company->currency_id,
            'account_id'   => $account->id,
            'balance'      => $balance,
            'debit'        => $balance >= 0 ? $balance : 0,
            'credit'       => $balance < 0 ? -$balance : 0,
            'parent_state' => MoveState::POSTED,
            'date'         => $date,
        ]);
    }
}

it('reports P&L as income minus expenses with ledger signs, reconciling to the trial balance (J6-F5)', function () {
    $company = Company::factory()->create();
    $bank = plAccount($company, AccountType::ASSET_CASH, 'PL-1000');
    $accumulated = plAccount($company, AccountType::ASSET_FIXED, 'PL-1500');
    $sales = plAccount($company, AccountType::INCOME, 'PL-4000');
    $returns = plAccount($company, AccountType::INCOME, 'PL-4100');
    $other = plAccount($company, AccountType::INCOME_OTHER, 'PL-4900');
    $expense = plAccount($company, AccountType::EXPENSE, 'PL-6000');
    $recovery = plAccount($company, AccountType::EXPENSE, 'PL-6100');
    $directCost = plAccount($company, AccountType::EXPENSE_DIRECT_COST, 'PL-5000');
    $depreciation = plAccount($company, AccountType::EXPENSE_DEPRECIATION, 'PL-6900');

    $journal = Journal::factory()->create(['company_id' => $company->id, 'default_account_id' => $bank->id]);

    plPost($company, $journal, '2025-02-10', [[$bank, 1000.0], [$sales, -1000.0]]);
    plPost($company, $journal, '2025-02-12', [[$returns, 100.0], [$bank, -100.0]]);          // debit-balance income (sales return)
    plPost($company, $journal, '2025-03-01', [[$bank, 25.0], [$other, -25.0]]);
    plPost($company, $journal, '2025-03-05', [[$expense, 400.0], [$bank, -400.0]]);
    plPost($company, $journal, '2025-03-06', [[$bank, 150.0], [$recovery, -150.0]]);       // credit-balance expense (recovery)
    plPost($company, $journal, '2025-03-07', [[$directCost, 200.0], [$bank, -200.0]]);
    plPost($company, $journal, '2025-03-31', [[$depreciation, 50.0], [$accumulated, -50.0]]);
    plPost($company, $journal, '2026-01-15', [[$expense, 9999.0], [$bank, -9999.0]]);      // outside the period

    $user = User::factory()->create(['is_active' => true, 'default_company_id' => $company->id]);
    Permission::findOrCreate('page_accounting_profit_loss', 'web');
    $user->givePermissionTo('page_accounting_profit_loss');
    test()->actingAs($user);

    $data = Livewire::test(ProfitLoss::class)
        ->set('data.date_range', '01/01/2025 - 31/12/2025')
        ->instance()
        ->profitLossData;

    [$revenue, $expenses] = $data['sections'];
    $byCode = fn (array $section): array => collect($section['accounts'])->pluck('balance', 'code')->all();

    // Revenue 1000 - 100 return + 25 other = 925; expenses 400 - 150 + 200 + 50 = 500.
    // The old abs() handling reported 1125 revenue and 800 expenses.
    expect($revenue['total'])->toBe(925.0)
        ->and($expenses['total'])->toBe(500.0)
        ->and($data['net_income'])->toBe(425.0)
        ->and($data['is_profit'])->toBeTrue()
        ->and($byCode($revenue))->toBe(['PL-4000' => 1000.0, 'PL-4100' => -100.0, 'PL-4900' => 25.0])
        ->and($byCode($expenses))->toBe(['PL-5000' => 200.0, 'PL-6000' => 400.0, 'PL-6100' => -150.0, 'PL-6900' => 50.0]);

    // Reconcile to the trial balance: net income = -(net movement of every
    // income and expense account over the period).
    $trialBalance = app(TrialBalanceService::class)->compute($company->id, '2025-01-01', '2025-12-31');
    $profitAndLossTypes = array_merge(array_keys(AccountType::income()), array_keys(AccountType::expenses()));
    $typeById = Account::query()->whereKey(collect($trialBalance['rows'])->pluck('account_id'))->pluck('account_type', 'id');

    $movement = collect($trialBalance['rows'])
        ->filter(fn (array $row): bool => in_array(
            $typeById[$row['account_id']] instanceof AccountType ? $typeById[$row['account_id']]->value : $typeById[$row['account_id']],
            $profitAndLossTypes,
            true,
        ))
        ->sum(fn (array $row): float => ($row['closing_debit'] - $row['closing_credit']) - ($row['opening_debit'] - $row['opening_credit']));

    expect(round(-$movement, 2))->toBe(round($data['net_income'], 2))
        ->and(round($trialBalance['totals']['closing_debit'] - $trialBalance['totals']['closing_credit'], 2))->toBe(0.0);
});

it('reports a net loss with a negative net income and no sign flip', function () {
    $company = Company::factory()->create();
    $bank = plAccount($company, AccountType::ASSET_CASH, 'PLL-1000');
    $sales = plAccount($company, AccountType::INCOME, 'PLL-4000');
    $expense = plAccount($company, AccountType::EXPENSE, 'PLL-6000');
    $journal = Journal::factory()->create(['company_id' => $company->id, 'default_account_id' => $bank->id]);

    plPost($company, $journal, '2025-05-10', [[$bank, 300.0], [$sales, -300.0]]);
    plPost($company, $journal, '2025-05-11', [[$expense, 800.0], [$bank, -800.0]]);

    $user = User::factory()->create(['is_active' => true, 'default_company_id' => $company->id]);
    Permission::findOrCreate('page_accounting_profit_loss', 'web');
    $user->givePermissionTo('page_accounting_profit_loss');
    test()->actingAs($user);

    $data = Livewire::test(ProfitLoss::class)
        ->set('data.date_range', '01/05/2025 - 31/05/2025')
        ->instance()
        ->profitLossData;

    expect($data['sections'][0]['total'])->toBe(300.0)
        ->and($data['sections'][1]['total'])->toBe(800.0)
        ->and($data['net_income'])->toBe(-500.0)
        ->and($data['is_profit'])->toBeFalse();
});
