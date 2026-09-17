<?php

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Models\JournalEntry;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

/**
 * Webkul\Accounting\Models\JournalEntry is another thin Move subclass, owned
 * entirely by the accounting plugin (unlike Bill/Invoice, no other plugin is
 * involved) -- see AccountingServiceProvider::registerDocumentAttachmentRelations().
 */
if (! function_exists('makeGeneralJournalEntry')) {
    function makeGeneralJournalEntry(Company $company): JournalEntry
    {
        $currency = Currency::query()->firstOrFail();

        // JournalFactory defaults to JournalType::GENERAL, matching what
        // JournalEntryResource's own journal_id field filters for.
        $journal = Journal::factory()->create([
            'company_id'  => $company->id,
            'currency_id' => $currency->id,
        ]);

        $move = Move::factory()->create([
            'move_type'   => MoveType::ENTRY,
            'company_id'  => $company->id,
            'currency_id' => $currency->id,
            'journal_id'  => $journal->id,
        ]);

        // Re-fetch through JournalEntry's own query so the returned
        // instance is a real JournalEntry, exactly as JournalEntryResource
        // would load it -- Move::factory() itself always returns a plain
        // Move instance.
        return JournalEntry::query()->findOrFail($move->id);
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

    Storage::fake('accounting_documents');

    $this->service = app(DocumentService::class);
    $this->company = Company::factory()->create(['is_active' => true]);
    $this->user = documentTestUser($this->company, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
    ]);
    $this->entry = makeGeneralJournalEntry($this->company);
});

it('gets a real, queryable documentAttachments relation on JournalEntry', function () {
    expect($this->entry->documentAttachments())->toBeInstanceOf(MorphMany::class);
    expect($this->entry->documentAttachments)->toHaveCount(0);
});

it('attaches an uploaded document to a real journal entry and finds it through its own relation', function () {
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Other, 'Manual adjustment justification', null,
        fakeUploadedFileWithRealContent('justification.pdf', 'application/pdf'),
    );

    $this->service->attach($this->user, $document, $this->entry, note: 'Year-end accrual correction');

    expect($this->entry->documentAttachments()->count())->toBe(1);

    $attachment = $this->entry->documentAttachments()->first();
    expect($attachment->document_id)->toBe($document->id)
        ->and($attachment->note)->toBe('Year-end accrual correction')
        ->and($attachment->company_id)->toBe($this->company->id);
});

it('refuses to attach a document to a journal entry belonging to a different company', function () {
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $otherEntry = makeGeneralJournalEntry($otherCompany);

    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Other, 'Wrong-company document', null,
        fakeUploadedFileWithRealContent('wrong.pdf', 'application/pdf'),
    );

    expect(fn () => $this->service->attach($this->user, $document, $otherEntry))
        ->toThrow(RuntimeException::class, 'belongs to a different company');

    expect($this->entry->documentAttachments()->count())->toBe(0)
        ->and(Document::query()->where('id', $document->id)->first()->attachments()->count())->toBe(0);
});

it('detaching from the journal entry leaves the document itself intact', function () {
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Other, 'Detach me', null,
        fakeUploadedFileWithRealContent('detach.pdf', 'application/pdf'),
    );
    $attachment = $this->service->attach($this->user, $document, $this->entry);

    $this->service->detach($this->user, $attachment);

    expect($this->entry->documentAttachments()->count())->toBe(0)
        ->and(Document::query()->find($document->id))->not->toBeNull();
});

it('refuses to detach a document from a posted journal entry, same as Phase 5 locking for invoices', function () {
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::Other, 'Supporting workpaper', null,
        fakeUploadedFileWithRealContent('workpaper.pdf', 'application/pdf'),
    );
    $attachment = $this->service->attach($this->user, $document, $this->entry);

    $this->entry->update(['state' => MoveState::POSTED]);

    expect(fn () => $this->service->detach($this->user, $attachment))
        ->toThrow(RuntimeException::class, 'posted record');

    expect($this->entry->documentAttachments()->count())->toBe(1);
});
