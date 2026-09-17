<?php

/**
 * DriveSyncExportTest only ever uses DocumentType::Other/::Receipt (both
 * fall to config/accounting_drive.php's 'default' template, no
 * {identifier} segment), so DriveFolderPathResolver's actual per-type
 * templates and identifierFor()/sanitize() logic had zero direct coverage
 * -- flagged by review, closed here with a unit-level test against the
 * resolver directly, no DocumentService/DriveSyncService/Drive API
 * involved.
 */

use Illuminate\Support\Facades\DB;
use Webkul\Account\Database\Factories\BankStatementFactory;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Models\Bill as AccountingBill;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\DocumentAttachment;
use Webkul\Accounting\Models\Invoice as AccountingInvoice;
use Webkul\Accounting\Models\JournalEntry;
use Webkul\Accounting\Support\DriveFolderPathResolver;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );
    Package::$plugins = Plugin::all()->keyBy('name');

    $this->resolver = new DriveFolderPathResolver;
    $this->company = Company::factory()->create(['is_active' => true]);
});

it('resolves the Invoice template and embeds the attached record\'s sanitized name as the identifier', function () {
    // Move's factory default name ('MISC/####/####') is genuinely
    // slash-bearing -- the realistic, common shape of an invoice number
    // -- which is exactly the value sanitize() exists to handle.
    $move = Move::factory()->create([
        'name'        => 'INV/2026/00042',
        'move_type'   => MoveType::OUT_INVOICE,
        'company_id'  => $this->company->id,
        'currency_id' => Currency::query()->firstOrFail()->id,
    ]);
    $invoice = AccountingInvoice::query()->findOrFail($move->id);

    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Invoice,
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $document->id,
        'attachable_type' => AccountingInvoice::class,
        'attachable_id'   => $invoice->id,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Accounting',
        'Invoices',
        'INV-2026-00042',
    ]);
});

it('resolves the Bill template with its own identifier, distinct from Invoice', function () {
    $move = Move::factory()->create([
        'name'        => 'BILL/2026/00017',
        'move_type'   => MoveType::IN_INVOICE,
        'company_id'  => $this->company->id,
        'currency_id' => Currency::query()->firstOrFail()->id,
    ]);
    $bill = AccountingBill::query()->findOrFail($move->id);

    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Bill,
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $document->id,
        'attachable_type' => AccountingBill::class,
        'attachable_id'   => $bill->id,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Accounting',
        'Bills',
        'BILL-2026-00017',
    ]);
});

it('resolves the Journal Entry template with its own identifier, distinct from Invoice and Bill', function () {
    // Found missing during the Google Drive integration inspection:
    // JournalEntryResource already supported attaching documents, but
    // DocumentType had no case of its own for it, so an attached
    // document always fell through to the generic 'default' template
    // ("Other Documents") instead of its own "Journal Entries" folder.
    $move = Move::factory()->create([
        'name'        => 'JE/2026/00042',
        'move_type'   => MoveType::ENTRY,
        'company_id'  => $this->company->id,
        'currency_id' => Currency::query()->firstOrFail()->id,
    ]);
    $entry = JournalEntry::query()->findOrFail($move->id);

    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::JournalEntry,
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $document->id,
        'attachable_type' => JournalEntry::class,
        'attachable_id'   => $entry->id,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Accounting',
        'Journal Entries',
        'JE-2026-00042',
    ]);
});

it('resolves the Payment Evidence template to its own "Payments" folder', function () {
    // Same gap as Journal Entry above, for the Customers/Vendors Payment
    // resources: PaymentEvidence already existed as a DocumentType, but
    // had no path template of its own. Unattached here (a fully valid
    // Payment fixture needs a journal/payment-method-line/outstanding &
    // destination accounts unrelated to what this test verifies) --
    // covers the template resolution itself, matching the existing
    // "falls back to document-{id}" case's own unattached pattern below.
    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::PaymentEvidence,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Accounting',
        'Payments',
        "document-{$document->id}",
    ]);
});

it('resolves the Bank Statement template using the statement\'s own name', function () {
    $statement = BankStatementFactory::new()->accountingModule([
        'company_id' => $this->company->id,
        'name'       => 'HBL Statement Aug/2026',
    ])->create();

    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::BankStatement,
    ]);
    DocumentAttachment::factory()->create([
        'company_id'      => $this->company->id,
        'document_id'     => $document->id,
        'attachable_type' => $statement::class,
        'attachable_id'   => $statement->id,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Accounting',
        'Bank Statements',
        'HBL Statement Aug-2026',
    ]);
});

it('falls back to the default template for a document type with no template entry', function () {
    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Other,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path)->toBe([
        'Aureus',
        "{$this->company->name} ({$this->company->id})",
        'Accounting',
        'Other Documents',
    ]);
});

it('falls back to "document-{id}" as the identifier when the document is not attached to anything', function () {
    $document = Document::factory()->create([
        'company_id'    => $this->company->id,
        'document_type' => DocumentType::Invoice,
    ]);

    $path = $this->resolver->resolve($document);

    expect(end($path))->toBe("document-{$document->id}");
});

it('sanitizes a slash-bearing company name so it can never introduce an extra folder level', function () {
    $company = Company::factory()->create(['is_active' => true, 'name' => 'Trade Debtors / Local']);

    $document = Document::factory()->create([
        'company_id'    => $company->id,
        'document_type' => DocumentType::Other,
    ]);

    $path = $this->resolver->resolve($document);

    expect($path[1])->toBe("Trade Debtors - Local ({$company->id})")
        ->and($path[1])->not->toContain('/');
});
