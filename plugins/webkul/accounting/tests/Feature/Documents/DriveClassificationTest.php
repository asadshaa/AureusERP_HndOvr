<?php

/**
 * Phase 2 of Drive -> Aureus ingestion: classification + review queue +
 * approval routing. Deliberately does NOT cover invoice creation or GL
 * posting -- those are later phases. See DriveClassificationService's
 * class doc for exactly what classify() does and does not attempt
 * (filename/metadata heuristics only, not real OCR).
 */

use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\DriveClassificationStatus;
use Webkul\Accounting\Enums\DriveIngestionStatus;
use Webkul\Accounting\Models\DriveIngestion;
use Webkul\Accounting\Models\FsTag;
use Webkul\Accounting\Services\Drive\DriveClassificationService;
use Webkul\Accounting\Services\FsTagService;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

function driveClassificationFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();

    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $company->enabledCurrencies()->syncWithoutDetaching([
        $currency->id => ['transaction_enabled' => true, 'reporting_enabled' => true],
    ]);

    $glAccount = Account::factory()->create([
        'code'         => 'EXP'.uniqid(),
        'name'         => 'Office Expense',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $currency->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $glAccount->companies()->attach($company->id);

    $fsTag = FsTag::query()->create([
        'company_id' => $company->id,
        'account_id' => $glAccount->id,
        'code'       => 'FS-001',
        'name'       => 'Office Expenses',
        'is_active'  => true,
    ]);

    $partner = Partner::factory()->create(['company_id' => $company->id, 'name' => 'Acme']);

    $workflow = ApprovalWorkflow::query()->create([
        'company_id'   => $company->id,
        'name'         => 'Drive ingestion classification approval',
        'request_type' => 'drive_ingestion_classification',
        'is_active'    => true,
    ]);
    $workflow->steps()->create([
        'sequence'           => 1,
        'name'               => 'Finance review',
        'approver_user_id'   => $user->id,
        'required_approvals' => 1,
    ]);

    return compact('company', 'user', 'currency', 'glAccount', 'fsTag', 'partner', 'workflow');
}

function makeRegisteredIngestion(Company $company, string $filename): DriveIngestion
{
    return DriveIngestion::query()->create([
        'company_id'      => $company->id,
        'drive_file_id'   => 'file-'.uniqid(),
        'checksum_sha256' => hash('sha256', $filename),
        'mime_type'       => 'application/pdf',
        'file_size'       => 100,
        'filename'        => $filename,
        'status'          => DriveIngestionStatus::Registered,
        'discovered_at'   => now(),
        'processed_at'    => now(),
    ]);
}

it('resolves a valid FS Tag, partner and account, reaching Valid with an approval request created', function () {
    $fx = driveClassificationFixture();

    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1001_Acme_500.00PKR_FS-001.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::Valid)
        ->and($classification->resolved_partner_id)->toBe($fx['partner']->id)
        ->and($classification->resolved_fs_tag_id)->toBe($fx['fsTag']->id)
        ->and($classification->resolved_account_id)->toBe($fx['glAccount']->id)
        ->and($classification->approval_request_id)->not->toBeNull();
});

it('marks NeedsReview with the exact diagnose() message for an unknown FS Tag code', function () {
    $fx = driveClassificationFixture();

    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1002_Acme_500.00PKR_FS-999.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    $expected = app(FsTagService::class)->diagnose($fx['company']->id, 'FS-999');

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and($classification->validation_issues)->toContain($expected)
        ->and($classification->approval_request_id)->toBeNull();
});

it('marks NeedsReview for an inactive FS Tag', function () {
    $fx = driveClassificationFixture();
    $fx['fsTag']->update(['is_active' => false]);

    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1003_Acme_500.00PKR_FS-001.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and($classification->approval_request_id)->toBeNull();
});

it('marks NeedsReview for an FS Tag that belongs to a different company', function () {
    $fx = driveClassificationFixture();
    $otherCompany = Company::factory()->create(['currency_id' => $fx['currency']->id, 'is_active' => true]);
    FsTag::query()->create([
        'company_id' => $otherCompany->id,
        'code'       => 'FS-777',
        'name'       => 'Other company tag',
        'is_active'  => true,
    ]);

    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1004_Acme_500.00PKR_FS-777.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and($classification->validation_issues[0])->toContain('different company');
});

it('never guesses an ambiguous partner match, marking NeedsReview', function () {
    $fx = driveClassificationFixture();
    Partner::factory()->create(['company_id' => $fx['company']->id, 'name' => 'Acme Two']);

    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1005_Acme_500.00PKR_FS-001.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and($classification->resolved_partner_id)->toBeNull()
        ->and(collect($classification->validation_issues)->contains(fn ($issue) => str_contains($issue, 'Multiple partners match')))->toBeTrue();
});

