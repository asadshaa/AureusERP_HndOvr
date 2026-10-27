<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Filament\Resources\InvoiceResource;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\CancelAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\ConfirmAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\PayAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\ResetToDraftAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\ReverseAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\SetAsCheckedAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use Webkul\Account\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use Webkul\Account\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use Webkul\Account\Models\Move;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Security\Bouncer;
use Webkul\Security\Enums\PermissionType;
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

it('forbids listing invoices without permission', function () {
    FilamentHelper::actingAs([]);

    Livewire::test(ListInvoices::class)->assertForbidden();
});

it('lists invoices with their key columns for authorized users', function () {
    FilamentHelper::actingAs(['view_any_account_invoice']);

    Livewire::test(ListInvoices::class)
        ->assertOk()
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('state');
});

it('renders the invoice create page', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'create_account_invoice']);

    Livewire::test(CreateInvoice::class)->assertOk();
});

it('posts a draft invoice through the confirm action', function () {
    // accounting_post_journal is required alongside update_account_invoice
    // since DEF-009: posting/paying/cancelling/reversing/resetting an
    // invoice is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice', 'accounting_post_journal']);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE);
    AccountHelper::productLine($invoice, AccountHelper::account('income'), qty: 2, priceUnit: 100);
    AccountHelper::compute($invoice);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->assertActionExists(ConfirmAction::class)
        ->callAction(ConfirmAction::class);

    expect($invoice->refresh()->state)->toBe(MoveState::POSTED);
});

it('cancels a draft invoice through the cancel action', function () {
    // accounting_post_journal is required alongside update_account_invoice
    // since DEF-009: posting/paying/cancelling/reversing/resetting an
    // invoice is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice', 'accounting_post_journal']);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->callAction(CancelAction::class);

    expect($invoice->refresh()->state)->toBe(MoveState::CANCEL);
});

function postedInvoiceRecord(): Move
{
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE);

    AccountHelper::productLine($invoice, AccountHelper::account('income'), qty: 2, priceUnit: 100);

    return AccountHelper::post($invoice);
}

it('reverses a posted invoice into a credit note through the action', function () {
    // accounting_post_journal is required alongside update_account_invoice
    // since DEF-009: posting/paying/cancelling/reversing/resetting an
    // invoice is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice', 'accounting_post_journal']);

    $invoice = postedInvoiceRecord();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->callAction(ReverseAction::class, data: [
            'reason'     => 'Test reversal',
            'journal_id' => $invoice->journal_id,
            'date'       => now(),
        ]);

    expect(
        Move::query()
            ->where('reversed_entry_id', $invoice->id)
            ->where('move_type', MoveType::OUT_REFUND)
            ->exists()
    )->toBeTrue();
});

it('resets a posted invoice to draft through the action', function () {
    // accounting_post_journal is required alongside update_account_invoice
    // since DEF-009: posting/paying/cancelling/reversing/resetting an
    // invoice is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice', 'accounting_post_journal']);

    $invoice = postedInvoiceRecord();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->callAction(ResetToDraftAction::class);

    expect($invoice->refresh()->state)->toBe(MoveState::DRAFT);
});

it('marks a posted invoice as checked through the action', function () {
    // accounting_post_journal is required alongside update_account_invoice
    // since DEF-009: posting/paying/cancelling/reversing/resetting an
    // invoice is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice', 'accounting_post_journal']);

    $invoice = postedInvoiceRecord();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->callAction(SetAsCheckedAction::class);

    expect($invoice->refresh()->checked)->toBeTrue();
});

it('registers a full payment and marks the invoice paid through the action', function () {
    // accounting_post_journal is required alongside update_account_invoice
    // since DEF-009: posting/paying/cancelling/reversing/resetting an
    // invoice is no longer implied by edit access alone.
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice', 'accounting_post_journal']);

    AccountHelper::bankJournal();

    $invoice = postedInvoiceRecord();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->assertActionVisible(PayAction::class)
        ->callAction(PayAction::class)
        ->assertActionHidden(PayAction::class);

    expect($invoice->refresh()->payment_state)->toBe(PaymentState::PAID);
});

