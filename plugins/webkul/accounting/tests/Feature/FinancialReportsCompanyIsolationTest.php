<?php

use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Enums\ValueBasis;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\FinancialReports;
use Webkul\Accounting\Models\ReportColumn;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineAccount;
use Webkul\Accounting\Models\ReportLineFormula;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\ReportCacheKey;
use Webkul\Accounting\Services\ReportQueryService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function isolationPost(Company $company, Account $account, float $balance, string $date): void
{
    $journal = Journal::factory()->create([
        'company_id'         => $company->id,
        'default_account_id' => $account->id,
    ]);

    $move = Move::factory()->create([
        'company_id' => $company->id,
        'journal_id' => $journal->id,
        'state'      => MoveState::POSTED,
        'date'       => $date,
    ]);

    MoveLine::factory()->create([
        'move_id'      => $move->id,
        'journal_id'   => $journal->id,
        'company_id'   => $company->id,
        'account_id'   => $account->id,
        'balance'      => $balance,
        'debit'        => $balance >= 0 ? $balance : 0,
        'credit'       => $balance < 0 ? -$balance : 0,
        'parent_state' => MoveState::POSTED,
        'date'         => $date,
    ]);
}

/**
 * A global template with an entity column for each company, a consolidated
 * column, a detail line, a detail line scoped to $other and subtotals.
 *
 * @return array<string, mixed>
 */
function isolationTemplate(Company $mine, Company $other, Account $account): array
{
    $template = ReportTemplate::factory()->create(['name' => 'Isolation Group BS', 'company_id' => null]);

    $detail = ReportLine::factory()->create(['report_template_id' => $template->id, 'caption' => 'Trade Debts', 'sort' => 1]);
    ReportLineAccount::factory()->create(['report_line_id' => $detail->id, 'account_id' => $account->id, 'sign' => 1]);

    $otherNi = ReportLine::factory()->create(['report_template_id' => $template->id, 'caption' => 'Other Entity NI', 'company_id' => $other->id, 'sort' => 2]);
    ReportLineAccount::factory()->create(['report_line_id' => $otherNi->id, 'account_id' => $account->id, 'sign' => 1]);

    $totalNi = ReportLine::factory()->subtotal()->create(['report_template_id' => $template->id, 'caption' => 'Total NI', 'sort' => 3]);
    ReportLineFormula::factory()->create(['report_line_id' => $totalNi->id, 'operand_line_id' => $detail->id, 'operator' => '+', 'sort' => 0]);
    ReportLineFormula::factory()->create(['report_line_id' => $totalNi->id, 'operand_line_id' => $otherNi->id, 'operator' => '+', 'sort' => 1]);

    $ownTotal = ReportLine::factory()->subtotal()->create(['report_template_id' => $template->id, 'caption' => 'Own Total', 'sort' => 4]);
    ReportLineFormula::factory()->create(['report_line_id' => $ownTotal->id, 'operand_line_id' => $detail->id, 'operator' => '+', 'sort' => 0]);

    return [
        'template'  => $template,
        'detail'    => $detail,
        'other_ni'  => $otherNi,
        'total_ni'  => $totalNi,
        'own_total' => $ownTotal,
        'col_mine'  => ReportColumn::factory()->fullYear()->create(['report_template_id' => $template->id, 'label' => 'Mine Entity', 'company_id' => $mine->id, 'sort' => 1]),
        'col_other' => ReportColumn::factory()->fullYear()->create(['report_template_id' => $template->id, 'label' => 'Other Entity', 'company_id' => $other->id, 'sort' => 2]),
        'col_cons'  => ReportColumn::factory()->fullYear()->consolidated()->create(['report_template_id' => $template->id, 'label' => 'Consolidated', 'sort' => 3]),
    ];
}

/**
 * @param  array<int, Company>  $allowed
 */
function isolationUser(Company $default, array $allowed = []): User
{
    $user = User::factory()->create(['is_active' => true, 'default_company_id' => $default->id]);
    $user->allowedCompanies()->syncWithoutDetaching(collect([$default, ...$allowed])->pluck('id')->all());

    Permission::findOrCreate('page_accounting_financial_reports', 'web');
    $user->givePermissionTo('page_accounting_financial_reports');

    return $user;
}

