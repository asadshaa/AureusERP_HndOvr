<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Services\PeriodLockService;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/AccountHelper.php';

/**
 * Regression coverage for the posting-integrity guards on AccountManager and
 * the Move/MoveLine models: reset to draft, posted-entry deletion, the
 * company consistency of a posted entry and the missing-FX-rate block.
 */
beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');

    $this->user = AccountHelper::actingAsAdmin();

    $this->income = AccountHelper::account('income');
    $this->partner = AccountHelper::partner();
});

function postedIntegrityInvoice(object $test): Move
{
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $test->partner);
    AccountHelper::productLine($invoice, $test->income, qty: 1, priceUnit: 100);

    return AccountHelper::post($invoice);
}

it('refuses to reset a posted invoice whose date falls in a locked period', function () {
    $invoice = postedIntegrityInvoice($this);

    app(PeriodLockService::class)->lockThrough(AccountHelper::company(), $this->user, now()->startOfDay());

    expect(fn () => AccountHelper::resetToDraft($invoice))
        ->toThrow(Exception::class, 'closed accounting period');

    expect($invoice->refresh()->state)->toBe(MoveState::POSTED)
        ->and($invoice->posted_at)->not->toBeNull();
});

it('refuses to reset a posted entry owned by a source workflow and points to reversal', function (string $column, string $value) {
    $invoice = postedIntegrityInvoice($this);
    DB::table('accounts_account_moves')->where('id', $invoice->id)->update([$column => $value]);

    expect(AccountFacade::resetToDraftBlockedReason($invoice->refresh()))->toContain('reversing entry')
        ->and(fn () => AccountHelper::resetToDraft($invoice))
        ->toThrow(Exception::class, 'reversing entry');

    expect($invoice->refresh()->state)->toBe(MoveState::POSTED);
})->with([
    'bank mapping'      => ['accounting_source_type', 'bank_mapping'],
    'manual adjustment' => ['accounting_source_type', 'manual_adjustment'],
    'drive ingestion'   => ['accounting_source_type', 'drive_ingestion'],
    'employee claim'    => ['accounting_source_type', 'employee_request'],
    'CoA migration'     => ['coa_migration_kind', 'opening'],
]);

it('still resets an ordinary posted invoice to draft', function () {
    $invoice = postedIntegrityInvoice($this);

    expect(AccountFacade::resetToDraftBlockedReason($invoice))->toBeNull();

    AccountHelper::resetToDraft($invoice);

    expect($invoice->refresh()->state)->toBe(MoveState::DRAFT);
});

it('refuses to delete a posted move or any of its lines at model level', function () {
    $invoice = postedIntegrityInvoice($this);
    $line = $invoice->lines()->firstOrFail();

    expect(fn () => $invoice->delete())->toThrow(RuntimeException::class, 'cannot be deleted')
        ->and(fn () => $line->delete())->toThrow(RuntimeException::class, 'cannot be deleted');

    expect(Move::query()->whereKey($invoice->id)->exists())->toBeTrue()
        ->and(DB::table('accounts_account_move_lines')->where('id', $line->id)->exists())->toBeTrue();
});

it('still lets the engine regenerate the generated lines of a posted entry when it is re-computed', function () {
    $invoice = postedIntegrityInvoice($this);

    AccountFacade::computeAccountMove($invoice->refresh());

    $lines = $invoice->refresh()->lines;

    expect($invoice->state)->toBe(MoveState::POSTED)
        ->and($lines->firstWhere('display_type', DisplayType::PAYMENT_TERM))->not->toBeNull()
        ->and((float) $lines->sum('debit'))->toBe((float) $lines->sum('credit'));
});

it('still deletes a draft move and its lines', function () {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    $line = AccountHelper::productLine($invoice, $this->income, qty: 1, priceUnit: 100);

    $line->delete();
    $invoice->delete();

    expect(Move::query()->whereKey($invoice->id)->exists())->toBeFalse();
});

it('lets only the explicit maintenance bypass delete a posted move', function () {
    $invoice = postedIntegrityInvoice($this);

    Move::withoutPostedGuard(function () use ($invoice): void {
        $invoice->lines()->delete();
        $invoice->delete();
    });

    expect(Move::query()->whereKey($invoice->id)->exists())->toBeFalse()
        ->and(fn () => postedIntegrityInvoice($this)->delete())
        ->toThrow(RuntimeException::class, 'cannot be deleted');
});

it('refuses to post a line on an account that belongs only to another company', function () {
    $otherCompany = Company::factory()->create();
    $foreignAccount = AccountHelper::account('income');
    $foreignAccount->companies()->attach($otherCompany->id);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    AccountHelper::productLine($invoice, $foreignAccount, qty: 1, priceUnit: 100);

    expect(fn () => AccountHelper::post($invoice))->toThrow(Exception::class, 'is not linked to');
    expect($invoice->refresh()->state)->toBe(MoveState::DRAFT);
});

it('posts a line on an account shared with the move company through the pivot', function () {
    $otherCompany = Company::factory()->create();
    $sharedAccount = AccountHelper::account('income');
    $sharedAccount->companies()->attach([$otherCompany->id, AccountHelper::company()->id]);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    AccountHelper::productLine($invoice, $sharedAccount, qty: 1, priceUnit: 100);

    expect(AccountHelper::post($invoice)->state)->toBe(MoveState::POSTED);
});

it('refuses to post through a journal of another company', function () {
    $otherCompany = Company::factory()->create();
    $journal = Journal::factory()->sale()->create([
        'company_id'         => $otherCompany->id,
        'currency_id'        => AccountHelper::currency()->id,
        'default_account_id' => $this->income->id,
    ]);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner, $journal);
    AccountHelper::productLine($invoice, $this->income, qty: 1, priceUnit: 100);

    expect(fn () => AccountHelper::post($invoice))->toThrow(Exception::class, 'belongs to another company');
    expect($invoice->refresh()->state)->toBe(MoveState::DRAFT);
});

it('keeps a foreign-currency invoice without any exchange rate in draft and blocks posting', function () {
    $rateless = Currency::factory()->create(['active' => true]);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner, null, ['currency_id' => $rateless->id]);
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);
    AccountHelper::compute($invoice);

    expect($invoice->refresh()->state)->toBe(MoveState::DRAFT)
        ->and(fn () => AccountHelper::post($invoice))
        ->toThrow(Exception::class, 'exchange rate');

    expect($invoice->refresh()->state)->toBe(MoveState::DRAFT)
        ->and($invoice->posted_at)->toBeNull();
});

it('posts a foreign-currency invoice once a rate exists for its date', function () {
    $foreign = Currency::factory()->create(['active' => true]);
    AccountHelper::setCurrencyRate($foreign, 2.0);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner, null, ['currency_id' => $foreign->id]);
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);

    $posted = AccountHelper::post($invoice);
    $productLine = $posted->lines->firstWhere('display_type', DisplayType::PRODUCT);

    expect($posted->state)->toBe(MoveState::POSTED)
        ->and((float) abs($productLine->balance))->toBe(100.0);
});
