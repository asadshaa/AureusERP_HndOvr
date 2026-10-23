<?php

/**
 * Regression tests for the Recruitment Stage 1 security fixes
 * (docs/RECRUITMENT_PLUGIN_STATUS_AND_INTEGRATION_ROADMAP.md §8, tasks 1.1-1.4):
 *
 *  1. Only users with hr_convert_candidates (hiring manager / HR) can turn a
 *     candidate into an employee -- on every entry point, enforced in
 *     CandidateConversionService.
 *  2. CVs are stored on the private (non web-served) disk, size-limited, and
 *     handed out only through the permission-checked "Download CV" action.
 *  3. An application can only link its own company's candidate, job and
 *     department; form selects only offer own-company records.
 *  4. Shared (company-less) pipeline stages can only be changed by an admin.
 */

use Database\Seeders\HrRoleSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource\Pages\CreateCandidate;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\StageResource\Pages\ListStages;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\Stage;
use Webkul\Recruitment\Services\CandidateConversionService;
use Webkul\Recruitment\Support\RecruitmentCompanyScope;
use Webkul\Recruitment\Traits\HandlesEmployeeConversion;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

/** A user in $company holding the named seeded HR role (recruiter / hiring_manager). */
function securityRoleUser(Company $company, string $roleName): User
{
    app(HrRoleSeeder::class)->run();
    $role = Role::query()->whereRaw('LOWER(name) = ?', [$roleName])->firstOrFail();

    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    $user->assignRole($role);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

function securityAdmin(Company $company): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    $user->assignRole(Role::query()->firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user->refresh();
}

/** @return array{company: Company, application: Applicant, candidate: Candidate} */
function securityHireableApplication(): array
{
    // Fresh acting user (same reason as recruitmentFixture()): a previous test's
    // rolled-back user must not be stamped as creator_id on the new records.
    Auth::login(User::factory()->create(['is_active' => true]));
    $company = Company::factory()->create(['is_active' => true]);
    $department = Department::factory()->create(['company_id' => $company->id, 'manager_id' => null]);
    $job = JobPosition::query()->create(['company_id' => $company->id, 'department_id' => $department->id, 'name' => 'Dispatcher', 'is_active' => true]);
    $partner = Partner::query()->create(['name' => 'Hire Me', 'sub_type' => 'individual', 'company_id' => $company->id]);
    $candidate = Candidate::query()->create(['company_id' => $company->id, 'name' => 'Hire Me', 'email_from' => 'hire.me@example.test', 'partner_id' => $partner->id]);
    $application = Applicant::query()->create(['company_id' => $company->id, 'candidate_id' => $candidate->id, 'job_id' => $job->id, 'department_id' => $department->id, 'create_date' => now()]);

    return compact('company', 'application', 'candidate');
}

// ---------------------------------------------------------------------
// 1. Conversion permission
// ---------------------------------------------------------------------

it('refuses to convert a candidate for a recruiter, and creates no employee', function () {
    $f = securityHireableApplication();
    $this->actingAs(securityRoleUser($f['company'], 'recruiter'));

    expect(fn () => app(CandidateConversionService::class)->convert($f['application']))
        ->toThrow(AuthorizationException::class, 'not allowed to convert');
    expect(fn () => $f['application']->createEmployee())->toThrow(AuthorizationException::class);
    expect(fn () => $f['candidate']->createEmployee())->toThrow(AuthorizationException::class);
    expect(Employee::query()->where('name', 'Hire Me')->exists())->toBeFalse();
});

it('converts for a hiring manager', function () {
    $f = securityHireableApplication();
    $this->actingAs(securityRoleUser($f['company'], 'hiring_manager'));

    $employee = app(CandidateConversionService::class)->convert($f['application']);

    expect($employee)->toBeInstanceOf(Employee::class)
        ->and($f['candidate']->fresh()->employee_id)->toBe($employee->id);
});

it('still lets anyone who can see an already-converted candidate open their employee', function () {
    $f = securityHireableApplication();
    $this->actingAs(securityRoleUser($f['company'], 'hiring_manager'));
    $employee = app(CandidateConversionService::class)->convert($f['application']);

    $this->actingAs(securityRoleUser($f['company'], 'recruiter'));

    expect(app(CandidateConversionService::class)->convert($f['application']->fresh())->id)->toBe($employee->id);
});

// Note: Livewire::test() of the candidate/applicant record pages crashes PHP in
// this environment (pre-existing, also documented in TestRecruitmentScenarioTest),
// so the page buttons are exercised through the shared trait they all use.
function securityConversionPage(): object
{
    return new class
    {
        use HandlesEmployeeConversion;

        public function canConvert(): bool
        {
            return self::userCanConvert();
        }

        public function run(callable $convert)
        {
            return $this->convertAndRedirect($convert, 'view');
        }
    };
}

it('shows the page "Create employee" buttons only to users allowed to convert', function () {
    $f = securityHireableApplication();

    $this->actingAs(securityRoleUser($f['company'], 'recruiter'));
    expect(securityConversionPage()->canConvert())->toBeFalse();

    $this->actingAs(securityRoleUser($f['company'], 'hiring_manager'));
    expect(securityConversionPage()->canConvert())->toBeTrue();
});

it('turns a refused conversion from a page button into a message, not an error', function () {
    $f = securityHireableApplication();
    $this->actingAs(securityRoleUser($f['company'], 'recruiter'));

    $result = securityConversionPage()->run(fn () => $f['application']->createEmployee());

    expect($result)->toBeNull();
    expect(Employee::query()->where('name', 'Hire Me')->exists())->toBeFalse();
    Notification::assertNotified('Could not create the employee');
});

// ---------------------------------------------------------------------
// 2. CV storage
// ---------------------------------------------------------------------

it('stores an uploaded CV on the private disk, never the public one', function () {
    Storage::fake('local');
    Storage::fake('public');
    $company = Company::factory()->create(['is_active' => true]);
    $this->actingAs(securityRoleUser($company, 'recruiter'));

    Livewire::test(CreateCandidate::class)
        ->fillForm([
            'name'        => 'Private CV',
            'email_from'  => 'private.cv@example.test',
            'resume_path' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $path = Candidate::query()->where('email_from', 'private.cv@example.test')->value('resume_path');
    expect($path)->toStartWith('recruitment/cv/');
    Storage::disk('local')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
    expect(CandidateResource::CV_DISK)->toBe('local')
        ->and(config('filesystems.disks.local.url'))->toBeNull();
});

it('rejects a CV larger than the limit', function () {
    Storage::fake('local');
    $company = Company::factory()->create(['is_active' => true]);
    $this->actingAs(securityRoleUser($company, 'recruiter'));

    Livewire::test(CreateCandidate::class)
        ->fillForm([
            'name'        => 'Huge CV',
            'resume_path' => UploadedFile::fake()->create('cv.pdf', CandidateResource::CV_MAX_KB + 100, 'application/pdf'),
        ])
        ->call('create')
        ->assertHasFormErrors(['resume_path']);

    expect(Candidate::query()->where('name', 'Huge CV')->exists())->toBeFalse();
});

it('downloads a CV only through the permission-checked action', function () {
    Storage::fake('local');
    $company = Company::factory()->create(['is_active' => true]);
    $recruiter = securityRoleUser($company, 'recruiter');
    $this->actingAs($recruiter);
    Storage::disk('local')->put('recruitment/cv/abc.pdf', '%PDF-1.4 test');
    $candidate = Candidate::query()->create(['company_id' => $company->id, 'name' => 'Ada Lovelace', 'resume_path' => 'recruitment/cv/abc.pdf']);

    $action = CandidateResource::downloadCvAction()->record($candidate);
    expect($action->isVisible())->toBeTrue();

    $response = $action->call();
    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('content-disposition'))->toContain('ada-lovelace-cv.pdf');

    $withoutCv = Candidate::query()->create(['company_id' => $company->id, 'name' => 'No CV']);
    expect(CandidateResource::downloadCvAction()->record($withoutCv)->isVisible())->toBeFalse();

    // Someone without candidate view permission gets neither the button nor the file.
    $this->actingAs(User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]));
    $denied = CandidateResource::downloadCvAction()->record($candidate);
    expect($denied->isVisible())->toBeFalse();
    expect(fn () => $denied->call())->toThrow(HttpException::class);
});

