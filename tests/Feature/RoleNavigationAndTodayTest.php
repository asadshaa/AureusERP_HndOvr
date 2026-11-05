<?php

/**
 * Each role gets the menu it uses (hidden items are UI only: the pages
 * still check access on the server), and the "Today" box at the top of
 * home lists today's work with counts limited to the active company.
 */

use App\Services\Dashboard\HomeDashboardContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\CoaImportHistoryResource;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\DocumentType;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeDocument;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Database\Factories\LeaveTypeFactory;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;

/** What a plain employee's role grants (the seeded "employee" role). */
const RN_EMPLOYEE_PERMISSIONS = [
    'view_any_employee_employee',
    'view_employee_employee',
    'view_any_time_off_my::allocation',
    'view_any_time_off_my::time::off',
    'create_time_off_my::time::off',
    'page_time_off_dashboard',
    'view_any_employee_attendance::record',
    'create_employee_attendance::record',
    'view_any_employee_employee::request',
    'create_employee_employee::request',
];

beforeEach(function () {
    // Wednesday 28 Oct 2026, 11:30 in Karachi (UTC+5): after office start.
    $this->travelTo(CarbonImmutable::parse('2026-10-28 06:30:00', 'UTC'));
    config([
        'attendance.monthly_report.office_start'       => '10:00',
        'attendance.monthly_report.office_end'         => '18:00',
        'attendance.monthly_report.late_grace_minutes' => 10,
        'attendance.monthly_report.timezone'           => 'Asia/Karachi',
        'attendance.monthly_report.weekend_days'       => [6, 7],
        'hr_attendance_geofence.enabled'               => true,
    ]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function rnCompany(string $name): Company
{
    return Company::factory()->create(['name' => $name, 'is_active' => true]);
}

/** @param  array<int, string>  $permissions */
function rnUser(Company $company, string $role, array $permissions = [], array $extraCompanies = []): User
{
    $roleModel = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    foreach ($permissions as $name) {
        $roleModel->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->assignRole($roleModel);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id, ...array_map(fn (Company $other): int => $other->id, $extraCompanies)]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function rnEmployee(Company $company, string $name, ?User $user = null, ?Employee $manager = null): Employee
{
    return Employee::query()->create([
        'company_id' => $company->id,
        'user_id'    => $user?->id,
        'parent_id'  => $manager?->id,
        'name'       => $name,
        'is_active'  => true,
        'time_zone'  => 'Asia/Karachi',
    ]);
}

/**
 * The admin menu the user gets on the home page, as group label => item labels.
 *
 * @return array<string, array<int, string>>
 */
function rnNavigation(User $user): array
{
    test()->actingAs($user);
    test()->get('/admin')->assertOk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $menu = [];
    foreach (Filament::getNavigation() as $group) {
        $menu[(string) ($group->getLabel() ?? '')] = collect($group->getItems())->map(fn ($item): string => (string) $item->getLabel())->values()->all();
    }

    return $menu;
}

/** @return array<string, array<string, mixed>> Today rows of the signed-in user, by key */
function rnToday(User $user): array
{
    test()->actingAs($user);
    app(HomeDashboardContext::class)->flush();
    $tasks = app(HomeDashboardContext::class)->todayTasks();

    return collect([...$tasks['mine'], ...$tasks['team']])->keyBy('key')->all();
}

function rnLeave(Employee $employee, State $state, User $by): Leave
{
    return Leave::query()->create([
        'company_id'        => $employee->company_id,
        'employee_id'       => $employee->id,
        'user_id'           => $by->id,
        'creator_id'        => $by->id,
        'holiday_status_id' => LeaveTypeFactory::new()->create(['company_id' => $employee->company_id, 'creator_id' => $by->id, 'name' => 'Casual Leave', 'requires_allocation' => 'no', 'request_unit' => 'day'])->id,
        'state'             => $state,
        'request_date_from' => '2026-11-10',
        'request_date_to'   => '2026-11-10',
        'date_from'         => '2026-11-10',
        'date_to'           => '2026-11-10',
        'number_of_days'    => 1,
    ]);
}

function rnPendingDocument(Employee $employee): EmployeeDocument
{
    return EmployeeDocument::query()->create([
        'company_id'       => $employee->company_id,
        'employee_id'      => $employee->id,
        'document_type_id' => DocumentType::query()->where('company_id', $employee->company_id)->value('id'),
        'title'            => 'CNIC',
        'file_path'        => 'employees/documents/test-'.uniqid().'.pdf',
        'original_name'    => 'cnic.pdf',
        'mime_type'        => 'application/pdf',
        'size'             => 1000,
        'status'           => EmployeeDocument::STATUS_PENDING,
    ]);
}

it('shows a plain employee only their self-service menu', function () {
    $company = rnCompany('Nav Employee Co');
    $user = rnUser($company, 'rn_employee', RN_EMPLOYEE_PERMISSIONS);
    rnEmployee($company, 'Hina Qureshi', $user);

    $menu = rnNavigation($user);
    $items = collect($menu)->flatten()->all();

    expect(array_keys($menu))->not->toContain('Accounting', 'Invoices', 'Plugins', 'Settings', 'Recruitment')
        ->and($items)->toContain('My Attendance', 'My Time', 'Employee Requests')
        ->and($items)->not->toContain('Approvals', 'Departments', 'Attendance Register', "Today's attendance", 'Employee Request Types', 'Team appraisals', 'Payroll input');

    // Hidden is not the only guard: the accounting pages refuse the employee on the server.
    $this->get(ReportTemplateResource::getUrl('index'))->assertForbidden();
    $this->get(CoaImportHistoryResource::getUrl('index'))->assertForbidden();
});

it('keeps accounting, plugins and system settings out of the HR manager menu', function () {
    $company = rnCompany('Nav HR Co');
    $user = rnUser($company, 'rn_hr_manager', HrPermissions::all());
    rnEmployee($company, 'Zainab HR', $user);

    $menu = rnNavigation($user);

    expect(array_keys($menu))->toContain('My work', 'Employees', 'Attendance')
        ->not->toContain('Accounting', 'Invoices', 'Plugins', 'Settings')
        ->and($menu['Employees'])->toContain('Employee Request Types');

    $this->actingAs($user);
    expect(ReportTemplateResource::canViewAny())->toBeFalse()
        ->and(CoaImportHistoryResource::canViewAny())->toBeFalse();
});

it('lists a line manager approvals under their own work, without HR setup items', function () {
    $company = rnCompany('Nav Manager Co');
    $user = rnUser($company, 'rn_manager', [...HrPermissions::manager(), 'view_any_support_approval::request', 'view_support_approval::request']);
    $manager = rnEmployee($company, 'Kamran Shah', $user);
    rnEmployee($company, 'Sana Report', null, $manager);

    $menu = rnNavigation($user);

    expect($menu['My work'] ?? [])->toContain('Approvals')
        ->and(array_keys($menu))->not->toContain('Accounting', 'Settings', 'Plugins')
        ->and(collect($menu)->flatten()->all())->not->toContain('Employee Request Types');

    // The page itself stays open to them: only the menu entry is hidden.
    $this->get('/admin/employee-request-types')->assertOk();
});

it('gives HR a Today box with counts from the active company only', function () {
    $company = rnCompany('Today HR Co');
    $other = rnCompany('Today Other Co');
    $user = rnUser($company, 'rn_today_hr', [...HrPermissions::all(), 'view_any_time_off_time::off'], [$other]);
    rnEmployee($company, 'Zainab HR', $user);

    // Active company: two waiting leave requests, one pending document, one late and one missing check-in.
    $late = rnEmployee($company, 'Fahad Late');
    AttendanceRecord::query()->create([
        'company_id'      => $company->id,
        'employee_id'     => $late->id,
        'attendance_date' => '2026-10-28',
        'check_in'        => CarbonImmutable::parse('2026-10-28 10:40', 'Asia/Karachi')->utc()->toDateTimeString(),
        'status'          => 'present',
        'source'          => 'manual',
    ]);
    $missing = rnEmployee($company, 'Rabia Missing');
    rnLeave($late, State::CONFIRM, $user);
    rnLeave($missing, State::VALIDATE_ONE, $user);
    rnLeave($missing, State::VALIDATE_TWO, $user);
    rnPendingDocument($missing);

    // The other company has more of everything; none of it may be counted.
    $elsewhere = rnEmployee($other, 'Other Employee');
    rnLeave($elsewhere, State::CONFIRM, $user);
    rnLeave($elsewhere, State::CONFIRM, $user);
    rnPendingDocument($elsewhere);
    rnPendingDocument($elsewhere);

    $today = rnToday($user);

    expect($today['leave_requests']['count'])->toBe(2)
        ->and($today['documents']['count'])->toBe(1)
        ->and($today['late']['count'])->toBe(1)
        ->and($today['not_checked_in']['count'])->toBeGreaterThanOrEqual(1)
        ->and($today)->not->toHaveKey('applicants');

    $this->get('/admin')->assertOk()
        ->assertSee('Today')
        ->assertSee('Leave requests waiting')
        ->assertSee('Documents to check')
        ->assertSee('Late today');
});

it('gives an employee check-in, leave balance and their requests, and a friendly empty state otherwise', function () {
    $company = rnCompany('Today Employee Co');
    $user = rnUser($company, 'rn_today_employee', RN_EMPLOYEE_PERMISSIONS);
    rnEmployee($company, 'Hina Qureshi', $user);

    $today = rnToday($user);

    expect($today)->toHaveKey('leave_balance')
        ->and($today['leave_balance']['label'])->toContain('of leave left')
        ->and($today)->not->toHaveKeys(['approvals', 'leave_requests', 'documents', 'not_checked_in']);

    $this->get('/admin')->assertOk()
        ->assertSee('For you')
        ->assertSee('of leave left')
        ->assertDontSee('Leave requests waiting');

    // Someone with no employee record and nothing to do.
    $plain = rnUser($company, 'rn_today_plain');
    expect(rnToday($plain))->toBe([]);

    $this->actingAs($plain)->get('/admin')->assertOk()->assertSee('Nothing waiting for you today');
});
