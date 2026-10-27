<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/FilamentHelper.php';
require_once __DIR__.'/../../Helpers/AccountHelper.php';

if (! function_exists('flushTerminatingCallbacks')) {
    /**
     * Chatter queues follower notifications with app()->terminating(), which the
     * test application never clears, so every Livewire request in a test would
     * replay them. Run them once and forget them before measuring a render.
     */
    function flushTerminatingCallbacks(): void
    {
        app()->terminate();
        (fn () => $this->terminatingCallbacks = [])->call(app());
    }
}

if (! function_exists('selectableMoveKeys')) {
    /**
     * Draft moves of this type the active company already has (the test
     * database is a copy of the working one, so it is not empty).
     *
     * @return array<int, string>
     */
    function selectableMoveKeys(MoveType $type): array
    {
        return Move::query()
            ->where('company_id', Auth::user()->default_company_id)
            ->where('move_type', $type)
            ->where('state', '!=', MoveState::POSTED)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }
}

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

/**
 * Seeds the bill list for the performance regressions in
 * docs/PERFORMANCE_BASELINE_PHASE1.md §2.5 (INV-1, INV-2).
 *
 * @return array{drafts: array<int, Move>, posted: array<int, Move>, other: array<int, Move>}
 */
function seedBillListDataset(int $drafts = 30, int $posted = 30, int $otherCompany = 5): array
{
    $partner = AccountHelper::partner();
    $journal = AccountHelper::purchaseJournal();
    $otherCompanyId = Company::factory()->create()->id;

    $make = fn (array $overrides): Move => AccountHelper::invoice(MoveType::IN_INVOICE, $partner, $journal, $overrides);

    return [
        'drafts' => collect(range(1, $drafts))->map(fn (int $i): Move => $make([]))->all(),
        'posted' => collect(range(1, $posted))->map(fn (int $i): Move => $make(['state' => MoveState::POSTED]))->all(),
        'other'  => collect(range(1, $otherCompany))->map(fn (int $i): Move => $make(['company_id' => $otherCompanyId]))->all(),
    ];
}

it('renders the bill list and select-all without hydrating every bill', function () {
    FilamentHelper::actingAs(['view_any_account_bill', 'delete_account_bill', 'delete_any_account_bill']);

    $alreadySelectable = selectableMoveKeys(MoveType::IN_INVOICE);
    $dataset = seedBillListDataset();
    flushTerminatingCallbacks();

    $retrievedMoves = 0;

    Event::listen('eloquent.retrieved: *', function (string $event, array $payload) use (&$retrievedMoves): void {
        if (($payload[0] ?? null) instanceof Move) {
            $retrievedMoves++;
        }
    });

    $component = Livewire::test(ListBills::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords($dataset['other']);

    expect($retrievedMoves)->toBeLessThanOrEqual($component->instance()->getTableRecordsPerPage());

    $retrievedMoves = 0;

    $keys = $component->instance()->getAllSelectableTableRecordKeys();

    expect($retrievedMoves)->toBe(0)
        ->and($component->instance()->getAllSelectableTableRecordsCount())->toBe(count($alreadySelectable) + count($dataset['drafts']))
        ->and($keys)->toEqualCanonicalizing([...$alreadySelectable, ...collect($dataset['drafts'])->map(fn (Move $move): string => (string) $move->id)->all()]);
});

it('keeps posted bills unselectable and halts bulk delete on a posted bill', function () {
    FilamentHelper::actingAs(['view_any_account_bill', 'delete_account_bill', 'delete_any_account_bill']);

    $draft = AccountHelper::invoice(MoveType::IN_INVOICE);
    $posted = AccountHelper::invoice(MoveType::IN_INVOICE, overrides: ['state' => MoveState::POSTED]);

    $component = Livewire::test(ListBills::class)->assertOk();

    expect($component->instance()->getTable()->isRecordSelectable($posted))->toBeFalse()
        ->and($component->instance()->getAllSelectableTableRecordKeys())->toBe([(string) $draft->id]);

    $component->callTableBulkAction('delete', [$draft, $posted]);

    expect(Move::query()->whereKey([$draft->id, $posted->id])->count())->toBe(2);
});

it('lists bills newest first by default and sorts by due date', function () {
    FilamentHelper::actingAs(['view_any_account_bill']);

    $partner = AccountHelper::partner();
    $journal = AccountHelper::purchaseJournal();

    $make = fn (int $dueInDays): Move => AccountHelper::invoice(MoveType::IN_INVOICE, $partner, $journal, [
        'invoice_date_due' => now()->addDays($dueInDays)->toDateString(),
    ]);

    $dueLast = $make(30);
    $dueFirst = $make(1);
    $dueMiddle = $make(10);

    Livewire::test(ListBills::class)
        ->searchTable($partner->name)
        ->assertCanSeeTableRecords([$dueMiddle, $dueFirst, $dueLast], inOrder: true)
        ->sortTable('invoice_date_due')
        ->assertCanSeeTableRecords([$dueFirst, $dueMiddle, $dueLast], inOrder: true);
});
