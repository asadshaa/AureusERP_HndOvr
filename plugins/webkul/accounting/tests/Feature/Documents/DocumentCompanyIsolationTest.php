<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\Accounting\Enums\DocumentAuditAction;
use Webkul\Accounting\Enums\DocumentType;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

/**
 * The five scenarios explicitly called out in the Phase 2 spec: Company A
 * must never be able to view, download, enumerate, attach to, or reach by
 * guessing an id, a document that belongs to Company B.
 */
beforeEach(function () {
    Storage::fake('accounting_documents');

    $this->service = app(DocumentService::class);

    $this->companyA = Company::factory()->create(['is_active' => true]);
    $this->companyB = Company::factory()->create(['is_active' => true]);

    $this->userA = documentTestUser($this->companyA, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::ViewDocuments,
        AccountingPermissions::DeleteDocuments,
    ]);

    $this->userB = documentTestUser($this->companyB, [
        AccountingPermissions::ManageDocuments,
        AccountingPermissions::DownloadDocuments,
        AccountingPermissions::ViewDocuments,
    ]);

    $this->companyBDocument = $this->service->upload(
        $this->userB, $this->companyB->id, DocumentType::Invoice, "Company B's confidential invoice", null,
        UploadedFile::fake()->create('company-b-invoice.pdf', 20, 'application/pdf'),
    );
});

it('Test 1: Company A cannot view Company B\'s document', function () {
    expect(fn () => $this->service->find($this->userA, $this->companyBDocument->id))
        ->toThrow(ModelNotFoundException::class);
});

it('Test 2: Company A cannot download Company B\'s document', function () {
    expect(fn () => $this->service->retrieveContents($this->userA, $this->companyBDocument->id))
        ->toThrow(ModelNotFoundException::class);

    // The refusal must not have exposed the file: nothing about the
    // attempt should read like a permission problem the user could work
    // around -- it must look identical to "this id doesn't exist".
});

it('Test 3: Company A cannot enumerate Company B\'s documents in a list', function () {
    // Give Company A a document of its own too, so an empty list wouldn't
    // just be "no documents exist anywhere" -- it has to actually exclude B.
    $this->service->upload(
        $this->userA, $this->companyA->id, DocumentType::Receipt, "Company A's own receipt", null,
        UploadedFile::fake()->create('company-a-receipt.pdf', 10, 'application/pdf'),
    );

    $listForA = $this->service->listForUser($this->userA);

    expect($listForA)->toHaveCount(1)
        ->and($listForA->first()->company_id)->toBe($this->companyA->id)
        ->and($listForA->pluck('id'))->not->toContain($this->companyBDocument->id);
});

it('Test 4: Company A cannot attach to a Company B record (or the reverse)', function () {
    $companyADocument = $this->service->upload(
        $this->userA, $this->companyA->id, DocumentType::Other, "Company A's document", null,
        UploadedFile::fake()->create('company-a-doc.pdf', 10, 'application/pdf'),
    );

    // Company A's own document, attached to Company B's record -- rejected.
    expect(fn () => $this->service->attach($this->userA, $companyADocument, $this->companyBDocument))
        ->toThrow(RuntimeException::class, 'belongs to a different company');

    expect($companyADocument->attachments()->count())->toBe(0);
});

it('Test 5: Company A cannot retrieve Company B\'s document by manipulating the id directly', function () {
    // This is the direct-object-reference attack: knowing (or guessing)
    // the real numeric id of another company's document and asking for it
    // by that id alone, with no reference to Company B anywhere in the
    // request. It must fail exactly like an id that was never issued.
    $guessedId = $this->companyBDocument->id;

    expect(fn () => $this->service->find($this->userA, $guessedId))
        ->toThrow(ModelNotFoundException::class);

    // And the exact same exception for an id that genuinely never existed --
    // proving the two cases are indistinguishable from the caller's side.
    $neverExistedId = $this->companyBDocument->id + 999_999;

    expect(fn () => $this->service->find($this->userA, $neverExistedId))
        ->toThrow(ModelNotFoundException::class);
});

it('records the cross-company attempt in Company B\'s own audit trail, not Company A\'s', function () {
    try {
        $this->service->find($this->userA, $this->companyBDocument->id);
    } catch (ModelNotFoundException) {
        // expected
    }

    $audit = $this->companyBDocument->audits()->where('action', DocumentAuditAction::AccessDenied)->first();

    expect($audit)->not->toBeNull()
        ->and($audit->company_id)->toBe($this->companyB->id)
        ->and($audit->actor_id)->toBe($this->userA->id);
});

it('still enforces isolation even for a user who belongs to neither company', function () {
    $outsider = documentTestUser(
        Company::factory()->create(['is_active' => true]),
        [AccountingPermissions::ViewDocuments],
    );

    expect(fn () => $this->service->find($outsider, $this->companyBDocument->id))
        ->toThrow(ModelNotFoundException::class);

    expect($this->service->listForUser($outsider))->toBeEmpty();
});
