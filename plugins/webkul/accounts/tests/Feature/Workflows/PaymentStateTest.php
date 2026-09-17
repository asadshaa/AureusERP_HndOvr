<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Payment;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/AccountHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');

    AccountHelper::actingAsAdmin();
});

it('does not mark a payment paid when the real residual only reads as zero under the wrong currency\'s rounding', function () {
    $bank = AccountHelper::bankJournal();
    $liquidityAccount = Account::find($bank->default_account_id);

    // Deliberately much coarser than the company currency's own rounding, the
    // way JPY (rounding 1) is coarser than a company that books in BHD
    // (rounding 0.001). The exact numbers don't matter, only that `$coarse`
    // is comfortably bigger than `$fine`.
    $fine = AccountHelper::company()->currency->rounding;
    $coarse = Currency::factory()->create(['rounding' => max($fine * 100, 1)]);

    // Bigger than the company currency's own zero-threshold (so it is a real,
    // outstanding residual) but smaller than half of the coarse currency's
    // rounding (so the coarse currency's threshold misreads it as zero).
    $realResidual = $fine * 10;

    $move = AccountHelper::journalEntry(null, [
        'move_type'   => MoveType::ENTRY,
        'state'       => MoveState::POSTED,
        'currency_id' => $coarse->id,
    ]);

    $line = AccountHelper::entryLine($move, $liquidityAccount, debit: 100, credit: 0);
    $line->update(['amount_residual' => $realResidual]);

    $payment = Payment::query()->create([
        'move_id'                => $move->id,
        'journal_id'             => $bank->id,
        'company_id'             => AccountHelper::company()->id,
        'currency_id'            => $coarse->id,
        'payment_method_line_id' => $bank->inboundPaymentMethodLines->first()->id,
        'outstanding_account_id' => $liquidityAccount->id,
        'destination_account_id' => $liquidityAccount->id,
        'payment_type'           => PaymentType::RECEIVE,
        'state'                  => PaymentStatus::IN_PROCESS,
        'date'                   => now(),
        'amount'                 => 100,
    ]);

    // A genuinely unpaid residual (in the company's own currency terms) must
    // not be waved through as PAID just because it looks small under a
    // coarser transaction currency's rounding.
    expect($payment->fresh()->state)->toBe(PaymentStatus::IN_PROCESS);
});
