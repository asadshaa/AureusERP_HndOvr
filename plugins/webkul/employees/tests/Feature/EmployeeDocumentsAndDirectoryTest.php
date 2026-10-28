<?php

/**
 * Employee documents (HR Parity 1.6) and directory / Pakistan fields (1.7):
 * upload and download permissions (HR, the employee, other company, line
 * manager), signed links only, expiry badges, reminders once per threshold,
 * CNIC format and uniqueness per company, employee codes per company prefix,
 * and the Excel export's company and sensitive-column rules.
 */

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Resources\EmployeeDocumentResource;
use Webkul\Employee\Filament\Resources\EmployeeDocumentResource\Pages\ListEmployeeDocuments;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers\DocumentsRelationManager;
use Webkul\Employee\Models\DocumentType;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeDocument;
use Webkul\Employee\Services\DocumentExpiryReminderService;
use Webkul\Employee\Services\EmployeeCodeGenerator;
use Webkul\Employee\Services\EmployeeDirectoryExport;
use Webkul\Employee\Services\EmployeeDocumentService;
use Webkul\Employee\Support\Cnic;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function () {
    Storage::fake('local');
    config(['attendance.monthly_report.timezone' => 'Asia/Karachi']);
    test()->travelTo(CarbonImmutable::parse('2026-10-28 09:00', 'Asia/Karachi')->utc());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

function edCompany(string $name = 'Docs Co'): Company
{
    return Company::factory()->create(['name' => $name, 'is_active' => true]);
}

/** @param  array<int, string>  $permissions */
function edUser(Company $company, array $permissions = []): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

function edHr(Company $company, array $extra = []): User
{
    return edUser($company, array_merge([
        HrPermissions::ViewAllRecords,
        HrPermissions::ManageEmployeeDocuments,
        'view_any_employee_employee',
        'view_employee_employee',
    ], $extra));
}

function edEmployee(Company $company, string $name, ?User $user = null, array $attributes = []): Employee
{
    return Employee::query()->create($attributes + [
        'company_id' => $company->id,
        'name'       => $name,
        'is_active'  => true,
        'user_id'    => $user?->id,
    ]);
}

function edType(Company $company, string $code): DocumentType
{
    return DocumentType::query()->where('company_id', $company->id)->where('code', $code)->firstOrFail();
}

function edPdf(string $name = 'scan.pdf', int $padding = 200): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%".str_repeat('a', $padding)."\n%%EOF\n");
}

function edUpload(Employee $employee, User $by, string $code, array $data = []): EmployeeDocument
{
    return app(EmployeeDocumentService::class)->upload($employee, $by, $data + [
        'document_type_id' => edType($employee->company, $code)->id,
    ], edPdf());
}

// ---------------------------------------------------------------------
// Document types
// ---------------------------------------------------------------------
it('gives every new company the default document types', function () {
    $company = edCompany();

    $types = DocumentType::query()->where('company_id', $company->id)->get()->keyBy('code');

    expect($types)->toHaveCount(11)
        ->and($types['cnic']->has_expiry)->toBeTrue()
        ->and($types['cnic']->required_for_new_hires)->toBeTrue()
        ->and($types['medical_certificate']->visible_to_employee)->toBeFalse()
        ->and($types['employment_contract']->has_expiry)->toBeFalse();
});

// ---------------------------------------------------------------------
// Upload rules
// ---------------------------------------------------------------------
it('lets HR upload a checked PDF to the private disk and refuses other files', function () {
    $company = edCompany();
    $hr = edHr($company);
    $employee = edEmployee($company, 'Ayesha');

    $document = edUpload($employee, $hr, 'cnic', ['number' => '35202-1234567-1', 'expires_on' => '2030-01-01']);

    expect($document->status)->toBe(EmployeeDocument::STATUS_VERIFIED)
        ->and($document->mime_type)->toBe('application/pdf')
        ->and($document->file_path)->toStartWith('employees/documents/'.$employee->id.'/')
        ->and($document->expires_on->toDateString())->toBe('2030-01-01');
    Storage::disk('local')->assertExists($document->file_path);
    Storage::disk('public')->assertMissing($document->file_path);

    $service = app(EmployeeDocumentService::class);
    $type = edType($company, 'other');

    expect(fn () => $service->upload($employee, $hr, ['document_type_id' => $type->id], UploadedFile::fake()->createWithContent('notes.txt', 'plain text')))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->upload($employee, $hr, ['document_type_id' => $type->id], edPdf('big.pdf', 10 * 1024 * 1024 + 10)))
        ->toThrow(ValidationException::class);

    // A type without expiry never keeps an expiry date.
    $contract = edUpload($employee, $hr, 'employment_contract', ['expires_on' => '2030-01-01']);
    expect($contract->expires_on)->toBeNull();
});

