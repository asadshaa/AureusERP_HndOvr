<?php

/**
 * Multi-company cross view (docs/MULTI_COMPANY_ACCESS_PLAN.md): a user who
 * belongs to several companies sees the records of all of them on the HR and
 * recruitment lists, narrowed with a "Company" filter that starts on the
 * active company. Other companies' records are view-only; single-company
 * users see no change and no filter.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource;
use Webkul\Employee\Filament\Resources\DepartmentResource;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ManageSkill;
use Webkul\Employee\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\ListApplicants;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar as WebkulPermissionRegistrar;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\TimeOffResource;

const CROSS_VIEW_HR_PERMISSIONS = [
    'hr_view_all_records',
    'view_any_employee_employee', 'view_employee_employee', 'update_employee_employee', 'delete_employee_employee',
    'view_any_recruitment_applicant', 'view_recruitment_applicant', 'update_recruitment_applicant', 'delete_recruitment_applicant',
];

/** An HR user whose active company is $company, who also belongs to $alsoIn. */
function crossMember(Company $company, array $alsoIn = [], string ...$permissions): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    $user->allowedCompanies()->sync(collect($alsoIn)->pluck('id')->all());

    foreach ($permissions ?: CROSS_VIEW_HR_PERMISSIONS as $name) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(WebkulPermissionRegistrar::class)->forgetCachedPermissions();
    CurrentCompany::flush();
    app(HrHierarchyService::class)->flushCache();

    return $user->refresh();
}

function crossEmployee(Company $company): Employee
{
    return Employee::query()->create([
        'company_id' => $company->id, 'name' => 'Employee '.Str::random(6), 'is_active' => true, 'employment_status' => 'active',
    ]);
}

function crossApplicant(Company $company): Applicant
{
    $job = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Driver '.Str::random(4), 'is_active' => true]);
    $candidate = Candidate::query()->create(['company_id' => $company->id, 'name' => 'Candidate '.Str::random(6), 'email_from' => Str::random(8).'@example.test']);

    return Applicant::query()->create(['company_id' => $company->id, 'candidate_id' => $candidate->id, 'job_id' => $job->id, 'create_date' => now()]);
}

beforeEach(function (): void {
    $this->truckItIn = Company::factory()->create(['name' => 'Truck It In '.Str::random(4), 'is_active' => true]);
    $this->shipLine = Company::factory()->create(['name' => 'ShipLine '.Str::random(4), 'is_active' => true]);
    $this->openPort = Company::factory()->create(['name' => 'OpenPort '.Str::random(4), 'is_active' => true]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

it('scopes a query to the user\'s member companies, and to nothing without a membership', function () {
    $hr = crossMember($this->shipLine, [$this->openPort]);
    $ours = crossEmployee($this->shipLine);
    $shared = crossEmployee($this->openPort);
    $stranger = crossEmployee($this->truckItIn);

    expect(CurrentCompany::scopeMemberships(Employee::query(), user: $hr)->pluck('id')->all())
        ->toContain($ours->id, $shared->id)
        ->not->toContain($stranger->id);

    $nobody = User::factory()->create(['default_company_id' => null, 'is_active' => true]);
    expect(CurrentCompany::scopeMemberships(Employee::query(), user: $nobody)->count())->toBe(0);
});

it('shows a single-company HR only their company, with no Company filter', function () {
    $hr = crossMember($this->shipLine);
    $ours = crossEmployee($this->shipLine);
    $theirs = crossEmployee($this->openPort);
    $this->actingAs($hr);

    Livewire::test(ListEmployees::class)
        ->assertTableFilterHidden('company_id')
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$theirs]);

    expect(EmployeeResource::getEloquentQuery()->pluck('id')->all())->not->toContain($theirs->id);
});

it('starts a two-company HR on the active company and shows the other one through the Company filter', function () {
    $hr = crossMember($this->shipLine, [$this->openPort]);
    $ours = crossEmployee($this->shipLine);
    $shared = crossEmployee($this->openPort);
    $stranger = crossEmployee($this->truckItIn);
    $this->actingAs($hr);

    Livewire::test(ListEmployees::class)
        ->assertTableFilterVisible('company_id')
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$shared, $stranger])
        ->filterTable('company_id', [$this->openPort->id])
        ->assertCanSeeTableRecords([$shared])
        ->assertCanNotSeeTableRecords([$ours, $stranger])
        ->filterTable('company_id', [])
        ->assertCanSeeTableRecords([$ours, $shared])
        ->assertCanNotSeeTableRecords([$stranger])
        ->filterTable('company_id', [$this->truckItIn->id])
        ->assertCanNotSeeTableRecords([$ours, $shared, $stranger]);

    $options = EmployeeResource::companyFilter()->getOptions();
    expect(array_keys($options))->toEqualCanonicalizing([$this->shipLine->id, $this->openPort->id]);
});

