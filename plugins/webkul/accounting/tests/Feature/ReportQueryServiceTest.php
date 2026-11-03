<?php

use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Accounting\Data\ReportContext;
use Webkul\Accounting\Enums\ValueSource;
use Webkul\Accounting\Models\ReportColumn;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Models\ReportLineAccount;
use Webkul\Accounting\Models\ReportLineInput;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\ReportQueryService;
use Webkul\Support\Models\Company;

function queryService(): ReportQueryService
{
    return app(ReportQueryService::class);
}

it('serves cached results and invalidates them when a manual input is saved', function () {
    $company = Company::factory()->create();
    $template = ReportTemplate::factory()->monthlyMatrix()->create();

    $line = ReportLine::factory()->create([
        'report_template_id' => $template->id, 'line_type' => 'detail', 'value_source' => ValueSource::MANUAL,
    ]);

    ReportLineInput::factory()->create(['report_line_id' => $line->id, 'date' => '2025-01-15', 'value' => 10]);

    $context = ReportContext::forCompany($company->id);
    $service = queryService();

    $first = $service->getReport($template, 2025, $context)->keyBy('lineId');
    expect($first[$line->id]->valueFor('2025-01'))->toBe(10.0);

    // Saving an input touches the line, which touches the template, which
    // rotates the cache key — so the next read recomputes. Timestamps have
    // one-second resolution, so move the clock to make the touch observable.
    test()->travelTo(now()->addMinute());

    ReportLineInput::factory()->create(['report_line_id' => $line->id, 'date' => '2025-01-20', 'value' => 5]);

    $second = $service->getReport($template->fresh(), 2025, $context)->keyBy('lineId');
    expect($second[$line->id]->valueFor('2025-01'))->toBe(15.0);
});

it('exposes resolved columns, including spacers, for renderers', function () {
    $company = Company::factory()->create();
    $template = ReportTemplate::factory()->create();

    ReportColumn::factory()->create(['report_template_id' => $template->id, 'start_month' => 6]);
    ReportColumn::factory()->spacer()->create(['report_template_id' => $template->id]);
    ReportColumn::factory()->fullYear()->create(['report_template_id' => $template->id]);

    $columns = queryService()->columnsFor($template, 2025, ReportContext::forCompany($company->id));

    expect($columns)->toHaveCount(3)
        ->and($columns[1]->isSpacer())->toBeTrue()
        ->and($columns[0]->isSpacer())->toBeFalse();
});

/**
 * @return array{0: Move, 1: MoveLine}
 */
function queryServicePost(Company $company, Account $account, float $balance, string $date, MoveState $state = MoveState::POSTED): array
{
    $journal = Journal::factory()->create(['company_id' => $company->id, 'default_account_id' => $account->id]);

    $move = Move::factory()->create([
        'company_id' => $company->id,
        'journal_id' => $journal->id,
        'state'      => $state,
        'date'       => $date,
    ]);

    $line = MoveLine::factory()->create([
        'move_id'      => $move->id,
        'journal_id'   => $journal->id,
        'company_id'   => $company->id,
        'account_id'   => $account->id,
        'balance'      => $balance,
        'debit'        => $balance >= 0 ? $balance : 0,
        'credit'       => $balance < 0 ? -$balance : 0,
        'parent_state' => $state,
        'date'         => $date,
    ]);

    return [$move, $line];
}

it('shows postings, reversals and resets immediately despite the report cache', function () {
    $company = Company::factory()->create();
    $account = Account::factory()->create();
    $template = ReportTemplate::factory()->create();
    $line = ReportLine::factory()->create(['report_template_id' => $template->id]);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);

    $context = ReportContext::forCompany($company->id);
    $service = queryService();
    $value = fn (): ?float => $service->getReport($template, 2025, $context)->keyBy('lineId')[$line->id]->valueFor('2025');

    [$first, $firstLine] = queryServicePost($company, $account, 100.0, '2025-02-10');
    expect($value())->toBe(100.0);

    // Eloquent posting: visible on the very next read (no TTL wait).
    [$second] = queryServicePost($company, $account, 50.0, '2025-03-10');
    expect($value())->toBe(150.0);

    // A reversal is a new posted move.
    queryServicePost($company, $account, -50.0, '2025-03-11');
    expect($value())->toBe(100.0);

    // Raw query-builder state change (the bank/manual-adjustment posting
    // path bypasses model events): reset the first move to draft.
    DB::table('accounts_account_moves')->where('id', $first->id)->update(['state' => MoveState::DRAFT->value]);
    expect($value())->toBe(0.0);

    // ...and post it again through the same raw path.
    DB::table('accounts_account_moves')->where('id', $first->id)->update(['state' => MoveState::POSTED->value]);
    expect($value())->toBe(100.0);

    // A journal-item edit through Eloquent that leaves the move row alone.
    $firstLine->refresh()->forceFill(['balance' => 70.0, 'debit' => 70.0])->save();
    expect($value())->toBe(70.0);

    // Drafts never count, whether created or deleted.
    [$draft] = queryServicePost($company, $account, 999.0, '2025-04-01', MoveState::DRAFT);
    expect($value())->toBe(70.0);
    $draft->delete();
    expect($value())->toBe(70.0);
});

it('still serves cached results while the ledger is unchanged', function () {
    $company = Company::factory()->create();
    $account = Account::factory()->create();
    $template = ReportTemplate::factory()->create();
    $line = ReportLine::factory()->create(['report_template_id' => $template->id]);
    ReportLineAccount::factory()->create(['report_line_id' => $line->id, 'account_id' => $account->id, 'sign' => 1]);

    [, $posted] = queryServicePost($company, $account, 100.0, '2025-02-10');

    $context = ReportContext::forCompany($company->id);
    $service = queryService();

    expect($service->getReport($template, 2025, $context)->keyBy('lineId')[$line->id]->valueFor('2025'))->toBe(100.0);

    // An out-of-band line edit that neither fires model events nor touches
    // any move is not part of the stamp: the cached run is reused.
    DB::table('accounts_account_move_lines')->where('id', $posted->id)->update(['balance' => 1.0]);

    expect($service->getReport($template, 2025, $context)->keyBy('lineId')[$line->id]->valueFor('2025'))->toBe(100.0)
        ->and($service->getReport($template, 2025, $context, useCache: false)->keyBy('lineId')[$line->id]->valueFor('2025'))->toBe(1.0);
});