it('lets the employee upload a visible type for HR to check, but not for colleagues or HR-only types', function () {
    $company = edCompany();
    $self = edUser($company, ['view_employee_employee']);
    $employee = edEmployee($company, 'Bilal', $self);
    $colleague = edEmployee($company, 'Colleague');

    $document = edUpload($employee, $self, 'educational_certificate');

    expect($document->status)->toBe(EmployeeDocument::STATUS_PENDING)
        ->and($document->verified_by)->toBeNull();

    expect(fn () => edUpload($employee, $self, 'medical_certificate'))->toThrow(AuthorizationException::class)
        ->and(fn () => edUpload($colleague, $self, 'educational_certificate'))->toThrow(AuthorizationException::class);

    // HR checks it; the employee cannot change it.
    $hr = edHr($company);
    app(EmployeeDocumentService::class)->verify($document, $hr);
    expect($document->fresh()->status)->toBe(EmployeeDocument::STATUS_VERIFIED)
        ->and(fn () => app(EmployeeDocumentService::class)->delete($document->fresh(), $self))->toThrow(AuthorizationException::class);
});

it('refuses HR of another company and a document type of another company', function () {
    $company = edCompany();
    $other = edCompany('Other Co');
    $otherHr = edHr($other);
    $employee = edEmployee($company, 'Cross');

    expect(fn () => edUpload($employee, $otherHr, 'cnic'))->toThrow(AuthorizationException::class);

    $hr = edHr($company);
    expect(fn () => app(EmployeeDocumentService::class)->upload($employee, $hr, ['document_type_id' => edType($other, 'cnic')->id], edPdf()))
        ->toThrow(ValidationException::class);
});

it('accepts only a fresh upload from the staging folder, never another stored file', function () {
    $company = edCompany();
    $hr = edHr($company);
    $employee = edEmployee($company, 'Path');
    $victim = edUpload(edEmployee($company, 'Victim'), $hr, 'cnic');
    $service = app(EmployeeDocumentService::class);
    $type = edType($company, 'other');

    expect(fn () => $service->upload($employee, $hr, ['document_type_id' => $type->id], $victim->file_path))->toThrow(ValidationException::class)
        ->and(fn () => $service->upload($employee, $hr, ['document_type_id' => $type->id], EmployeeDocument::INCOMING_DIRECTORY.'/../../../'.$victim->file_path))->toThrow(ValidationException::class);
    Storage::disk('local')->assertExists($victim->file_path);

    Storage::disk('local')->put(EmployeeDocument::INCOMING_DIRECTORY.'/fresh.pdf', "%PDF-1.4\n%abc\n%%EOF\n");
    $document = $service->upload($employee, $hr, ['document_type_id' => $type->id, 'original_name' => 'Fresh.pdf'], EmployeeDocument::INCOMING_DIRECTORY.'/fresh.pdf');

    expect($document->file_path)->toBe('employees/documents/'.$employee->id.'/fresh.pdf')
        ->and($document->original_name)->toBe('Fresh.pdf');
    Storage::disk('local')->assertMissing(EmployeeDocument::INCOMING_DIRECTORY.'/fresh.pdf');
});