/**
 * Seeds the invoice list for the performance regressions in
 * docs/PERFORMANCE_BASELINE_PHASE1.md §2.5 (INV-1..INV-4, SEC-1).
 *
 * @return array{drafts: array<int, Move>, posted: array<int, Move>, other: array<int, Move>}
 */
function seedInvoiceListDataset(int $drafts = 30, int $posted = 30, int $otherCompany = 5): array
{
    $partner = AccountHelper::partner();
    $journal = AccountHelper::saleJournal();
    $otherCompanyId = Company::factory()->create()->id;

    $make = fn (array $overrides): Move => AccountHelper::invoice(MoveType::OUT_INVOICE, $partner, $journal, $overrides);

    return [
        'drafts' => collect(range(1, $drafts))->map(fn (int $i): Move => $make([]))->all(),
        'posted' => collect(range(1, $posted))->map(fn (int $i): Move => $make(['state' => MoveState::POSTED]))->all(),
        'other'  => collect(range(1, $otherCompany))->map(fn (int $i): Move => $make(['company_id' => $otherCompanyId]))->all(),
    ];
}

/**
 * @param  array<int, Move>  $moves
 * @return array<int, string>
 */
function invoiceKeys(array $moves): array
{
    return collect($moves)->map(fn (Move $move): string => (string) $move->id)->all();
}

it('renders the invoice list and select-all without hydrating every invoice', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'delete_account_invoice', 'delete_any_account_invoice']);

    $alreadySelectable = selectableMoveKeys(MoveType::OUT_INVOICE);
    $dataset = seedInvoiceListDataset();
    flushTerminatingCallbacks();

    $retrievedMoves = 0;

    Event::listen('eloquent.retrieved: *', function (string $event, array $payload) use (&$retrievedMoves): void {
        if (($payload[0] ?? null) instanceof Move) {
            $retrievedMoves++;
        }
    });

    $component = Livewire::test(ListInvoices::class)->assertOk();

    expect($retrievedMoves)->toBeLessThanOrEqual($component->instance()->getTableRecordsPerPage());

    $retrievedMoves = 0;

    $count = $component->instance()->getAllSelectableTableRecordsCount();
    $keys = $component->instance()->getAllSelectableTableRecordKeys();

    expect($retrievedMoves)->toBe(0)
        ->and($count)->toBe(count($alreadySelectable) + count($dataset['drafts']))
        ->and($keys)->toEqualCanonicalizing([...$alreadySelectable, ...invoiceKeys($dataset['drafts'])]);
});

it('keeps posted invoices unselectable and halts bulk delete on a posted invoice', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'delete_account_invoice', 'delete_any_account_invoice']);

    $draft = AccountHelper::invoice(MoveType::OUT_INVOICE);
    $posted = AccountHelper::invoice(MoveType::OUT_INVOICE, overrides: ['state' => MoveState::POSTED]);

    $component = Livewire::test(ListInvoices::class)->assertOk();

    $table = $component->instance()->getTable();

    expect($table->isRecordSelectable($posted))->toBeFalse()
        ->and($table->isRecordSelectable($draft))->toBeTrue()
        ->and($component->instance()->getAllSelectableTableRecordKeys())->not->toContain((string) $posted->id);

    $component->callTableBulkAction('delete', [$draft, $posted]);

    expect(Move::query()->whereKey([$draft->id, $posted->id])->count())->toBe(2);
});

it('selects no invoices for users who cannot delete them', function () {
    FilamentHelper::actingAs(['view_any_account_invoice']);

    AccountHelper::invoice(MoveType::OUT_INVOICE);

    $component = Livewire::test(ListInvoices::class)->assertOk();

    expect($component->instance()->getAllSelectableTableRecordsCount())->toBe(0)
        ->and($component->instance()->getAllSelectableTableRecordKeys())->toBe([]);
});

