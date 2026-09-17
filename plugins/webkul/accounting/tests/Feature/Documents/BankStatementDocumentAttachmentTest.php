<?php

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Database\Factories\BankStatementFactory;
use Webkul\Account\Models\BankStatement;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

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

    $this->statement = BankStatementFactory::new()
        ->accountingModule(['company_id' => $this->company->id])
        ->create();
});

it('gets a real, queryable documentAttachments relation on BankStatement', function () {
    expect($this->statement->documentAttachments())->toBeInstanceOf(MorphMany::class);
    expect($this->statement->documentAttachments)->toHaveCount(0);
});

it('attaches the original bank statement export as evidence, distinct from its recorded filename/hash', function () {
    // BankStatement already records original_filename/file_hash for dedup
    // detection, but never keeps a downloadable copy of the file itself --
    // this is the actual, retrievable evidence that fills that gap.
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::BankStatement, 'HBL statement export', null,
        fakeUploadedFileWithRealContent('hbl-statement.pdf', 'application/pdf'),
    );

    $this->service->attach($this->user, $document, $this->statement, note: 'Original export from HBL netbanking');

    expect($this->statement->documentAttachments()->count())->toBe(1);

    $result = $this->service->retrieveContents($this->user, $document->id);
    expect($result['document']->title)->toBe('HBL statement export');
});

it('refuses to attach a document to a bank statement belonging to a different company', function () {
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $otherStatement = BankStatementFactory::new()->accountingModule(['company_id' => $otherCompany->id])->create();

    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::BankStatement, 'Wrong-company statement', null,
        fakeUploadedFileWithRealContent('wrong.pdf', 'application/pdf'),
    );

    expect(fn () => $this->service->attach($this->user, $document, $otherStatement))
        ->toThrow(RuntimeException::class, 'belongs to a different company');

    expect($otherStatement->documentAttachments()->count())->toBe(0);
});

it('detaching from the bank statement leaves the document and its history intact', function () {
    $document = $this->service->upload(
        $this->user, $this->company->id, DocumentType::BankStatement, 'Detach test', null,
        fakeUploadedFileWithRealContent('detach.pdf', 'application/pdf'),
    );
    $attachment = $this->service->attach($this->user, $document, $this->statement);

    $this->service->detach($this->user, $attachment);

    expect($this->statement->documentAttachments()->count())->toBe(0)
        ->and(Document::query()->find($document->id))->not->toBeNull()
        ->and($document->audits()->count())->toBeGreaterThan(0);
});