it('marks NeedsReview when zero partners match', function () {
    $fx = driveClassificationFixture();

    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1006_Zeta_500.00PKR_FS-001.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and($classification->resolved_partner_id)->toBeNull();
});

it('marks NeedsReview with a specific reason for a non-postable GL account behind the FS Tag', function () {
    $fx = driveClassificationFixture();
    $fx['glAccount']->update(['is_group' => true]);

    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1007_Acme_500.00PKR_FS-001.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and($classification->resolved_account_id)->toBeNull()
        ->and(collect($classification->validation_issues)->contains(fn ($issue) => str_contains($issue, 'unknown, inactive, non-postable')))->toBeTrue();
});

it('marks DuplicateSuspected and does not create an approval request for a matching existing invoice', function () {
    $fx = driveClassificationFixture();

    Move::factory()->invoice()->create([
        'company_id'   => $fx['company']->id,
        'currency_id'  => $fx['currency']->id,
        'partner_id'   => $fx['partner']->id,
        'reference'    => '1008',
        'amount_total' => 500.00,
    ]);

    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1008_Acme_500.00PKR_FS-001.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::DuplicateSuspected)
        ->and($classification->approval_request_id)->toBeNull();
});

it('reaches Valid, creates an ApprovalRequest and stores approval_request_id for a fully clean case', function () {
    $fx = driveClassificationFixture();

    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1009_Acme_750.50PKR_FS-001.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::Valid);

    $request = $classification->approvalRequest;
    expect($request)->not->toBeNull()
        ->and($request->request_type)->toBe('drive_ingestion_classification')
        ->and($request->subject_type)->toBe($classification->getMorphClass())
        ->and($request->subject_id)->toBe($classification->id);
});

it('marks NeedsReview when no date is extracted and the document currency differs from the company currency', function () {
    $fx = driveClassificationFixture();

    // Company currency is PKR (see driveClassificationFixture()); this
    // filename has an amount+currency segment (USD) but no YYYY-MM-DD
    // segment, so extracted_date stays null -- the exact failure scenario
    // that would otherwise let a foreign-currency document post using
    // today's exchange rate instead of its real historical rate.
    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1011_Acme_500.00USD_FS-001.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::NeedsReview)
        ->and($classification->extracted_date)->toBeNull()
        ->and($classification->approval_request_id)->toBeNull()
        ->and(collect($classification->validation_issues)->contains(fn ($issue) => str_contains($issue, 'No date could be extracted')))->toBeTrue();
});

it('still reaches Valid with no date when the document currency matches the company currency', function () {
    $fx = driveClassificationFixture();

    // Same scenario as above but the extracted currency (PKR) matches the
    // company currency, so the missing date carries no FX-rate risk
    // (Currency::getConversionRate() short-circuits to a rate of 1 for a
    // matching currency regardless of date) -- must not regress the
    // existing no-date-required behaviour for this case.
    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1012_Acme_500.00PKR_FS-001.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::Valid)
        ->and($classification->extracted_date)->toBeNull()
        ->and($classification->approval_request_id)->not->toBeNull();
});

it('includes extracted_date in the approval request context payload', function () {
    $fx = driveClassificationFixture();

    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1013_Acme_500.00PKR_2026-01-15_FS-001.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->validation_status)->toBe(DriveClassificationStatus::Valid);

    $request = $classification->approvalRequest;
    expect($request->context['extracted_date'])->toBe('2026-01-15');
});

it('never resolves a partner, FS Tag or account belonging to a different company', function () {
    $fx = driveClassificationFixture();
    $otherCompany = Company::factory()->create(['currency_id' => $fx['currency']->id, 'is_active' => true]);

    Partner::factory()->create(['company_id' => $otherCompany->id, 'name' => 'Acme']);
    $otherAccount = Account::factory()->create([
        'code'         => 'EXP'.uniqid(),
        'name'         => 'Other Company Expense',
        'account_type' => AccountType::EXPENSE,
        'currency_id'  => $fx['currency']->id,
        'is_group'     => false,
        'deprecated'   => false,
    ]);
    $otherAccount->companies()->attach($otherCompany->id);
    FsTag::query()->create([
        'company_id' => $otherCompany->id,
        'account_id' => $otherAccount->id,
        'code'       => 'FS-001',
        'name'       => 'Other company FS-001',
        'is_active'  => true,
    ]);

    // Company A has its own partner "Acme" and its own FS-001, so this
    // must resolve to Company A's own records only -- never Company B's,
    // even though both share the same partner name and FS Tag code.
    $ingestion = makeRegisteredIngestion($fx['company'], 'INV-1010_Acme_500.00PKR_FS-001.pdf');

    $classification = app(DriveClassificationService::class)->classify($ingestion);

    expect($classification->resolved_partner_id)->toBe($fx['partner']->id)
        ->and($classification->resolved_fs_tag_id)->toBe($fx['fsTag']->id)
        ->and($classification->resolved_account_id)->toBe($fx['glAccount']->id);
});
