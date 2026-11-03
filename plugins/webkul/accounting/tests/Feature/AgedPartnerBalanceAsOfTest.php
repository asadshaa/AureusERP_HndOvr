<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Partner;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\AgedPayable;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\AgedReceivable;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\PartnerLedger;
use Webkul\Accounting\Services\AgedPartnerBalanceService;
use Webkul\Accounting\Services\PartnerAnalyticsService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Insert a posted move with one partner line on $account and return the
 * line id. $amount is the signed balance (debit +); $residual the current
 * amount_residual (defaults to the full balance, i.e. still open).
 *
 * @param  array<string, mixed>  $move
 */
function agedItem(Company $company, Partner $partner, Account $account, float $amount, array $move, ?float $residual = null): int
{
    $now = now();

    $moveId = DB::table('accounts_account_moves')->insertGetId(array_merge([
        'company_id'   => $company->id,
        'partner_id'   => $partner->id,
        'currency_id'  => $company->currency_id,
        'state'        => MoveState::POSTED->value,
        'move_type'    => MoveType::ENTRY->value,
        'amount_total' => abs($amount),
        'created_at'   => $now,
        'updated_at'   => $now,
    ], $move));

    return DB::table('accounts_account_move_lines')->insertGetId([
        'move_id'         => $moveId,
        'company_id'      => $company->id,
        'currency_id'     => $company->currency_id,
        'account_id'      => $account->id,
        'partner_id'      => $partner->id,
        'parent_state'    => $move['state'] ?? MoveState::POSTED->value,
        'date'            => $move['date'],
        'debit'           => max($amount, 0),
        'credit'          => max(-$amount, 0),
        'balance'         => $amount,
        'amount_residual' => $residual ?? $amount,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
}

function agedReconcile(Company $company, int $debitLineId, int $creditLineId, float $amount, string $maxDate): void
{
    DB::table('accounts_partial_reconciles')->insert([
        'debit_move_id'  => $debitLineId,
        'credit_move_id' => $creditLineId,
        'company_id'     => $company->id,
        'amount'         => $amount,
        'max_date'       => $maxDate,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
}

/**
 * @return array{company: Company, partner: Partner, receivable: Account, payable: Account}
 */
function agedFixture(): array
{
    $company = Company::factory()->create();

    return [
        'company'    => $company,
        'partner'    => Partner::query()->create([
            'company_id' => $company->id, 'account_type' => 'company', 'sub_type' => 'customer',
            'name'       => 'Aged As-Of Partner', 'customer_rank' => 1, 'supplier_rank' => 1,
        ]),
        'receivable' => Account::factory()->create(['account_type' => AccountType::ASSET_RECEIVABLE, 'reconcile' => true, 'currency_id' => $company->currency_id]),
        'payable'    => Account::factory()->create(['account_type' => AccountType::LIABILITY_PAYABLE, 'reconcile' => true, 'currency_id' => $company->currency_id]),
    ];
}

function agedUser(Company $company): User
{
    $user = User::factory()->create(['is_active' => true, 'default_company_id' => $company->id]);

    foreach (['page_accounting_aged_receivable', 'page_accounting_aged_payable', 'page_accounting_partner_ledger'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    return $user;
}

it('ages receivables as of the chosen date, not as of today (J6-F8)', function () {
    ['company' => $company, 'partner' => $partner, 'receivable' => $receivable] = agedFixture();

    // Invoice 1: 1,000 due 2025-02-09, fully paid on 2025-03-15 (reconciled now).
    $invoice = agedItem($company, $partner, $receivable, 1000.0, [
        'name' => 'INV/AS-OF/1', 'date' => '2025-01-10', 'invoice_date' => '2025-01-10', 'invoice_date_due' => '2025-02-09',
    ], residual: 0.0);
    $payment = agedItem($company, $partner, $receivable, -1000.0, ['name' => 'PAY/AS-OF/1', 'date' => '2025-03-15'], residual: 0.0);
    agedReconcile($company, $invoice, $payment, 1000.0, '2025-03-15');

    // Invoice 2: 500 issued after the first as-of date, still open.
    agedItem($company, $partner, $receivable, 500.0, [
        'name' => 'INV/AS-OF/2', 'date' => '2025-04-01', 'invoice_date' => '2025-04-01', 'invoice_date_due' => '2025-05-01',
    ]);

    test()->actingAs(agedUser($company));

    $component = Livewire::test(AgedReceivable::class)->set('data.as_of_date', '2025-03-01');
    $data = $component->instance()->agedReceivableData;

    // On 2025-03-01 only invoice 1 existed and it was unpaid: 20 days overdue.
    expect($data['partners'])->toHaveCount(1)
        ->and($data['partners'][$partner->id]['total'])->toBe(1000.0)
        ->and($data['partners'][$partner->id]['period_1'])->toBe(1000.0)
        ->and($data['partners'][$partner->id]['at_date'])->toBe(0)
        ->and($data['as_of_date']->toDateString())->toBe('2025-03-01');

    $lines = $component->instance()->getPartnerLines($partner->id);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['move_name'])->toBe('INV/AS-OF/1')
        ->and($lines[0]['days_overdue'])->toBe(20);

    // On 2025-03-31 invoice 1 was paid and invoice 2 did not exist yet.
    $data = Livewire::test(AgedReceivable::class)->set('data.as_of_date', '2025-03-31')->instance()->agedReceivableData;
    expect($data['partners'])->toBe([]);

    // On 2025-06-15 invoice 2 is 45 days overdue (31-60 bucket).
    $data = Livewire::test(AgedReceivable::class)->set('data.as_of_date', '2025-06-15')->instance()->agedReceivableData;
    expect($data['partners'][$partner->id]['total'])->toBe(500.0)
        ->and($data['partners'][$partner->id]['period_2'])->toBe(500.0);
});

it('ages payables as of the chosen date with partial payments added back', function () {
    ['company' => $company, 'partner' => $partner, 'payable' => $payable] = agedFixture();

    // Bill: 800 payable, due 2025-02-28; 300 paid 2025-03-20 (after the
    // first as-of date), so 500 is still open today.
    $bill = agedItem($company, $partner, $payable, -800.0, [
        'name' => 'BILL/AS-OF/1', 'date' => '2025-02-01', 'invoice_date' => '2025-02-01', 'invoice_date_due' => '2025-02-28',
    ], residual: -500.0);
    $payment = agedItem($company, $partner, $payable, 300.0, ['name' => 'PAY/AS-OF/2', 'date' => '2025-03-20'], residual: 0.0);
    agedReconcile($company, $payment, $bill, 300.0, '2025-03-20');

    test()->actingAs(agedUser($company));

    $before = Livewire::test(AgedPayable::class)->set('data.as_of_date', '2025-03-10')->instance()->agedPayableData;
    $after = Livewire::test(AgedPayable::class)->set('data.as_of_date', '2025-03-31')->instance()->agedPayableData;

    expect($before['partners'][$partner->id]['total'])->toBe(800.0)
        ->and($before['partners'][$partner->id]['period_1'])->toBe(800.0)
        ->and($after['partners'][$partner->id]['total'])->toBe(500.0)
        ->and($after['partners'][$partner->id]['period_2'])->toBe(500.0);
});

it('keeps the legacy partner report pages reachable', function () {
    ['company' => $company] = agedFixture();

    test()->actingAs(agedUser($company));

    foreach ([AgedReceivable::getUrl(), AgedPayable::getUrl(), PartnerLedger::getUrl()] as $url) {
        test()->get($url)->assertOk();
    }
});

it('adds 0-30/31-60/61-90/90+ ageing and DSO to customer analytics from the aged balance', function () {
    ['company' => $company, 'partner' => $partner, 'receivable' => $receivable] = agedFixture();

    // Five invoices of 1,000 issued in H1 2026 with open residuals spread
    // over the buckets as of 2026-06-30, plus one issued after it.
    foreach ([
        ['2026-06-01', '2026-07-15', 100.0],  // not yet due
        ['2026-05-20', '2026-06-20', 200.0],  // 10 days
        ['2026-04-15', '2026-05-15', 300.0],  // 46 days
        ['2026-03-10', '2026-04-10', 400.0],  // 81 days
        ['2026-01-01', '2026-01-01', 500.0],  // 180 days
    ] as $index => [$invoiceDate, $dueDate, $open]) {
        agedItem($company, $partner, $receivable, 1000.0, [
            'name'             => 'INV/AN/'.$index,
            'move_type'        => MoveType::OUT_INVOICE->value,
            'date'             => $invoiceDate,
            'invoice_date'     => $invoiceDate,
            'invoice_date_due' => $dueDate,
            'amount_total'     => 1000.0,
            'amount_residual'  => $open,
        ], residual: $open);
    }
    agedItem($company, $partner, $receivable, 999.0, [
        'name'         => 'INV/AN/LATE', 'move_type' => MoveType::OUT_INVOICE->value, 'date' => '2026-07-05',
        'invoice_date' => '2026-07-05', 'invoice_date_due' => '2026-08-05', 'amount_total' => 999.0, 'amount_residual' => 999.0,
    ]);

    $analytics = app(PartnerAnalyticsService::class)->summary($company->id, 'customer', '2026-01-01', '2026-06-30');

    expect($analytics['aging'])->toBe([
        'current' => 100.0, '0_30' => 200.0, '31_60' => 300.0, '61_90' => 400.0, '90_plus' => 500.0, 'total' => 1500.0,
    ])
        ->and($analytics['aging_as_of'])->toBe('2026-06-30')
        ->and($analytics['document_value'])->toBe(5000.0)
        // 1,500 open / 5,000 invoiced x 181 days.
        ->and($analytics['dso'])->toBe(54.3)
        ->and($analytics['dpo'])->toBeNull();

    // Same source as the Aged Receivable report at that date.
    $aged = app(AgedPartnerBalanceService::class)->openItems($company->id, AgedPartnerBalanceService::RECEIVABLE, now()->setDate(2026, 6, 30));
    expect(round($aged->sum('amount'), 2))->toBe($analytics['aging']['total']);
});

it('adds payables ageing and DPO to vendor analytics', function () {
    ['company' => $company, 'partner' => $partner, 'payable' => $payable] = agedFixture();

    agedItem($company, $partner, $payable, -600.0, [
        'name'         => 'BILL/AN/1', 'move_type' => MoveType::IN_INVOICE->value, 'date' => '2026-04-01',
        'invoice_date' => '2026-04-01', 'invoice_date_due' => '2026-05-21', 'amount_total' => 600.0, 'amount_residual' => 300.0,
    ], residual: -300.0);

    $analytics = app(PartnerAnalyticsService::class)->summary($company->id, 'vendor', '2026-01-01', '2026-06-30');

    expect($analytics['aging']['31_60'])->toBe(300.0)
        ->and($analytics['aging']['total'])->toBe(300.0)
        // 300 open / 600 billed x 181 days.
        ->and($analytics['dpo'])->toBe(90.5)
        ->and($analytics['dso'])->toBeNull();
});