it('never lists or selects another company\'s invoices', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'delete_account_invoice', 'delete_any_account_invoice']);

    $dataset = seedInvoiceListDataset(drafts: 3, posted: 0, otherCompany: 5);

    $component = Livewire::test(ListInvoices::class)
        ->assertOk()
        ->assertCanSeeTableRecords($dataset['drafts'])
        ->assertCanNotSeeTableRecords($dataset['other']);

    expect(array_intersect($component->instance()->getAllSelectableTableRecordKeys(), invoiceKeys($dataset['other'])))->toBe([]);
});

it('lists invoices newest first by default and sorts by due date and amount', function () {
    FilamentHelper::actingAs(['view_any_account_invoice']);

    $partner = AccountHelper::partner();
    $journal = AccountHelper::saleJournal();

    $make = fn (int $dueInDays, float $total): Move => AccountHelper::invoice(MoveType::OUT_INVOICE, $partner, $journal, [
        'invoice_date_due'                => now()->addDays($dueInDays)->toDateString(),
        'amount_total_in_currency_signed' => $total,
    ]);

    $dueLast = $make(30, 100);
    $dueFirst = $make(1, 300);
    $dueMiddle = $make(10, 200);

    Livewire::test(ListInvoices::class)
        ->searchTable($partner->name)
        ->assertCanSeeTableRecords([$dueMiddle, $dueFirst, $dueLast], inOrder: true)
        ->sortTable('invoice_date_due')
        ->assertCanSeeTableRecords([$dueFirst, $dueMiddle, $dueLast], inOrder: true)
        ->sortTable('invoice_date_due', 'desc')
        ->assertCanSeeTableRecords([$dueLast, $dueMiddle, $dueFirst], inOrder: true)
        ->sortTable('amount_total_in_currency_signed', 'desc')
        ->assertCanSeeTableRecords([$dueFirst, $dueMiddle, $dueLast], inOrder: true);
});

it('eager-loads the partner in invoice global search instead of querying it per result', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'view_account_invoice', 'update_account_invoice']);

    $journal = AccountHelper::saleJournal();

    foreach (range(1, 5) as $i) {
        AccountHelper::invoice(MoveType::OUT_INVOICE, AccountHelper::partner(), $journal)
            ->forceFill(['name' => "PERFGS/{$i}"])
            ->saveQuietly();
    }

    InvoiceResource::getGlobalSearchResults('PERFGS');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $results = InvoiceResource::getGlobalSearchResults('PERFGS');

    $queries = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($results)->toHaveCount(5)
        ->and($queries)->toBeLessThanOrEqual(3);
});

it('memoises the permission scope schema lookup per table', function () {
    $user = FilamentHelper::actingAs(['view_any_account_invoice'], global: false);

    $user->forceFill(['resource_permission' => PermissionType::INDIVIDUAL])->saveQuietly();

    $resetCaches = function (): void {
        (new ReflectionProperty(Bouncer::class, 'authorizedUserIdsCache'))->setValue(null, null);
        (new ReflectionProperty(Move::class, 'permissionScopeColumnCache'))->setValue(null, []);
    };

    $resetCaches();

    $schemaQueries = 0;

    DB::listen(function ($query) use (&$schemaQueries): void {
        if (str_contains(strtolower($query->sql), 'information_schema')) {
            $schemaQueries++;
        }
    });

    $statements = collect(range(1, 3))
        ->map(fn (int $i): string => Move::query()->applyPermissionScope()->toSql())
        ->unique();

    $resetCaches();

    expect($statements)->toHaveCount(1)
        ->and($statements->first())->toContain('creator_id')
        ->and($schemaQueries)->toBeLessThanOrEqual(1);
});
