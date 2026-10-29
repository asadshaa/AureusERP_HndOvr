<?php

/**
 * HR Parity 3.1 and 3.7: the org chart (OrgChartService, OrgChart page) and
 * the HR reports in Employees -> Reports (joiners & leavers, headcount trend
 * with turnover, leave balances): visibility per role, company isolation,
 * counts at month boundaries, leave figures, the Excel workbooks and query
 * counts that do not grow with the number of employees.
 */

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Clusters\Reportings\Pages\HeadcountTrend;
use Webkul\Employee\Filament\Clusters\Reportings\Pages\JoinersLeavers;
use Webkul\Employee\Filament\Clusters\Reportings\Pages\LeaveBalances;
use Webkul\Employee\Filament\Pages\OrgChart;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\DepartureReason;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Services\OrgChartService;
use Webkul\Employee\Services\Reports\HeadcountTrendReport;
use Webkul\Employee\Services\Reports\HrReportExcel;
use Webkul\Employee\Services\Reports\JoinersLeaversReport;
use Webkul\Employee\Services\Reports\LeaveBalanceReport;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar as WebkulPermissionRegistrar;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveBalanceService;

beforeEach(function () {
    config(['attendance.monthly_report.timezone' => 'Asia/Karachi']);
    test()->travelTo(CarbonImmutable::parse('2026-10-28 09:00', 'Asia/Karachi')->utc());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

function ocrCompany(string $name = 'Org Co'): Company
{
    return Company::factory()->create(['name' => $name.' '.uniqid(), 'is_active' => true]);
}

/** @param  array<int, string>  $permissions */
function ocrUser(Company $company, array $permissions = [], string $name = 'User'): User
{
    $user = User::factory()->create(['name' => $name, 'default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(WebkulPermissionRegistrar::class)->forgetCachedPermissions();
    CurrentCompany::flush();

    return $user->fresh();
}

function ocrHr(Company $company, array $extra = []): User
{
    return ocrUser($company, [HrPermissions::ViewAllRecords, HrPermissions::ViewReports, 'view_any_employee_employee', 'view_employee_employee', ...$extra], 'Hina HR');
}

function ocrEmployee(Company $company, string $name, array $attributes = []): Employee
{
    return Employee::query()->create($attributes + [
        'company_id'   => $company->id,
        'name'         => $name,
        'is_active'    => true,
        'joining_date' => '2020-01-01',
    ]);
}

/** Visible names of an org chart, in display order. */
function ocrNames(array $chart): array
{
    return array_map(fn (int $id): string => $chart['nodes'][$id]['name'], $chart['order']);
}

function ocrChart(User $user, Company $company): array
{
    app(HrHierarchyService::class)->flushCache();

    return app(OrgChartService::class)->forUser($user, $company->id);
}

/**
 * CEO -> Ops Manager -> Ali, Sara -> Intern; CEO -> HR Head; CEO -> Finance
 * Manager -> Accountant; plus Aaron Solo (no manager) and two people who left.
 *
 * @return array<string, mixed>
 */
function ocrOrg(): array
{
    $company = ocrCompany();
    $ops = Department::query()->create(['name' => 'Operations', 'company_id' => $company->id]);
    $fleet = Department::query()->create(['name' => 'Fleet', 'company_id' => $company->id, 'parent_id' => $ops->id]);
    $finance = Department::query()->create(['name' => 'Finance', 'company_id' => $company->id]);

    $managerUser = ocrUser($company, ['view_employee_employee'], 'Kamran');
    $employeeUser = ocrUser($company, ['view_employee_employee'], 'Ali');

    $ceo = ocrEmployee($company, 'Tariq CEO', ['job_title' => 'General Manager']);
    $hrHead = ocrEmployee($company, 'Hina Head', ['parent_id' => $ceo->id]);
    $opsManager = ocrEmployee($company, 'Kamran Ops', ['parent_id' => $ceo->id, 'user_id' => $managerUser->id, 'department_id' => $ops->id, 'job_title' => 'Operations Manager']);
    $ali = ocrEmployee($company, 'Ali Clerk', ['parent_id' => $opsManager->id, 'user_id' => $employeeUser->id, 'department_id' => $ops->id]);
    $sara = ocrEmployee($company, 'Sara Lead', ['parent_id' => $opsManager->id, 'department_id' => $fleet->id]);
    $intern = ocrEmployee($company, 'Iqra Intern', ['parent_id' => $sara->id, 'department_id' => $fleet->id]);
    $financeManager = ocrEmployee($company, 'Faisal Finance', ['parent_id' => $ceo->id, 'department_id' => $finance->id]);
    $accountant = ocrEmployee($company, 'Asma Accounts', ['parent_id' => $financeManager->id, 'department_id' => $finance->id]);
    $solo = ocrEmployee($company, 'Aaron Solo');
    ocrEmployee($company, 'Gone Already', ['parent_id' => $opsManager->id, 'leaving_date' => '2026-09-30']);
    ocrEmployee($company, 'Switched Off', ['parent_id' => $opsManager->id])->forceFill(['is_active' => false])->saveQuietly();

    return compact('company', 'ops', 'fleet', 'finance', 'managerUser', 'employeeUser', 'ceo', 'hrHead', 'opsManager', 'ali', 'sara', 'intern', 'financeManager', 'accountant', 'solo');
}

// ---------------------------------------------------------------------
// Org chart
// ---------------------------------------------------------------------
it('shows HR and the group owner the whole company, top-level manager first, current employees only', function () {
    $org = ocrOrg();
    $hr = ocrHr($org['company']);

    $chart = ocrChart($hr, $org['company']);

    expect($chart['scope'])->toBe('company')
        ->and($chart['total'])->toBe(9)
        ->and($chart['roots'])->toBe([$org['ceo']->id, $org['solo']->id])
        // Under each manager: people with a team first, then by name.
        ->and(ocrNames($chart))->toBe(['Tariq CEO', 'Faisal Finance', 'Asma Accounts', 'Kamran Ops', 'Sara Lead', 'Iqra Intern', 'Ali Clerk', 'Hina Head', 'Aaron Solo'])
        ->and(ocrNames($chart))->not->toContain('Gone Already', 'Switched Off')
        ->and($chart['nodes'][$org['ceo']->id]['size'])->toBe(7)
        ->and($chart['nodes'][$org['opsManager']->id])->toMatchArray(['title' => 'Operations Manager', 'dept' => 'Operations', 'parent' => $org['ceo']->id, 'initials' => 'KO', 'open' => true])
        ->and(collect($chart['nodes'])->every(fn (array $node): bool => $node['open']))->toBeTrue()
        // Operations takes in its sub-department Fleet.
        ->and(collect($chart['departments'])->firstWhere('id', $org['ops']->id))->toMatchArray(['count' => 4])
        ->and(collect($chart['departments'])->firstWhere('id', $org['ops']->id)['ids'])->toEqualCanonicalizing([$org['ops']->id, $org['fleet']->id]);

    $owner = ocrUser($org['company'], [CurrentCompany::MANAGE_MEMBERSHIPS], 'Owner');

    expect(CurrentCompany::isGroupOwner($owner))->toBeTrue()
        ->and(ocrChart($owner, $org['company']))->toMatchArray(['scope' => 'company', 'total' => 9]);
});

it('shows a line manager their team, their own manager and their peers', function () {
    $org = ocrOrg();

    $chart = ocrChart($org['managerUser'], $org['company']);

    expect($chart['scope'])->toBe('hierarchy')
        ->and($chart['self_id'])->toBe($org['opsManager']->id)
        ->and(ocrNames($chart))->toEqualCanonicalizing(['Tariq CEO', 'Kamran Ops', 'Ali Clerk', 'Sara Lead', 'Iqra Intern', 'Hina Head', 'Faisal Finance'])
        ->and(ocrNames($chart))->not->toContain('Asma Accounts', 'Aaron Solo')
        ->and($chart['roots'])->toBe([$org['ceo']->id])
        // Opens only the people the Employees screen lets them see.
        ->and($chart['nodes'][$org['intern']->id]['open'])->toBeTrue()
        ->and($chart['nodes'][$org['ceo']->id]['open'])->toBeFalse()
        ->and($chart['nodes'][$org['hrHead']->id]['open'])->toBeFalse();
});

it('shows an employee their chain upwards and their peers only', function () {
    $org = ocrOrg();

    $chart = ocrChart($org['employeeUser'], $org['company']);

    expect(ocrNames($chart))->toBe(['Tariq CEO', 'Kamran Ops', 'Ali Clerk', 'Sara Lead'])
        ->and($chart['self_id'])->toBe($org['ali']->id)
        ->and($chart['nodes'][$org['ali']->id]['self'])->toBeTrue()
        ->and($chart['nodes'][$org['ali']->id]['open'])->toBeTrue()
        ->and($chart['nodes'][$org['sara']->id]['open'])->toBeFalse()
        // Sara's own team is not the employee's business.
        ->and($chart['nodes'][$org['sara']->id]['kids'])->toBe([]);

    $stranger = ocrUser($org['company'], [], 'No employee');

    expect(ocrChart($stranger, $org['company'])['total'])->toBe(0);
});

it('keeps each company apart on the org chart and refuses a company the user does not belong to', function () {
    $org = ocrOrg();
    $other = ocrCompany('Other Co');
    ocrEmployee($other, 'Outsider Person');
    $hr = ocrHr($org['company']);

    expect(ocrNames(ocrChart($hr, $org['company'])))->not->toContain('Outsider Person')
        ->and(fn () => ocrChart($hr, $other))->toThrow(AuthorizationException::class);

    Auth::login($hr);

    Livewire::test(OrgChart::class)
        ->assertOk()
        ->assertSee('Tariq CEO')
        ->assertSee('Iqra Intern')
        ->assertDontSee('Outsider Person');

    Auth::login($org['employeeUser']);

    Livewire::test(OrgChart::class)
        ->assertOk()
        ->assertSee('Sara Lead')
        ->assertDontSee('Iqra Intern')
        ->assertDontSee('Asma Accounts');
});

it('cuts a reporting loop so everyone still shows once', function () {
    $company = ocrCompany();
    $first = ocrEmployee($company, 'Loop One');
    $second = ocrEmployee($company, 'Loop Two', ['parent_id' => $first->id]);
    DB::table('employees_employees')->where('id', $first->id)->update(['parent_id' => $second->id]);

    $chart = ocrChart(ocrHr($company), $company);

    expect($chart['total'])->toBe(2)
        ->and($chart['order'])->toHaveCount(2)
        ->and($chart['roots'])->toHaveCount(1);
});

// ---------------------------------------------------------------------
// Joiners & leavers
// ---------------------------------------------------------------------

/** @return array<string, mixed> */
function ocrMovements(): array
{
    $company = ocrCompany('Movers');
    $ops = Department::query()->create(['name' => 'Operations', 'company_id' => $company->id]);
    $fleet = Department::query()->create(['name' => 'Fleet', 'company_id' => $company->id, 'parent_id' => $ops->id]);
    $sales = Department::query()->create(['name' => 'Sales', 'company_id' => $company->id]);
    $resigned = DepartureReason::query()->create(['name' => 'Resigned '.uniqid(), 'reason_code' => 9001, 'sort' => 1]);
    $retired = DepartureReason::query()->create(['name' => 'Retired '.uniqid(), 'reason_code' => 9002, 'sort' => 2]);

    $people = [
        'january'  => ocrEmployee($company, 'January Joiner', ['joining_date' => '2026-01-15', 'department_id' => $ops->id]),
        'march'    => ocrEmployee($company, 'March Joiner', ['joining_date' => '2026-03-01', 'leaving_date' => '2026-09-30', 'departure_reason_id' => $resigned->id, 'department_id' => $fleet->id]),
        'retiree'  => ocrEmployee($company, 'Old Timer', ['joining_date' => '2020-05-01', 'leaving_date' => '2026-03-31', 'departure_reason_id' => $retired->id, 'department_id' => $sales->id]),
        'archived' => ocrEmployee($company, 'Archived Person', ['joining_date' => '2019-01-01', 'department_id' => $sales->id]),
        'eve'      => ocrEmployee($company, 'New Year Eve', ['joining_date' => '2025-12-31', 'department_id' => $sales->id]),
        'future'   => ocrEmployee($company, 'Future Joiner', ['joining_date' => '2026-11-15', 'department_id' => $ops->id]),
    ];
    DB::table('employees_employees')->where('id', $people['archived']->id)->update(['deleted_at' => '2026-06-10 05:00:00']);

    return compact('company', 'ops', 'fleet', 'sales', 'resigned', 'retired') + ['people' => $people];
}

it('counts joiners and leavers by month, with departure reasons and turnover', function () {
    $data = ocrMovements();
    $hr = ocrHr($data['company']);

    $report = app(JoinersLeaversReport::class)->build($hr, $data['company']->id, '2026-01-01', '2026-10-28');
    $byMonth = collect($report['months'])->keyBy('month');

    expect(array_column($report['joiners'], 'name'))->toBe(['January Joiner', 'March Joiner'])
        ->and(array_column($report['leavers'], 'name'))->toBe(['Old Timer', 'Archived Person', 'March Joiner'])
        ->and($report['months'])->toHaveCount(10)
        ->and($byMonth['2026-01'])->toMatchArray(['joiners' => 1, 'leavers' => 0, 'net' => 1])
        ->and($byMonth['2026-03'])->toMatchArray(['joiners' => 1, 'leavers' => 1, 'net' => 0])
        ->and($byMonth['2026-06'])->toMatchArray(['joiners' => 0, 'leavers' => 1])
        ->and($byMonth['2026-09'])->toMatchArray(['joiners' => 0, 'leavers' => 1])
        ->and($report['leavers'][1])->toMatchArray(['left' => '2026-06-10', 'left_source' => 'archived', 'reason' => null])
        ->and($report['leavers'][2])->toMatchArray(['tenure' => '7 m', 'reason' => $data['resigned']->name])
        ->and(collect($report['reasons'])->pluck('count', 'reason')->all())->toEqual([
            $data['retired']->name          => 1,
            JoinersLeaversReport::NO_REASON => 1,
            $data['resigned']->name         => 1,
        ])
        // Start: Old Timer, Archived Person, New Year Eve. End: January Joiner, New Year Eve.
        ->and($report['summary'])->toMatchArray(['joiners' => 2, 'leavers' => 3, 'net' => -1, 'opening' => 3, 'closing' => 2, 'turnover_percent' => 120.0]);

    // Operations takes in Fleet; Sales people are left out.
    $operations = app(JoinersLeaversReport::class)->build($hr, $data['company']->id, '2026-01-01', '2026-10-28', $data['ops']->id);

    expect(array_column($operations['joiners'], 'name'))->toBe(['January Joiner', 'March Joiner'])
        ->and(array_column($operations['leavers'], 'name'))->toBe(['March Joiner'])
        ->and($operations['department'])->toBe('Operations');
});

it('takes the record date for a joiner without a joining date and refuses a reversed period', function () {
    $company = ocrCompany();
    ocrEmployee($company, 'No Date', ['joining_date' => null]);
    $hr = ocrHr($company);

    $report = app(JoinersLeaversReport::class)->build($hr, $company->id, '2026-10-01', '2026-10-31');

    expect($report['joiners'][0])->toMatchArray(['name' => 'No Date', 'joined' => '2026-10-28', 'joined_source' => 'record created'])
        ->and(fn () => app(JoinersLeaversReport::class)->build($hr, $company->id, '2026-10-31', '2026-10-01'))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------
// Headcount trend
// ---------------------------------------------------------------------

/** @return array{company: Company, hr: User} */
function ocrBoundaryCompany(): array
{
    $company = ocrCompany('Boundary');
    ocrEmployee($company, 'Joins First Of August', ['joining_date' => '2026-08-01']);
    ocrEmployee($company, 'Leaves Last Of August', ['joining_date' => '2021-01-01', 'leaving_date' => '2026-08-31']);
    ocrEmployee($company, 'Leaves First Of September', ['joining_date' => '2021-01-01', 'leaving_date' => '2026-09-01']);
    ocrEmployee($company, 'Joins Last Of July', ['joining_date' => '2026-07-31']);
    ocrEmployee($company, 'Stays', ['joining_date' => '2020-01-01']);
    ocrEmployee($company, 'Joins Later This Month', ['joining_date' => '2026-10-30']);

    return ['company' => $company, 'hr' => ocrHr($company)];
}

it('counts the headcount at month boundaries: a leaving date is the last day on the books', function () {
    ['company' => $company, 'hr' => $hr] = ocrBoundaryCompany();

    $report = app(HeadcountTrendReport::class)->build($hr, $company->id, '2026-10', 12);
    $byMonth = collect($report['months'])->keyBy('month');

    expect($report['months'])->toHaveCount(12)
        ->and($report['months'][0]['month'])->toBe('2025-11')
        ->and($byMonth['2026-07'])->toMatchArray(['opening' => 3, 'joiners' => 1, 'leavers' => 0, 'closing' => 4])
        ->and($byMonth['2026-08'])->toMatchArray(['opening' => 4, 'joiners' => 1, 'leavers' => 1, 'closing' => 4])
        ->and($byMonth['2026-09'])->toMatchArray(['opening' => 4, 'joiners' => 0, 'leavers' => 1, 'closing' => 3])
        // The current month runs to today: the joiner of the 30th is not counted yet.
        ->and($byMonth['2026-10'])->toMatchArray(['as_of' => '2026-10-28', 'opening' => 3, 'joiners' => 0, 'closing' => 3])
        ->and(collect($report['months'])->every(fn (array $month): bool => $month['closing'] === $month['opening'] + $month['joiners'] - $month['leavers']))->toBeTrue()
        ->and($report['chart']['labels'])->toHaveCount(12)
        ->and($report['chart']['datasets'][0]['data'])->toBe(array_column($report['months'], 'closing'))
        ->and(collect($report['departments'])->firstWhere('name', 'No department')['closing']['2026-08'])->toBe(4);

    // A future end month is cut to the current month.
    expect(app(HeadcountTrendReport::class)->build($hr, $company->id, '2027-03', 12)['months'][11]['month'])->toBe('2026-10');
});

it('works out turnover per month and for the period', function () {
    ['company' => $company, 'hr' => $hr] = ocrBoundaryCompany();

    $report = app(HeadcountTrendReport::class)->build($hr, $company->id, '2026-09', 12);
    $byMonth = collect($report['months'])->keyBy('month');

    // September: 1 leaver / ((4 + 3) / 2). Period (Oct 2025 - Sep 2026): 2 leavers / 38/12 average.
    expect($byMonth['2026-09']['turnover_percent'])->toBe(28.6)
        ->and($byMonth['2026-06']['turnover_percent'])->toBe(0.0)
        ->and($report['summary'])->toMatchArray([
            'opening'           => 3,
            'closing'           => 3,
            'joiners'           => 2,
            'leavers'           => 2,
            'change'            => 0,
            'average_headcount' => 3.2,
            'turnover_percent'  => 63.2,
        ]);
});

// ---------------------------------------------------------------------
// Leave balances
// ---------------------------------------------------------------------
function ocrLeave(Employee $employee, LeaveType $type, string $date, float $days, State $state, array $attributes = []): Leave
{
    $leave = Leave::query()->create($attributes + [
        'user_id'             => $employee->user_id,
        'holiday_status_id'   => $type->id,
        'employee_id'         => $employee->id,
        'employee_company_id' => $employee->company_id,
        'company_id'          => $employee->company_id,
        'department_id'       => $employee->department_id,
        'state'               => State::CONFIRM,
        'request_date_from'   => $date,
        'request_date_to'     => $date,
        'date_from'           => $date,
        'date_to'             => $date,
        'number_of_days'      => $days,
    ]);

    DB::table('time_off_leaves')->where('id', $leave->id)->update(['state' => $state->value]);

    return $leave;
}

function ocrType(Company $company, string $name): LeaveType
{
    return LeaveType::query()->where('company_id', $company->id)->where('name', $name)->firstOrFail();
}

it('reports allocated, taken, pending and remaining leave per type, without cancelled, refused or Half day leave', function () {
    $company = ocrCompany('Leave Co');
    $hr = ocrHr($company);
    // Created in 2026 without a joining date: the hire-time allocation gives Annual 12, Sick 8, Casual 5.
    $employee = ocrEmployee($company, 'Ali Leave', ['joining_date' => null]);
    $colleague = ocrEmployee($company, 'Bina Leave', ['joining_date' => null]);
    $annual = ocrType($company, 'Annual Leave');
    $casual = ocrType($company, 'Casual Leave');

    ocrLeave($employee, $annual, '2026-11-02', 2, State::VALIDATE_TWO);
    ocrLeave($employee, $annual, '2026-11-10', 1, State::CONFIRM);
    ocrLeave($employee, $annual, '2026-11-11', 1, State::VALIDATE_ONE);
    ocrLeave($employee, $annual, '2026-11-12', 1, State::CANCEL);
    ocrLeave($employee, $annual, '2026-11-13', 1, State::REFUSE);
    ocrLeave($employee, ocrType($company, 'Half day'), '2026-11-16', 0.5, State::VALIDATE_TWO, ['request_unit_half' => true]);
    ocrLeave($employee, $casual, '2026-11-17', 0.5, State::VALIDATE_TWO, ['request_unit_half' => true]);

    $report = app(LeaveBalanceReport::class)->build($hr, $company->id, 2026);
    $rows = collect($report['rows'])->keyBy('name');

    expect(array_column($report['types'], 'name'))->toBe(['Annual Leave', 'Sick Leave', 'Casual Leave'])
        ->and(collect($report['types'])->every(fn (array $type): bool => $type['tracked']))->toBeTrue()
        ->and($rows['Ali Leave']['balances']['annual'])->toBe(['allocated' => 12.0, 'taken' => 2.0, 'pending' => 2.0, 'remaining' => 8.0])
        ->and($rows['Ali Leave']['balances']['casual'])->toBe(['allocated' => 5.0, 'taken' => 0.5, 'pending' => 0.0, 'remaining' => 4.5])
        ->and($rows['Ali Leave']['balances']['sick'])->toBe(['allocated' => 8.0, 'taken' => 0.0, 'pending' => 0.0, 'remaining' => 8.0])
        ->and($rows['Bina Leave']['balances']['annual'])->toBe(['allocated' => 12.0, 'taken' => 0.0, 'pending' => 0.0, 'remaining' => 12.0])
        ->and($report['totals']['annual'])->toBe(['allocated' => 24.0, 'taken' => 2.0, 'pending' => 2.0, 'remaining' => 20.0])
        ->and($report['without_allocation'])->toBe(0);

    // The same figures as the balance the employee sees on the request form.
    $single = app(LeaveBalanceService::class)->forEmployee($employee, $annual, 2026);
    expect($rows['Ali Leave']['balances']['annual'])->toMatchArray(array_intersect_key($single, array_flip(['allocated', 'taken', 'pending', 'remaining'])));
});

it('keeps each company apart in the reports and needs hr_view_reports', function () {
    $data = ocrMovements();
    $other = ocrCompany('Other Co');
    ocrEmployee($other, 'Outsider Joiner', ['joining_date' => '2026-02-02']);
    $hr = ocrHr($data['company']);

    $report = app(JoinersLeaversReport::class)->build($hr, $data['company']->id, '2026-01-01', '2026-10-28');
    $balances = app(LeaveBalanceReport::class)->build($hr, $data['company']->id, 2026);

    expect(array_column($report['joiners'], 'name'))->not->toContain('Outsider Joiner')
        ->and(array_column($balances['rows'], 'name'))->not->toContain('Outsider Joiner')
        ->and(fn () => app(JoinersLeaversReport::class)->build($hr, $other->id, '2026-01-01', '2026-10-28'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(HeadcountTrendReport::class)->build($hr, $other->id, '2026-10'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(LeaveBalanceReport::class)->build($hr, $other->id, 2026))->toThrow(AuthorizationException::class);

    // A department of another company never widens the report.
    $foreignDepartment = Department::query()->create(['name' => 'Foreign', 'company_id' => $other->id]);
    expect(app(JoinersLeaversReport::class)->build($hr, $data['company']->id, '2026-01-01', '2026-10-28', $foreignDepartment->id)['joiners'])->toBe([]);

    $withoutPermission = ocrUser($data['company'], [HrPermissions::ViewAllRecords], 'No reports');
    expect(fn () => app(LeaveBalanceReport::class)->build($withoutPermission, $data['company']->id, 2026))->toThrow(AuthorizationException::class);

    Auth::login($withoutPermission);
    expect(JoinersLeavers::canAccess())->toBeFalse()
        ->and(HeadcountTrend::canAccess())->toBeFalse()
        ->and(LeaveBalances::canAccess())->toBeFalse();

    Auth::login($hr);
    expect(LeaveBalances::canAccess())->toBeTrue()
        ->and(HrPermissions::hrManager())->toContain(HrPermissions::ViewReports)
        ->and(HrPermissions::hrAuditor())->toContain(HrPermissions::ViewReports)
        ->and(HrPermissions::manager())->not->toContain(HrPermissions::ViewReports);
});

it('limits the reports to the people in a scoped HR user\'s hierarchy', function () {
    $org = ocrOrg();
    $officer = ocrUser($org['company'], [HrPermissions::ViewReports], 'Scoped officer');
    $org['opsManager']->forceFill(['user_id' => $officer->id])->saveQuietly();

    $report = app(LeaveBalanceReport::class)->build($officer, $org['company']->id, 2026);

    expect(array_column($report['rows'], 'name'))->toEqualCanonicalizing(['Kamran Ops', 'Ali Clerk', 'Sara Lead', 'Iqra Intern', 'Gone Already', 'Switched Off']);
});

// ---------------------------------------------------------------------
// Excel
// ---------------------------------------------------------------------
it('exports the reports to styled Excel workbooks', function () {
    $data = ocrMovements();
    $data['people']['january']->forceFill(['name' => '=HYPERLINK("x")', 'employee_number' => 'MV-001'])->saveQuietly();
    $hr = ocrHr($data['company']);
    $excel = app(HrReportExcel::class);

    $book = $excel->joinersLeavers(app(JoinersLeaversReport::class)->build($hr, $data['company']->id, '2026-01-01', '2026-10-28'));
    $summary = $book->getSheetByName('Summary');
    $joiners = $book->getSheetByName('Joiners');
    $leavers = $book->getSheetByName('Leavers');

    expect($book->getSheetNames())->toBe(['Summary', 'Joiners', 'Leavers', 'Departure reasons'])
        ->and($summary->getCell('A1')->getValue())->toBe('Joiners & Leavers')
        ->and($summary->getCell('B2')->getValue())->toBe($data['company']->name)
        ->and($summary->getCell('B3')->getValue())->toBe('01 Jan 2026 – 28 Oct 2026')
        ->and($summary->getCell('B4')->getValue())->toBe('All departments')
        ->and($summary->getCell('A7')->getValue())->toBe('Month')
        ->and($summary->getStyle('A7')->getFont()->getBold())->toBeTrue()
        ->and($summary->getStyle('A7')->getFont()->getName())->toBe('Times New Roman')
        ->and($summary->getCell('A8')->getValue())->toBe('Jan 2026')
        ->and($summary->getCell('B8')->getValue())->toBe(1)
        ->and($summary->getCell('A18')->getValue())->toBe('Total')
        ->and($summary->getCell('C18')->getValue())->toBe(3)
        ->and($summary->getStyle('A18')->getFont()->getBold())->toBeTrue()
        ->and($joiners->getColumnDimension('B')->getWidth())->toEqual(28)
        ->and($joiners->getAutoFilter()->getRange())->toBe('A7:F9')
        // A name is text, never a formula.
        ->and($joiners->getCell('B8')->getValue())->toBe('=HYPERLINK("x")')
        ->and($joiners->getCell('B8')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($joiners->getCell('A8')->getValue())->toBe('MV-001')
        ->and($joiners->getStyle('E8')->getNumberFormat()->getFormatCode())->toBe('d-mmm-yyyy')
        ->and($leavers->getCell('B8')->getValue())->toBe('Old Timer')
        ->and($leavers->getCell('I8')->getValue())->toBe($data['retired']->name)
        ->and($leavers->getCell('G9')->getValue())->toBe('archived');

    $balances = $excel->leaveBalances(app(LeaveBalanceReport::class)->build($hr, $data['company']->id, 2026));
    $sheet = $balances->getActiveSheet();

    expect($sheet->getTitle())->toBe('Leave balances')
        ->and($sheet->getCell('B3')->getValue())->toBe('2026')
        ->and($sheet->getCell('D7')->getValue())->toBe('Annual Leave')
        ->and($sheet->getMergeCells())->toHaveKey('D7:G7')
        ->and($sheet->getCell('D8')->getValue())->toBe('Allocated')
        ->and($sheet->getCell('G8')->getValue())->toBe('Remaining')
        // Six people were employed in 2026 (the November joiner too): rows 9-14, then the totals.
        ->and($sheet->getCell('A15')->getValue())->toBe('Total');

    $headcount = $excel->headcount(app(HeadcountTrendReport::class)->build($hr, $data['company']->id, '2026-10', 12));

    expect($headcount->getSheetNames())->toBe(['Headcount', 'By department'])
        ->and($headcount->getSheet(0)->getCell('F7')->getValue())->toBe('Turnover %')
        ->and($headcount->getSheet(0)->getStyle('F8')->getNumberFormat()->getFormatCode())->toBe('0.0%')
        ->and($headcount->getSheet(1)->getCell('A7')->getValue())->toBe('Department');
});

it('renders the report pages for HR and streams the Excel download', function () {
    $data = ocrMovements();
    $hr = ocrHr($data['company']);
    Auth::login($hr);

    Livewire::test(JoinersLeavers::class)
        ->assertOk()
        ->assertSee('January Joiner')
        ->assertSee('Archived Person')
        ->set('filters.department_id', $data['ops']->id)
        ->assertDontSee('Archived Person')
        ->callAction('exportExcel')
        ->assertFileDownloaded('Joiners-Leavers-'.str_replace(' ', '-', $data['company']->name).'-2026-01-01-to-2026-10-28.xlsx');

    Livewire::test(HeadcountTrend::class)
        ->assertOk()
        ->assertSee('Headcount now')
        ->assertDontSee('Nov 2024')
        ->set('filters.months', 24)
        ->assertSee('Nov 2024')
        ->callAction('exportExcel')
        ->assertFileDownloaded();

    Livewire::test(LeaveBalances::class)
        ->assertOk()
        ->assertSee('January Joiner')
        ->assertSee('Annual Leave')
        ->callAction('exportExcel')
        ->assertFileDownloaded();
});

// ---------------------------------------------------------------------
// Speed
// ---------------------------------------------------------------------
function ocrQueries(callable $callback): int
{
    app(HrHierarchyService::class)->flushCache();
    CurrentCompany::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('keeps the number of queries flat as the number of employees grows', function () {
    $small = ocrCompany('Small');
    $large = ocrCompany('Large');

    foreach ([[$small, 3], [$large, 30]] as [$company, $size]) {
        $department = Department::query()->create(['name' => 'Team', 'company_id' => $company->id]);
        $boss = ocrEmployee($company, 'Boss', ['department_id' => $department->id]);
        for ($index = 1; $index < $size; $index++) {
            $employee = ocrEmployee($company, 'Person '.$index, ['parent_id' => $boss->id, 'department_id' => $department->id, 'joining_date' => '2026-0'.(1 + $index % 9).'-10']);
            if ($index % 3 === 0) {
                ocrLeave($employee, ocrType($company, 'Annual Leave'), '2026-11-0'.(1 + $index % 9), 1, State::VALIDATE_TWO);
            }
        }
    }

    $hr = ocrUser($small, [HrPermissions::ViewAllRecords, HrPermissions::ViewReports, 'view_employee_employee'], 'Both companies HR');
    $hr->allowedCompanies()->syncWithoutDetaching([$large->id]);
    CurrentCompany::flush();

    foreach ([
        'org chart'        => fn (Company $company) => app(OrgChartService::class)->forUser($hr, $company->id),
        'joiners leavers'  => fn (Company $company) => app(JoinersLeaversReport::class)->build($hr, $company->id, '2026-01-01', '2026-10-28'),
        'headcount'        => fn (Company $company) => app(HeadcountTrendReport::class)->build($hr, $company->id, '2026-10', 24),
        'leave balances'   => fn (Company $company) => app(LeaveBalanceReport::class)->build($hr, $company->id, 2026),
    ] as $report => $run) {
        $run($small);
        $smallQueries = ocrQueries(fn () => $run($small));
        $largeQueries = ocrQueries(fn () => $run($large));

        expect($largeQueries)->toBe($smallQueries, "{$report}: {$smallQueries} queries for 3 employees, {$largeQueries} for 30");
    }

    expect(count(app(OrgChartService::class)->forUser($hr, $large->id)['nodes']))->toBe(30)
        ->and(count(app(LeaveBalanceReport::class)->build($hr, $large->id, 2026)['rows']))->toBe(30);
});
