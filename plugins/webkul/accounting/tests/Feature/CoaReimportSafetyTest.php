<?php

/**
 * CoA import safety: rows whose code belongs to a manual account are reported
 * (not silently skipped), a re-import never re-types an account with posted
 * lines unless explicitly allowed (and recorded), and duplicate codes are
 * detected ignoring case and a spreadsheet ".0".
 */

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Data\Coa\CoaRow;
use Webkul\Accounting\Data\Coa\CoaWarning;
use Webkul\Accounting\Services\Coa\CoaImportService;
use Webkul\Accounting\Services\Coa\CoaRowValidator;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function reimportRow(string $code, string $title, array $classifications = ['Liabilities', 'Current liabilities'], string $nature = 'B/S'): CoaRow
{
    return new CoaRow(1, $nature, $classifications, $code, $title, 0, 0, 0, 0, 0, 0, 0, 0);
}

beforeEach(function () {
    $currency = Currency::query()->where('name', 'PKR')->first() ?? Currency::query()->firstOrFail();
    $this->company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $this->service = app(CoaImportService::class);
});

it('reports rows whose code belongs to an existing manual account instead of skipping them silently', function () {
    $manual = Account::factory()->create(['code' => 'RE-100', 'name' => 'Manual accrual', 'account_type' => AccountType::LIABILITY_CURRENT]);
    $manual->companies()->sync([$this->company->id]);

    $batch = $this->service->import(rows: [reimportRow('re-100', 'Imported accrual'), reimportRow('RE-101', 'Other accrual')], company: $this->company);

    expect($batch->options['code_collision_count'])->toBe(1)
        ->and($batch->options['code_collisions'][0]['existing_account_id'])->toBe($manual->id)
        ->and(collect($batch->options['warnings'])->pluck('code'))->toContain('code_collision')
        ->and($manual->refresh()->name)->toBe('Manual accrual')
        ->and($batch->accounts_created)->toBe(1);
});

it('refuses to re-type an imported account with posted lines unless explicitly allowed', function () {
    $rows = [reimportRow('RE-200', 'Accrued expenses')];
    $first = $this->service->import(rows: $rows, company: $this->company);
    $account = Account::query()->where('import_batch_id', $first->id)->where('code', 'RE-200')->firstOrFail();

    expect($account->account_type)->toBe(AccountType::LIABILITY_CURRENT);

    $move = Move::factory()->posted()->create(['company_id' => $this->company->id]);
    MoveLine::factory()->create(['move_id' => $move->id, 'account_id' => $account->id, 'parent_state' => MoveState::POSTED]);

    $override = ['RE-200' => AccountType::LIABILITY_NON_CURRENT->value];

    expect(fn () => $this->service->import(rows: $rows, company: $this->company, typeOverrides: $override))
        ->toThrow(RuntimeException::class, 'posted journal items');

    expect($account->refresh()->account_type)->toBe(AccountType::LIABILITY_CURRENT);

    $allowed = $this->service->import(rows: $rows, company: $this->company, typeOverrides: $override, options: ['allow_posted_type_change' => true]);

    expect($account->refresh()->account_type)->toBe(AccountType::LIABILITY_NON_CURRENT)
        ->and($allowed->options['allow_posted_type_change'])->toBeTrue()
        ->and($allowed->options['posted_type_changes'])->toEqual([[
            'code' => 'RE-200', 'account_id' => $account->id, 'from' => 'liability_current', 'to' => 'liability_non_current',
        ]]);

    // Unchanged types re-import without any override.
    expect($this->service->import(rows: $rows, company: $this->company, typeOverrides: $override)->options['posted_type_changes'])->toBe([]);
});

it('detects duplicate codes ignoring case and a trailing .0', function () {
    $errors = collect((new CoaRowValidator)->validate([
        reimportRow('A100', 'First'),
        reimportRow('a100', 'Second'),
        reimportRow('1011', 'Third'),
        reimportRow('1011.0', 'Fourth'),
        reimportRow('1012', 'Fifth'),
    ]))->filter(fn (CoaWarning $warning) => $warning->code === 'duplicate_code')->pluck('accountCode')->all();

    expect($errors)->toBe(['A100', 'a100', '1011', '1011.0'])
        ->and(CoaRow::normalizeCode(' a100 '))->toBe('A100')
        ->and(CoaRow::normalizeCode('1011.00'))->toBe('1011');
});
