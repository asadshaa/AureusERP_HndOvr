<?php

/**
 * demo:business: customers, vendors, products, invoices, bills and payments
 * are created through the accounting services and recorded in demo_records;
 * a re-run creates nothing; nothing is queued or mailed. A demo company
 * (ShipLine) gets balanced posted documents and payments, and demo:purge
 * removes exactly them. A live company (Truck It In) gets drafts only --
 * nothing reaches its ledger -- and its purge keeps a demo document someone
 * has posted since, with the customer and products it uses.
 */

use App\Models\DemoRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Support\Models\Company;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 08:00:00', 'UTC'));

    if (Company::query()->whereKey([1, 25])->count() < 2 || ! Company::query()->where('name', 'ShipLine')->exists()) {
        $this->markTestSkipped('Truck It In (1) and ShipLine (25) are not in this database.');
    }
});

/** @return array<string, int> rows of the business tables in a company */
function businessCounts(int $companyId): array
{
    return [
        'partners' => DB::table('partners_partners')->where('company_id', $companyId)->count(),
        'products' => DB::table('products_products')->where('company_id', $companyId)->count(),
        'moves'    => DB::table('accounts_account_moves')->where('company_id', $companyId)->count(),
        'lines'    => DB::table('accounts_account_move_lines')->where('company_id', $companyId)->count(),
        'payments' => DB::table('accounts_account_payments')->where('company_id', $companyId)->count(),
        'posted'   => DB::table('accounts_account_moves')->where('company_id', $companyId)->where('state', 'posted')->count(),
    ];
}

/** @return array<int, int> the company's demo documents (invoices and bills) */
function demoDocumentIds(int $companyId): array
{
    return DemoRecord::query()->where('company_id', $companyId)->where('record_table', 'accounts_account_moves')
        ->where('demo_key', 'like', "business:{$companyId}:%")->pluck('record_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
}

it('seeds a demo company through the accounting services, idempotently, and purges exactly that', function () {
    $before = businessCounts(25);
    $jobs = DB::table('jobs')->count();

    $this->artisan('demo:business', ['company' => ['ShipLine']])->expectsOutputToContain('Not seeded, plugin not installed')->assertSuccessful();

    $documents = Move::query()->whereIn('id', demoDocumentIds(25))->get();
    $after = businessCounts(25);
    $recorded = fn (string $table): array => DemoRecord::query()->where('company_id', 25)->where('phase', DemoRecord::PHASE_BUSINESS)
        ->where('record_table', $table)->pluck('record_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

    expect($documents)->toHaveCount(18 + 14)
        ->and($after['partners'] - $before['partners'])->toBe(7 + 5)
        ->and($after['products'] - $before['products'])->toBe(13)
        ->and($documents->pluck('state')->unique()->map->value->sort()->values()->all())->toBe(['cancel', 'draft', 'posted'])
        ->and($documents->pluck('payment_state')->map->value->intersect(['paid', 'in_payment', 'partial'])->isNotEmpty())->toBeTrue()
        ->and($after['payments'])->toBeGreaterThan($before['payments'])
        // Every new move, line and payment of the company is recorded.
        ->and(count($recorded('accounts_account_moves')))->toBe($after['moves'] - $before['moves'])
        ->and(count($recorded('accounts_account_move_lines')))->toBe($after['lines'] - $before['lines'])
        ->and(count($recorded('accounts_account_payments')))->toBe($after['payments'] - $before['payments'])
        ->and(DB::table('jobs')->count())->toBe($jobs);

    Mail::assertNothingOutgoing();

    foreach ($documents->where('state', MoveState::POSTED) as $move) {
        $lines = MoveLine::query()->where('move_id', $move->id)->get();

        expect(round((float) $lines->sum('debit'), 2))->toBe(round((float) $lines->sum('credit'), 2))
            ->and((float) $move->amount_total)->toBeGreaterThan(0.0)
            ->and($lines->pluck('company_id')->unique()->all())->toBe([25])
            ->and($move->journal->company_id)->toBe(25)
            ->and($move->invoice_date->toDateString() >= '2026-08-03' && $move->invoice_date->toDateString() <= '2026-11-03')->toBeTrue();
    }

    // A re-run creates nothing.
    $records = DemoRecord::query()->count();
    $this->artisan('demo:business', ['company' => ['25']])->expectsOutputToContain('Created this run: nothing')->assertSuccessful();
    expect(businessCounts(25))->toBe($after)->and(DemoRecord::query()->count())->toBe($records);

    $this->artisan('demo:purge', ['--company' => ['ShipLine'], '--force' => true])->assertSuccessful();

    expect(businessCounts(25))->toBe($before)
        ->and(DemoRecord::query()->where('company_id', 25)->where('phase', DemoRecord::PHASE_BUSINESS)->count())->toBe(0);
});

it('gives a live company drafts only and keeps a demo document posted since when purging', function () {
    $before = businessCounts(1);
    $postedLines = DB::table('accounts_account_move_lines')->where('company_id', 1)->where('parent_state', 'posted')->count();

    $this->artisan('demo:business', ['company' => ['Truck It In']])->expectsOutputToContain('nothing is posted')->assertSuccessful();

    $documents = Move::query()->whereIn('id', demoDocumentIds(1))->get();

    expect($documents)->toHaveCount(14 + 12)
        ->and($documents->every(fn (Move $move): bool => $move->state === MoveState::DRAFT))->toBeTrue()
        ->and($documents->every(fn (Move $move): bool => (float) $move->amount_total > 0))->toBeTrue()
        // Truck It In is sales-tax registered: its invoices carry sales tax.
        ->and($documents->filter(fn (Move $move): bool => (float) $move->amount_tax > 0)->isNotEmpty())->toBeTrue()
        ->and($documents->every(fn (Move $move): bool => $move->invoice_date->toDateString() > '2026-09-01'))->toBeTrue()
        ->and(businessCounts(1)['posted'])->toBe($before['posted'])
        ->and(businessCounts(1)['payments'])->toBe($before['payments'])
        ->and(DB::table('accounts_account_move_lines')->where('company_id', 1)->where('parent_state', 'posted')->count())->toBe($postedLines);

    // Finance posts one of the demo drafts: it is part of the books now.
    $posted = AccountFacade::confirmMove($documents->firstWhere('move_type.value', 'out_invoice'));
    $productIds = MoveLine::query()->where('move_id', $posted->id)->whereNotNull('product_id')->pluck('product_id')->unique()->values();

    $this->artisan('demo:purge', ['--company' => ['Truck It In'], '--force' => true])
        ->expectsOutputToContain('were kept')
        ->assertSuccessful();

    $remaining = businessCounts(1);

    expect(Move::query()->find($posted->id)?->state)->toBe(MoveState::POSTED)
        ->and(DB::table('partners_partners')->where('id', $posted->partner_id)->exists())->toBeTrue()
        ->and(DB::table('products_products')->whereIn('id', $productIds)->count())->toBe($productIds->count())
        ->and(demoDocumentIds(1))->toBe([$posted->id])
        ->and($remaining['moves'])->toBe($before['moves'] + 1)
        ->and($remaining['partners'])->toBe($before['partners'] + 1)
        ->and($remaining['products'])->toBe($before['products'] + $productIds->count());
});