it('never computes entity columns or lines of companies outside the authorised set', function () {
    $mine = Company::factory()->create();
    $other = Company::factory()->create();
    $account = Account::factory()->create();

    isolationPost($mine, $account, 4321.0, '2025-03-10');
    isolationPost($other, $account, 98765.0, '2025-03-10');

    $fixture = isolationTemplate($mine, $other, $account);
    $service = app(ReportQueryService::class);

    $restricted = ReportContext::forCompanies([$mine->id])->restrictedTo([$mine->id]);
    $rows = $service->getReport($fixture['template'], 2025, $restricted)->keyBy('lineId');

    $mineKey = 'col_'.$fixture['col_mine']->id;
    $otherKey = 'col_'.$fixture['col_other']->id;
    $consKey = 'col_'.$fixture['col_cons']->id;

    // The viewer's own entity column and the consolidated (run-scope) column.
    expect($rows[$fixture['detail']->id]->valueFor($mineKey))->toBe(4321.0)
        ->and($rows[$fixture['detail']->id]->valueFor($consKey))->toBe(4321.0)
        ->and($rows[$fixture['own_total']->id]->valueFor($mineKey))->toBe(4321.0);

    // The other company's column is not available for every line.
    foreach (['detail', 'other_ni', 'total_ni', 'own_total'] as $line) {
        expect($rows[$fixture[$line]->id]->valueFor($otherKey))->toBeNull()
            ->and($rows[$fixture[$line]->id]->isAvailable($otherKey))->toBeFalse();
    }

    // A line scoped to the other company — and any total built on it — is
    // not available in any column.
    foreach ([$mineKey, $consKey] as $key) {
        expect($rows[$fixture['other_ni']->id]->valueFor($key))->toBeNull()
            ->and($rows[$fixture['total_ni']->id]->valueFor($key))->toBeNull();
    }

    expect($service->columnsFor($fixture['template'], 2025, $restricted)[1]->isAvailableIn($restricted))->toBeFalse();

    // An unrestricted (internal) run keeps the full entity matrix.
    $internal = $service->getReport($fixture['template'], 2025, ReportContext::forCompanies([$mine->id]), useCache: false)->keyBy('lineId');

    expect($internal[$fixture['detail']->id]->valueFor($otherKey))->toBe(98765.0)
        ->and($internal[$fixture['total_ni']->id]->valueFor($mineKey))->toBe(4321.0 + 98765.0);
});

it('never widens an empty restricted scope to every company', function () {
    $company = Company::factory()->create();
    $account = Account::factory()->create();

    isolationPost($company, $account, 500.0, '2025-03-10');

    $template = ReportTemplate::factory()->create();
    $line = ReportLine::factory()->create(['report_template_id' => $template->id]);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);

    $context = ReportContext::forCompanies([])->restrictedTo([]);

    expect($context->hasCompanyScope())->toBeTrue()
        ->and($context->narrowTo([$company->id]))->toBeNull();

    $rows = app(ReportQueryService::class)->getReport($template, 2025, $context, useCache: false)->keyBy('lineId');

    expect($rows[$line->id]->valueFor('2025'))->toBe(0.0);
});

it('shows another company\'s entity columns as not available to a company 1 user, even after an authorised user cached the run', function () {
    $companyOne = Company::query()->findOrFail(1);
    $other = Company::factory()->create(['name' => 'Isolation Other Entity']);
    $account = Account::factory()->create();

    isolationPost($companyOne, $account, 4321.0, '2025-03-10');
    isolationPost($other, $account, 98765.0, '2025-03-10');

    $fixture = isolationTemplate($companyOne, $other, $account);
    $url = FinancialReports::getUrl(['template' => $fixture['template']->id, 'year' => 2025]);

    // A user of both companies sees both entities — and fills the cache for
    // the same template, year and run scope (company 1).
    test()->actingAs(isolationUser($companyOne, [$other]));

    $both = test()->get($url)->assertOk()->getContent();

    expect($both)->toContain('98,765')
        ->and($both)->toContain('4,321');

    // A user of company 1 only must not be served those cached figures.
    test()->actingAs(isolationUser($companyOne));

    $html = test()->get($url)->assertOk()->getContent();

    expect($html)->toContain('Other Entity')
        ->and($html)->toContain('4,321')
        ->and($html)->toContain('Not available')
        ->and($html)->toContain('n/a')
        ->and($html)->not->toContain('98,765')
        ->and($html)->not->toContain('103,086');
});

it('limits the run scope to the companies the user belongs to', function () {
    $companyOne = Company::query()->findOrFail(1);
    $other = Company::factory()->create();
    $account = Account::factory()->create();

    isolationPost($other, $account, 98765.0, '2025-03-10');

    $template = ReportTemplate::factory()->create(['company_id' => null]);
    $line = ReportLine::factory()->create(['report_template_id' => $template->id, 'caption' => 'Trade Debts']);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);

    test()->actingAs(isolationUser($companyOne));

    // A forged company filter for a company the user does not belong to.
    $component = Livewire::test(FinancialReports::class)
        ->set('data.template_id', $template->id)
        ->set('data.year', 2025)
        ->set('data.companies', [$other->id]);

    expect($component->instance()->reportData)->toBeNull();

    $component->set('data.companies', [$companyOne->id, $other->id]);
    $report = $component->instance()->reportData;
    $row = collect($report['rows'])->firstWhere('lineId', $line->id);

    expect($row->valueFor('2025'))->toBe(0.0);
});

it('keys the report cache by the viewer authorised companies', function () {
    $mine = Company::factory()->create();
    $other = Company::factory()->create();
    $template = ReportTemplate::factory()->create();

    $columns = [];
    $wide = ReportContext::forCompanies([$mine->id])->restrictedTo([$mine->id, $other->id]);
    $narrow = ReportContext::forCompanies([$mine->id])->restrictedTo([$mine->id]);

    expect(ReportCacheKey::forColumns($template, $columns, $wide, ValueBasis::MOVEMENT))
        ->not->toBe(ReportCacheKey::forColumns($template, $columns, $narrow, ValueBasis::MOVEMENT));
});