it('replaces a file keeping the old one in the history and soft deletes', function () {
    $company = edCompany();
    $hr = edHr($company);
    $employee = edEmployee($company, 'Replace');
    $old = edUpload($employee, $hr, 'passport', ['number' => 'AB123', 'expires_on' => '2027-01-01']);

    $new = app(EmployeeDocumentService::class)->replace($old, $hr, edPdf('new.pdf'), ['expires_on' => '2032-01-01']);

    expect(EmployeeDocument::query()->find($old->id))->toBeNull()
        ->and(EmployeeDocument::withTrashed()->find($old->id)->replaced_by_id)->toBe($new->id)
        ->and($new->number)->toBe('AB123')
        ->and($new->expires_on->toDateString())->toBe('2032-01-01');
    Storage::disk('local')->assertExists($old->file_path);

    app(EmployeeDocumentService::class)->delete($new, $hr);
    expect(EmployeeDocument::query()->whereKey($new->id)->exists())->toBeFalse()
        ->and(EmployeeDocument::withTrashed()->whereKey($new->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------
// Downloads: signed link + permission
// ---------------------------------------------------------------------
it('downloads only through a signed link and only for HR or the employee for visible types', function () {
    $company = edCompany();
    $hr = edHr($company);
    $selfUser = edUser($company, ['view_employee_employee']);
    $managerUser = edUser($company, ['view_any_employee_employee', 'view_employee_employee']);
    $manager = edEmployee($company, 'Manager', $managerUser);
    $employee = edEmployee($company, 'Owner', $selfUser, ['parent_id' => $manager->id]);
    $otherHr = edHr(edCompany('Elsewhere'));

    $cnic = edUpload($employee, $hr, 'cnic');
    $medical = edUpload($employee, $hr, 'medical_certificate');

    $this->actingAs($hr)->get($cnic->temporaryDownloadUrl())->assertOk()->assertDownload($cnic->original_name);
    $this->actingAs($selfUser)->get($cnic->temporaryDownloadUrl())->assertOk();
    $this->actingAs($selfUser)->get($medical->temporaryDownloadUrl())->assertForbidden();
    $this->actingAs($managerUser)->get($cnic->temporaryDownloadUrl())->assertForbidden();
    $this->actingAs($otherHr)->get($cnic->temporaryDownloadUrl())->assertForbidden();

    // Unsigned, tampered or expired links are refused even for HR.
    $this->actingAs($hr)->get(route('employees.documents.download', ['document' => $cnic->id]))->assertForbidden();
    $this->actingAs($hr)->get(str_replace('/'.$cnic->id.'/', '/'.$medical->id.'/', $cnic->temporaryDownloadUrl()))->assertForbidden();
    $expired = URL::temporarySignedRoute('employees.documents.download', now()->subMinute(), ['document' => $cnic->id]);
    $this->actingAs($hr)->get($expired)->assertForbidden();

    // A deleted document is not served.
    app(EmployeeDocumentService::class)->delete($cnic, $hr);
    $this->actingAs($hr)->get($cnic->temporaryDownloadUrl())->assertForbidden();
});

it('sends a guest with a valid link to the login page', function () {
    $company = edCompany();
    $document = edUpload(edEmployee($company, 'Guest'), edHr($company), 'cnic');

    auth()->logout();
    $this->get($document->temporaryDownloadUrl())->assertRedirect();
});

// ---------------------------------------------------------------------
// Documents tab and HR list
// ---------------------------------------------------------------------
it('shows the Documents tab to HR and the employee but not to the line manager', function () {
    $company = edCompany();
    $hr = edHr($company);
    $selfUser = edUser($company, ['view_employee_employee']);
    $managerUser = edUser($company, ['view_any_employee_employee', 'view_employee_employee']);
    $manager = edEmployee($company, 'Manager', $managerUser);
    $employee = edEmployee($company, 'Tab Owner', $selfUser, ['parent_id' => $manager->id]);

    $this->actingAs($hr);
    expect(DocumentsRelationManager::canViewForRecord($employee, ViewEmployee::class))->toBeTrue();
    $this->actingAs($selfUser);
    expect(DocumentsRelationManager::canViewForRecord($employee, ViewEmployee::class))->toBeTrue();
    $this->actingAs($managerUser);
    expect(DocumentsRelationManager::canViewForRecord($employee, ViewEmployee::class))->toBeFalse();
});

it('lets the employee upload from the Documents tab and lists only their visible documents', function () {
    $company = edCompany();
    $hr = edHr($company);
    $selfUser = edUser($company, ['view_employee_employee']);
    $employee = edEmployee($company, 'Self Service', $selfUser);
    $medical = edUpload($employee, $hr, 'medical_certificate');
    $cnic = edUpload($employee, $hr, 'cnic');

    $this->actingAs($selfUser);

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => ViewEmployee::class])
        ->assertCanSeeTableRecords([$cnic])
        ->assertCanNotSeeTableRecords([$medical])
        ->assertTableActionHidden('delete', $cnic)
        ->callTableAction('upload', data: [
            'document_type_id' => edType($company, 'experience_letter')->id,
            'title'            => 'Previous employer',
            'file_path'        => edPdf('letter.pdf'),
        ])
        ->assertHasNoTableActionErrors();

    $uploaded = EmployeeDocument::query()->where('employee_id', $employee->id)->where('title', 'Previous employer')->firstOrFail();
    expect($uploaded->status)->toBe(EmployeeDocument::STATUS_PENDING)
        ->and($uploaded->file_path)->toStartWith('employees/documents/'.$employee->id.'/');
});

it('lists documents for HR company-scoped with expiry filters and counts missing required documents', function () {
    $company = edCompany();
    $other = edCompany('Hidden Co');
    $hr = edHr($company);
    $employee = edEmployee($company, 'Listed');
    $expired = edUpload($employee, $hr, 'passport', ['expires_on' => '2026-10-01']);
    $expiring = edUpload($employee, $hr, 'driving_licence', ['expires_on' => '2026-11-10']);
    $valid = edUpload($employee, $hr, 'cnic', ['expires_on' => '2031-01-01']);
    $foreign = edUpload(edEmployee($other, 'Foreign'), edHr($other), 'cnic');

    $this->actingAs($hr);

    Livewire::test(ListEmployeeDocuments::class)
        ->assertCanSeeTableRecords([$expired, $expiring, $valid])
        ->assertCanNotSeeTableRecords([$foreign])
        ->filterTable('expired', true)
        ->assertCanSeeTableRecords([$expired])
        ->assertCanNotSeeTableRecords([$expiring, $valid])
        ->resetTableFilters()
        ->filterTable('expiring', true)
        ->assertCanSeeTableRecords([$expiring])
        ->assertCanNotSeeTableRecords([$expired, $valid]);

    // CNIC is there; contract and offer letter (required by default) are missing.
    $missing = $employee->missingRequiredDocumentTypes()->pluck('code')->all();
    expect($missing)->toEqualCanonicalizing(['employment_contract', 'offer_letter'])
        ->and(Employee::query()->whereKey($employee->id)->withMissingRequiredDocumentsCount()->first()->missing_required_documents_count)->toBe(2)
        ->and(Employee::query()->whereKey($employee->id)->missingRequiredDocuments()->exists())->toBeTrue();

    edUpload($employee, $hr, 'employment_contract');
    edUpload($employee, $hr, 'offer_letter');
    expect(Employee::query()->whereKey($employee->id)->missingRequiredDocuments()->exists())->toBeFalse();

    // A line manager or plain employee cannot open the HR list.
    $this->actingAs(edUser($company, ['view_employee_employee']));
    expect(EmployeeDocumentResource::canViewAny())->toBeFalse();
});

// ---------------------------------------------------------------------
// Expiry badges and reminders
// ---------------------------------------------------------------------
it('shows expired red, 30 days or less amber and later green', function () {
    $today = CarbonImmutable::parse('2026-10-28', 'Asia/Karachi');
    $document = fn (?string $date): EmployeeDocument => new EmployeeDocument(['expires_on' => $date]);

    expect($document('2026-10-27')->expiryState($today))->toBe('expired')
        ->and($document('2026-10-28')->expiryState($today))->toBe('expiring')
        ->and($document('2026-11-27')->expiryState($today))->toBe('expiring')
        ->and($document('2026-11-28')->expiryState($today))->toBe('valid')
        ->and($document(null)->expiryState($today))->toBeNull()
        ->and(EmployeeDocument::expiryColor('expired'))->toBe('danger')
        ->and(EmployeeDocument::expiryColor('expiring'))->toBe('warning')
        ->and(EmployeeDocument::expiryColor('valid'))->toBe('success')
        ->and($document('2026-11-04')->expiryLabel($today))->toBe('Expires in 7 days');
});

it('reminds HR and the employee once at 30 days, 7 days and on expiry', function () {
    $company = edCompany();
    $hr = edHr($company);
    $selfUser = edUser($company, ['view_employee_employee']);
    $employee = edEmployee($company, 'Expiring', $selfUser);
    $passport = edUpload($employee, $hr, 'passport', ['expires_on' => '2026-11-27']);
    $medical = edUpload($employee, $hr, 'medical_certificate', ['expires_on' => '2026-11-27']);
    $farAway = edUpload($employee, $hr, 'cnic', ['expires_on' => '2028-01-01']);
    $reminders = app(DocumentExpiryReminderService::class);
    $titles = fn (User $user): array => $user->fresh()->notifications()->get()->pluck('data.title')->all();

    // 30 days before: HR for both, the employee only for the visible passport.
    $first = $reminders->send($company->id, '2026-10-28');
    expect($first['documents'])->toBe(2)
        ->and($titles($hr))->toEqualCanonicalizing(['Passport of Expiring expires in 30 days', 'Medical certificate of Expiring expires in 30 days'])
        ->and($titles($selfUser))->toBe(['Your Passport expires in 30 days']);

    // Re-run the same day (and the command): nothing new.
    expect($reminders->send($company->id, '2026-10-28')['notified'])->toBe(0);
    Artisan::call('hr:document-expiry-reminders', ['--company' => $company->id, '--date' => '2026-10-28']);
    expect($titles($selfUser))->toHaveCount(1);

    // Days in between: nothing.
    expect($reminders->send($company->id, '2026-11-05')['notified'])->toBe(0);

    // 7 days before, then on expiry, then the day after (already sent).
    $reminders->send($company->id, '2026-11-20');
    $reminders->send($company->id, '2026-11-20');
    $reminders->send($company->id, '2026-11-27');
    $reminders->send($company->id, '2026-11-28');

    expect($titles($selfUser))->toEqualCanonicalizing([
        'Your Passport expires in 30 days',
        'Your Passport expires in 7 days',
        'Your Passport expires today',
    ])->and($titles($hr))->toHaveCount(6)
        ->and($passport->fresh()->expiry_reminders)->toHaveKeys(['30', '7', '0'])
        ->and($farAway->fresh()->expiry_reminders)->toBeNull();

    // A renewed expiry date starts the reminders again.
    $passport->fresh()->update(['expires_on' => '2026-12-15']);
    expect($passport->fresh()->expiry_reminders)->toBeNull();
});

it('does not remind another company or employees who left', function () {
    $company = edCompany();
    $other = edCompany('Quiet Co');
    $hr = edHr($company);
    $otherHr = edHr($other);
    $left = edEmployee($company, 'Left', null, ['is_active' => false]);
    edUpload($left, $hr, 'passport', ['expires_on' => '2026-11-27']);
    edUpload(edEmployee($company, 'Stays'), $hr, 'passport', ['expires_on' => '2026-11-27']);

    app(DocumentExpiryReminderService::class)->send(null, '2026-10-28');

    expect($hr->fresh()->notifications()->get()->pluck('data.title')->all())->toBe(['Passport of Stays expires in 30 days'])
        ->and($otherHr->fresh()->notifications()->count())->toBe(0);
});

// ---------------------------------------------------------------------
// CNIC
// ---------------------------------------------------------------------
it('formats CNIC, refuses invalid ones and keeps it unique per company', function () {
    $company = edCompany();
    $other = edCompany('Second Co');

    $employee = edEmployee($company, 'Cnic One', null, ['cnic' => '3520212345671']);
    expect($employee->fresh()->cnic)->toBe('35202-1234567-1')
        ->and(Cnic::normalize('35202 1234567 1'))->toBe('35202-1234567-1')
        ->and(Cnic::isValid('35202-1234567'))->toBeFalse()
        ->and(Cnic::isValid('ABCDE-1234567-1'))->toBeFalse();

    expect(fn () => edEmployee($company, 'Bad', null, ['cnic' => '12345']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => edEmployee($company, 'Duplicate', null, ['cnic' => '35202-1234567-1']))->toThrow(InvalidArgumentException::class);

    // Same person in another company is allowed.
    expect(edEmployee($other, 'Other Co Copy', null, ['cnic' => '35202-1234567-1'])->cnic)->toBe('35202-1234567-1');

    // The form rule says the same.
    $errors = [];
    $rule = EmployeeResource::cnicRule(null);
    $this->actingAs(edHr($company));
    $rule('cnic', '35202-1234567-1', function (string $message) use (&$errors): void {
        $errors[] = $message;
    });
    $rule('cnic', '123', function (string $message) use (&$errors): void {
        $errors[] = $message;
    });
    $rule('cnic', '35202-7654321-1', function (string $message) use (&$errors): void {
        $errors[] = $message;
    });
    expect($errors)->toBe(['Another employee of this company already has this CNIC.', 'CNIC must be 13 digits, e.g. 35202-1234567-1.']);
});

it('finds employees by code always and by CNIC only with sensitive access', function () {
    $company = edCompany();
    $employee = edEmployee($company, 'Searchable', null, ['employee_number' => 'SRC-0042', 'cnic' => '42101-1111111-1']);
    edEmployee($company, 'Somebody Else');

    $this->actingAs(edHr($company));
    expect(EmployeeResource::applyDirectorySearch(Employee::query()->where('company_id', $company->id), 'SRC-0042')->pluck('id')->all())->toBe([$employee->id])
        ->and(EmployeeResource::applyDirectorySearch(Employee::query()->where('company_id', $company->id), '4210111111111')->count())->toBe(0);

    $this->actingAs(edHr($company, [HrPermissions::ViewSensitiveEmployeeData]));
    expect(EmployeeResource::applyDirectorySearch(Employee::query()->where('company_id', $company->id), '4210111111111')->pluck('id')->all())->toBe([$employee->id]);
});

// ---------------------------------------------------------------------
// Employee codes
// ---------------------------------------------------------------------
it('numbers new employees per company prefix and never changes a set code', function () {
    $truck = edCompany('Truck Ink Inn (Pvt) Ltd');
    $ship = edCompany('ShipLane');
    $codes = app(EmployeeCodeGenerator::class);

    expect($codes->defaultPrefix($truck->id))->toBe('TII')
        ->and($codes->defaultPrefix($ship->id))->toBe('SL');

    $codes->sequenceFor($ship->id)->update(['prefix' => 'SHL']);

    $first = edEmployee($truck, 'First');
    $second = edEmployee($truck, 'Second');
    $manual = edEmployee($truck, 'Manual', null, ['employee_number' => 'TII-0003']);
    $third = edEmployee($truck, 'Third');
    $shipFirst = edEmployee($ship, 'Ship First');

    expect($first->employee_number)->toBe('TII-0001')
        ->and($second->employee_number)->toBe('TII-0002')
        ->and($manual->employee_number)->toBe('TII-0003')
        ->and($third->employee_number)->toBe('TII-0004')
        ->and($shipFirst->employee_number)->toBe('SHL-0001');

    $first->update(['name' => 'First Renamed']);
    expect($first->fresh()->employee_number)->toBe('TII-0001');
});

it('backfills only empty employee codes', function () {
    $company = edCompany('Open Harbour Testing');
    $codes = app(EmployeeCodeGenerator::class);
    $codes->sequenceFor($company->id)->update(['prefix' => 'OPT']);

    $kept = edEmployee($company, 'Kept', null, ['employee_number' => 'LEGACY-7']);
    $empty = edEmployee($company, 'Empty');
    Employee::query()->whereKey($empty->id)->update(['employee_number' => null]);
    $blank = edEmployee($company, 'Blank');
    Employee::query()->whereKey($blank->id)->update(['employee_number' => '']);

    $preview = $codes->backfill($company->id, dryRun: true);
    expect(collect($preview)->pluck('code')->all())->toBe(['OPT-0003', 'OPT-0004'])
        ->and($empty->fresh()->employee_number)->toBeNull();

    Artisan::call('hr:backfill-employee-codes', ['--company' => $company->id]);

    expect($kept->fresh()->employee_number)->toBe('LEGACY-7')
        ->and($empty->fresh()->employee_number)->toBe('OPT-0003')
        ->and($blank->fresh()->employee_number)->toBe('OPT-0004');

    expect($codes->backfill($company->id))->toBe([]);
});

// ---------------------------------------------------------------------
// Excel export
// ---------------------------------------------------------------------
it('exports only the user\'s company and sensitive columns only with permission', function () {
    $company = edCompany();
    $other = edCompany('Not Mine');
    edEmployee($company, 'Exported', null, ['cnic' => '61101-2222222-2', 'eobi_number' => 'EOBI-1']);
    edEmployee($other, 'Not Exported', null, ['cnic' => '61101-3333333-3']);
    $export = app(EmployeeDirectoryExport::class);

    $plain = edHr($company);
    $rows = $export->rows($plain, Employee::query(), ['name', 'eobi_number', 'cnic', 'base_salary']);
    expect($rows[0])->toBe(['Name', 'EOBI no.'])
        ->and(collect($rows)->slice(1)->pluck(0)->all())->toContain('Exported')->not->toContain('Not Exported')
        ->and(array_keys($export->columnOptions($plain)))->not->toContain('cnic');

    $sensitive = edHr($company, [HrPermissions::ViewSensitiveEmployeeData]);
    $rows = $export->rows($sensitive, Employee::query()->where('company_id', $company->id), ['name', 'cnic']);
    expect($rows[0])->toBe(['Name', 'CNIC'])
        ->and($rows[1])->toBe(['Exported', '61101-2222222-2']);

    // A line manager only exports their own team.
    $managerUser = edUser($company, ['view_any_employee_employee', 'view_employee_employee']);
    $manager = edEmployee($company, 'Team Lead', $managerUser);
    edEmployee($company, 'Team Member', null, ['parent_id' => $manager->id]);
    $names = collect($export->rows($managerUser, Employee::query(), ['name']))->slice(1)->pluck(0)->all();
    expect($names)->toEqualCanonicalizing(['Team Lead', 'Team Member']);
});

it('renders the employee page with the new fields and filters the list by missing documents', function () {
    $company = edCompany();
    $hr = edHr($company, [HrPermissions::ViewSensitiveEmployeeData]);
    $missing = edEmployee($company, 'Missing Docs', null, ['cnic' => '35201-5555555-5', 'blood_group' => 'O+', 'probation_end_date' => '2027-01-31']);
    $complete = edEmployee($company, 'Complete Docs');
    foreach (['cnic', 'employment_contract', 'offer_letter'] as $code) {
        edUpload($complete, $hr, $code);
    }

    $this->actingAs($hr);

    Livewire::test(ViewEmployee::class, ['record' => $missing->getRouteKey()])
        ->assertOk()
        ->assertSee('35201-5555555-5')
        ->assertSee('O+')
        ->assertSee('Missing required documents');

    Livewire::test(ListEmployees::class)
        ->filterTable('missing_required_documents', true)
        ->assertCanSeeTableRecords([$missing])
        ->assertCanNotSeeTableRecords([$complete]);
});

it('downloads the Excel export from the employee list', function () {
    $company = edCompany();
    edEmployee($company, 'In The Sheet');
    $this->actingAs(edHr($company));

    Livewire::test(ListEmployees::class)
        ->callAction('exportExcel', data: ['columns' => ['employee_number', 'name']])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded();
});