// ---------------------------------------------------------------------
// 3. Company scoping
// ---------------------------------------------------------------------

it('refuses to link an application to another company\'s candidate, job or department', function () {
    $f = securityHireableApplication();
    $other = Company::factory()->create(['is_active' => true]);
    $otherCandidate = Candidate::query()->create(['company_id' => $other->id, 'name' => 'Elsewhere']);
    $otherJob = JobPosition::query()->create(['company_id' => $other->id, 'name' => 'Elsewhere Job', 'is_active' => true]);
    $otherDepartment = Department::factory()->create(['company_id' => $other->id, 'manager_id' => null]);

    expect(fn () => Applicant::query()->create(['company_id' => $f['company']->id, 'candidate_id' => $otherCandidate->id, 'create_date' => now()]))
        ->toThrow(InvalidArgumentException::class, 'candidate belongs to a different company');
    expect(fn () => $f['application']->update(['job_id' => $otherJob->id]))
        ->toThrow(InvalidArgumentException::class, 'job belongs to a different company');
    expect(fn () => $f['application']->fresh()->update(['department_id' => $otherDepartment->id]))
        ->toThrow(InvalidArgumentException::class, 'department belongs to a different company');
});

it('still accepts shared (company-less) jobs and never blocks unrelated edits of old rows', function () {
    $f = securityHireableApplication();
    $sharedJob = JobPosition::query()->create(['company_id' => null, 'name' => 'Shared Role', 'is_active' => true]);

    $f['application']->update(['job_id' => $sharedJob->id]);
    expect($f['application']->fresh()->job_id)->toBe($sharedJob->id);

    // A legacy row that already points at a foreign department can still be edited otherwise.
    $other = Company::factory()->create(['is_active' => true]);
    $otherDepartment = Department::factory()->create(['company_id' => $other->id, 'manager_id' => null]);
    DB::table('recruitments_applicants')->where('id', $f['application']->id)->update(['department_id' => $otherDepartment->id]);
    $f['application']->fresh()->update(['applicant_notes' => 'Called back on Monday']);

    expect($f['application']->fresh()->applicant_notes)->toBe('Called back on Monday');
});