it('shows a two-company HR the other company\'s applicants when the filter is set', function () {
    $hr = crossMember($this->shipLine, [$this->openPort]);
    $ours = crossApplicant($this->shipLine);
    $shared = crossApplicant($this->openPort);
    $stranger = crossApplicant($this->truckItIn);
    $this->actingAs($hr);

    Livewire::test(ListApplicants::class)
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$shared, $stranger])
        ->filterTable('company_id', [$this->openPort->id])
        ->assertCanSeeTableRecords([$shared])
        ->assertCanNotSeeTableRecords([$ours, $stranger]);

    foreach ([CandidateResource::class => [$ours->candidate, $shared->candidate, $stranger->candidate], JobPositionResource::class => [$ours->job, $shared->job, $stranger->job]] as $resource => [$own, $other, $foreign]) {
        expect($resource::getEloquentQuery()->pluck('id')->all())
            ->toContain($own->id, $other->id)
            ->not->toContain($foreign->id);
    }
});

it('lets another member company\'s records be viewed but not edited or deleted', function () {
    $hr = crossMember($this->shipLine, [$this->openPort]);
    $ours = crossEmployee($this->shipLine);
    $theirs = crossEmployee($this->openPort);
    $theirApplicant = crossApplicant($this->openPort);
    $theirDepartment = Department::query()->create(['company_id' => $this->openPort->id, 'name' => 'Ops '.Str::random(4)]);
    $this->actingAs($hr);

    expect(EmployeeResource::canEdit($ours))->toBeTrue()
        ->and(EmployeeResource::canView($theirs))->toBeTrue()
        ->and(EmployeeResource::canEdit($theirs))->toBeFalse()
        ->and(EmployeeResource::canDelete($theirs))->toBeFalse()
        ->and(EmployeeResource::getEditAuthorizationResponse($theirs)->message())->toContain($this->openPort->name)
        ->and(ApplicantResource::canView($theirApplicant))->toBeTrue()
        ->and(ApplicantResource::canEdit($theirApplicant))->toBeFalse()
        ->and(ApplicantResource::canDelete($theirApplicant))->toBeFalse()
        ->and(DepartmentResource::canEdit($theirDepartment))->toBeFalse();

    Livewire::test(ViewEmployee::class, ['record' => $theirs->getRouteKey()])
        ->assertOk()
        ->assertActionDoesNotExist('delete');

    Livewire::test(ListEmployees::class)
        ->filterTable('company_id', [])
        ->assertTableActionDisabled('edit', $theirs)
        ->assertTableActionEnabled('edit', $ours);

    Livewire::test(EditEmployee::class, ['record' => $theirs->getRouteKey()])->assertForbidden();

    expect(ManageSkill::canAccess(['record' => $theirs]))->toBeFalse();

    expect(EmployeeResource::canDelete($theirs))->toBeFalse();

    CurrentCompany::switchTo($this->openPort->id, $hr);
    Auth::login($hr->refresh());
    expect(EmployeeResource::canEdit($theirs))->toBeTrue()
        ->and(EmployeeResource::canEdit($ours))->toBeFalse();
});

it('lets the group owner see every active company, but never an archived one', function () {
    $owner = crossMember($this->truckItIn, [], CurrentCompany::MANAGE_MEMBERSHIPS, ...CROSS_VIEW_HR_PERMISSIONS);
    $archived = Company::factory()->create(['name' => 'Archived '.Str::random(4), 'is_active' => true]);
    $employees = [crossEmployee($this->truckItIn), crossEmployee($this->shipLine), crossEmployee($this->openPort)];
    $archivedEmployee = crossEmployee($archived);
    $archived->delete();
    CurrentCompany::flush();
    $this->actingAs($owner);

    expect(EmployeeResource::getEloquentQuery()->pluck('id')->all())
        ->toContain(...collect($employees)->pluck('id')->all())
        ->not->toContain($archivedEmployee->id);

    expect(array_keys(EmployeeResource::companyFilter()->getOptions()))
        ->toContain($this->truckItIn->id, $this->shipLine->id, $this->openPort->id)
        ->not->toContain($archived->id);

    Livewire::test(ListEmployees::class)
        ->assertCanSeeTableRecords([$employees[0]])
        ->assertCanNotSeeTableRecords([$employees[1], $employees[2]])
        ->filterTable('company_id', [])
        ->assertCanSeeTableRecords($employees);

    expect(app(HrHierarchyService::class)->visibleEmployeeIds($owner, $this->openPort->id))->toContain($employees[2]->id);
});

it('keeps the attendance and time-off lists inside the member companies', function () {
    $hr = crossMember($this->shipLine, [$this->openPort], 'hr_view_all_records');
    $this->actingAs($hr);

    foreach ([AttendanceRecordResource::class, TimeOffResource::class] as $resource) {
        $bindings = array_map('intval', $resource::getEloquentQuery()->getBindings());

        expect($bindings)->toContain($this->shipLine->id, $this->openPort->id)
            ->not->toContain($this->truckItIn->id);
    }
});
