<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Filament\Resources\BillResource\Pages\CreateBill;
use Webkul\Account\Filament\Resources\BillResource\Pages\EditBill;
use Webkul\Account\Filament\Resources\BillResource\Pages\ListBills;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\CancelAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\ConfirmAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\PayAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\ResetToDraftAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\ReverseAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\SetAsCheckedAction;
use Webkul\Account\Mail\Invoice\Actions\InvoiceEmail;
use Webkul\Account\Models\Move;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/FilamentHelper.php';
require_once __DIR__.'/../../Helpers/AccountHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');
});

it('forbids listing bills without permission', function () {
    FilamentHelper::actingAs([]);

    Livewire::test(ListBills::class)->assertForbidden();
});

it('lists bills with their key columns for authorized users', function () {
    FilamentHelper::actingAs(['view_any_account_bill']);

    Livewire::test(ListBills::class)
        ->assertOk()
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('state');
});

it('renders the bill create page', function () {
    FilamentHelper::actingAs(['view_any_account_bill', 'create_account_bill']);

    Livewire::test(CreateBill::class)->assertOk();
});

it('creates a draft bill with a number through the create form', function () {
    FilamentHelper::actingAs(['view_any_account_bill', 'create_account_bill']);

    $partner = AccountHelper::partner();

    Livewire::test(CreateBill::class)
        ->fillForm([
            'partner_id'   => $partner->id,
            'invoice_date' => now(),
            'journal_id'   => AccountHelper::purchaseJournal()->id,
            'currency_id'  => AccountHelper::currency()->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $bill = Move::query()
        ->where('partner_id', $partner->id)
        ->where('move_type', MoveType::IN_INVOICE)
        ->first();

    expect($bill)->not->toBeNull()
        ->and($bill->name)->not->toBeNull();
});

it('posts a draft bill through the confirm action', function () {
    // accounting_post_journal is required alongside update_account_bill
    // since DEF-009: posting/paying/cancelling/reversing/resetting a bill
    // is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_bill', 'update_account_bill', 'accounting_post_journal']);

    $bill = AccountHelper::invoice(MoveType::IN_INVOICE, null, null, ['invoice_date' => now()]);
    AccountHelper::productLine($bill, AccountHelper::account('expense'), qty: 2, priceUnit: 100);
    AccountHelper::compute($bill);

    Livewire::test(EditBill::class, ['record' => $bill->id])
        ->assertOk()
        ->assertActionExists(ConfirmAction::class)
        ->callAction(ConfirmAction::class);

    expect($bill->refresh()->state)->toBe(MoveState::POSTED);
});

it('cancels a draft bill through the cancel action', function () {
    // accounting_post_journal is required alongside update_account_bill
    // since DEF-009: posting/paying/cancelling/reversing/resetting a bill
    // is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_bill', 'update_account_bill', 'accounting_post_journal']);

    $bill = AccountHelper::invoice(MoveType::IN_INVOICE, null, null, ['invoice_date' => now()]);

    Livewire::test(EditBill::class, ['record' => $bill->id])
        ->assertOk()
        ->callAction(CancelAction::class);

    expect($bill->refresh()->state)->toBe(MoveState::CANCEL);
});

function postedBillRecord(): Move
{
    $bill = AccountHelper::invoice(MoveType::IN_INVOICE, null, null, ['invoice_date' => now()]);

    AccountHelper::productLine($bill, AccountHelper::account('expense'), qty: 2, priceUnit: 100);

    return AccountHelper::post($bill);
}

it('reverses a posted bill into a refund through the action', function () {
    // accounting_post_journal is required alongside update_account_bill
    // since DEF-009: posting/paying/cancelling/reversing/resetting a bill
    // is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_bill', 'update_account_bill', 'accounting_post_journal']);

    $bill = postedBillRecord();

    Livewire::test(EditBill::class, ['record' => $bill->id])
        ->assertOk()
        ->callAction(ReverseAction::class, data: [
            'reason'     => 'Test reversal',
            'journal_id' => $bill->journal_id,
            'date'       => now(),
        ]);

    expect(
        Move::query()
            ->where('reversed_entry_id', $bill->id)
            ->where('move_type', MoveType::IN_REFUND)
            ->exists()
    )->toBeTrue();
});

it('resets a posted bill to draft through the action', function () {
    // accounting_post_journal is required alongside update_account_bill
    // since DEF-009: posting/paying/cancelling/reversing/resetting a bill
    // is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_bill', 'update_account_bill', 'accounting_post_journal']);

    $bill = postedBillRecord();

    Livewire::test(EditBill::class, ['record' => $bill->id])
        ->assertOk()
        ->callAction(ResetToDraftAction::class);

    expect($bill->refresh()->state)->toBe(MoveState::DRAFT);
});

it('marks a posted bill as checked through the action', function () {
    // accounting_post_journal is required alongside update_account_bill
    // since DEF-009: posting/paying/cancelling/reversing/resetting a bill
    // is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_bill', 'update_account_bill', 'accounting_post_journal']);

    $bill = postedBillRecord();

    Livewire::test(EditBill::class, ['record' => $bill->id])
        ->assertOk()
        ->callAction(SetAsCheckedAction::class);

    expect($bill->refresh()->checked)->toBeTrue();
});

it('registers a full payment and marks the bill paid through the action', function () {
    // accounting_post_journal is required alongside update_account_bill
    // since DEF-009: posting/paying/cancelling/reversing/resetting a bill
    // is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_bill', 'update_account_bill', 'accounting_post_journal']);

    $bankJournal = AccountHelper::bankJournal();
    $paymentMethodLine = $bankJournal->outboundPaymentMethodLines->first();

    $bill = postedBillRecord();

    Livewire::test(EditBill::class, ['record' => $bill->id])
        ->assertOk()
        ->callAction(PayAction::class, data: [
            'journal_id'             => $bankJournal->id,
            'payment_method_line_id' => $paymentMethodLine->id,
            'amount'                 => $bill->amount_total,
            'currency_id'            => $bill->currency_id,
            'payment_date'           => now()->format('Y-m-d'),
            'communication'          => $bill->name,
        ])
        ->assertHasNoActionErrors();

    expect($bill->refresh()->payment_state)->toBe(PaymentState::PAID);
});

it('registers payment and sends paid bill email to vendor', function () {
    Mail::fake();

    // accounting_post_journal is required alongside update_account_bill
    // since DEF-009: posting/paying/cancelling/reversing/resetting a bill
    // is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_bill', 'update_account_bill', 'accounting_post_journal']);

    $bankJournal = AccountHelper::bankJournal();
    $paymentMethodLine = $bankJournal->outboundPaymentMethodLines->first();

    $bill = postedBillRecord();
    $bill->partner->update(['email' => 'vendor@supplier.com']);

    Livewire::test(EditBill::class, ['record' => $bill->id])
        ->assertOk()
        ->callAction(PayAction::class, data: [
            'journal_id'             => $bankJournal->id,
            'payment_method_line_id' => $paymentMethodLine->id,
            'amount'                 => $bill->amount_total,
            'currency_id'            => $bill->currency_id,
            'payment_date'           => now()->format('Y-m-d'),
            'communication'          => $bill->name,
            'send_receipt'           => true,
            'recipient_email'        => 'vendor@supplier.com',
            'email_subject'          => 'Paid Bill Receipt - '.$bill->name,
            'sync_to_paid_drive'     => false,
        ])
        ->assertHasNoActionErrors();

    expect($bill->refresh()->payment_state)->toBe(PaymentState::PAID);

    Mail::assertSent(InvoiceEmail::class, function ($mail) {
        return $mail->payload['to']['address'] === 'vendor@supplier.com';
    });

    expect($bill->messages()->count())->toBeGreaterThan(0);
});

it('updates partner email when paying bill if partner previously had no email', function () {
    Mail::fake();

    // accounting_post_journal is required alongside update_account_bill
    // since DEF-009: posting/paying/cancelling/reversing/resetting a bill
    // is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_bill', 'update_account_bill', 'accounting_post_journal']);

    $bankJournal = AccountHelper::bankJournal();
    $paymentMethodLine = $bankJournal->outboundPaymentMethodLines->first();

    $bill = postedBillRecord();
    $bill->partner->update(['email' => null]);

    Livewire::test(EditBill::class, ['record' => $bill->id])
        ->assertOk()
        ->callAction(PayAction::class, data: [
            'journal_id'             => $bankJournal->id,
            'payment_method_line_id' => $paymentMethodLine->id,
            'amount'                 => $bill->amount_total,
            'currency_id'            => $bill->currency_id,
            'payment_date'           => now()->format('Y-m-d'),
            'communication'          => $bill->name,
            'send_receipt'           => true,
            'recipient_email'        => 'autofilled@supplier.com',
            'sync_to_paid_drive'     => false,
        ])
        ->assertHasNoActionErrors();

    expect($bill->refresh()->payment_state)->toBe(PaymentState::PAID)
        ->and($bill->partner->refresh()->email)->toBe('autofilled@supplier.com');
});