it('offers only own-company candidates, jobs and users in recruitment selects', function () {
    $mine = Company::factory()->create(['is_active' => true]);
    $other = Company::factory()->create(['is_active' => true]);
    $me = securityRoleUser($mine, 'recruiter');
    $this->actingAs($me);

    $myCandidate = Candidate::query()->create(['company_id' => $mine->id, 'name' => 'Mine']);
    $theirCandidate = Candidate::query()->create(['company_id' => $other->id, 'name' => 'Theirs']);
    $colleague = User::factory()->create(['default_company_id' => $mine->id]);
    $stranger = User::factory()->create(['default_company_id' => $other->id]);

    $candidateIds = RecruitmentCompanyScope::ownCompany(Candidate::query())->pluck('id');
    $userIds = RecruitmentCompanyScope::companyUsers(User::query())->pluck('id');

    expect($candidateIds)->toContain($myCandidate->id)->not->toContain($theirCandidate->id)
        ->and($userIds)->toContain($colleague->id, $me->id)->not->toContain($stranger->id);
});

// ---------------------------------------------------------------------
// 4. Shared pipeline stages
// ---------------------------------------------------------------------

it('lets only an administrator change or delete a shared stage', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $recruiter = securityRoleUser($company, 'recruiter');
    $shared = Stage::query()->create(['name' => 'Shared Screening', 'company_id' => null]);
    $own = Stage::query()->create(['name' => 'Our Screening', 'company_id' => $company->id]);

    $this->actingAs($recruiter);
    expect($recruiter->can('update', $shared))->toBeFalse()
        ->and($recruiter->can('update', $own))->toBeTrue();
    expect(fn () => $shared->update(['name' => 'Renamed']))->toThrow(AuthorizationException::class);
    expect(fn () => Stage::query()->findOrFail($shared->id)->delete())->toThrow(AuthorizationException::class);
    expect(fn () => Stage::query()->findOrFail($own->id)->update(['company_id' => null]))->toThrow(AuthorizationException::class);

    Stage::query()->findOrFail($own->id)->update(['name' => 'Our First Screening']);
    expect($own->fresh()->name)->toBe('Our First Screening');

    $admin = securityAdmin($company);
    $this->actingAs($admin);
    Stage::query()->findOrFail($shared->id)->update(['name' => 'Screening (all companies)']);
    expect($shared->fresh()->name)->toBe('Screening (all companies)');
});

it('makes reordering the pipeline an administrator action', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $recruiter = securityRoleUser($company, 'recruiter');
    $recruiter->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'reorder_recruitment_stage', 'guard_name' => 'web']));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($recruiter->refresh()->can('reorder', Stage::class))->toBeFalse();
});

it('skips shared stages in a bulk delete instead of failing, and deletes the company\'s own', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $recruiter = securityRoleUser($company, 'recruiter');
    foreach (['delete_recruitment_stage', 'delete_any_recruitment_stage'] as $name) {
        $recruiter->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($recruiter->refresh());

    $shared = Stage::query()->create(['name' => 'Shared Offer', 'company_id' => null]);
    $own = Stage::query()->create(['name' => 'Our Offer', 'company_id' => $company->id]);

    Livewire::test(ListStages::class)
        ->callTableBulkAction('delete', [$shared, $own])
        ->assertNotified();

    expect(Stage::query()->whereKey($shared->id)->exists())->toBeTrue()
        ->and(Stage::query()->whereKey($own->id)->exists())->toBeFalse();
});
