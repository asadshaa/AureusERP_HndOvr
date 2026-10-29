<?php

/**
 * Query-count regressions for the approval lists (performance analysis of
 * 2026-10-29: Time Off 1,196 queries for 90 leaves, Employee Requests 188 for
 * 25 rows, Approval Queue 84). Every column and row action (approve / refuse
 * / return visibility through ApprovalEngine::canAct(), withdraw and cancel
 * rules, policies) must read relations loaded once per page: a list of about
 * 30 pending rows stays around 60 queries, and 20 more rows add at most 3.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages\ManageEmployeeRequests;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Support\EmployeeRequestKinds;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Filament\Resources\ApprovalRequestResource\Pages\ListApprovalRequests;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\TimeOffResource\Pages\ListTimeOff;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveApprovalService;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-28 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function qcUser(Company $company, string $name, ?string $role = null, array $permissions = []): User
{
    $user = User::factory()->create(['name' => $name, 'default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($role) {
        $user->assignRole(Role::query()->where('name', $role)->where('guard_name', 'web')->firstOrFail());
    }
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @return array{company: Company, managerUser: User, manager: Employee, hrUser: User} */
function qcFixture(): array
{
    Auth::login(User::factory()->create(['is_active' => true]));
    $company = Company::factory()->create(['currency_id' => Currency::query()->where('code', 'PKR')->value('id'), 'is_active' => true]);

    $lists = [
        'view_any_time_off_time::off', 'view_time_off_time::off',
        'view_any_employee_employee::request', 'view_employee_employee::request',
        'view_any_support_approval::request', 'view_support_approval::request',
    ];
    $managerUser = qcUser($company, 'Mo Manager', 'manager', $lists);
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Mo Manager']);
    $hrUser = qcUser($company, 'Hana HR', 'hr_manager', $lists);

    return compact('company', 'managerUser', 'manager', 'hrUser');
}

/**
 * More employees under the manager: $leaves of them with a pending leave and
 * $requests with a pending HR letter request, submitted through the real
 * services.
 */
function qcAddRows(array $f, int $leaves, int $requests): void
{
    static $sequence = 0;
    $onDuty = LeaveType::query()->firstOrCreate(
        ['company_id' => $f['company']->id, 'name' => 'On Duty'],
        ['requires_allocation' => 'no', 'time_type' => 'other', 'request_unit' => 'day', 'leave_validation_type' => 'manager', 'allocation_validation_type' => 'manager', 'employee_requests' => 'no', 'is_active' => true],
    );
    $letterType = EmployeeRequestType::query()->where('company_id', $f['company']->id)->where('code', EmployeeRequestKinds::HrLetterCode)->firstOrFail();

    for ($i = 0; $i < max($leaves, $requests); $i++) {
        $sequence++;
        $user = qcUser($f['company'], "Employee {$sequence}");
        $employee = Employee::query()->create(['company_id' => $f['company']->id, 'user_id' => $user->id, 'parent_id' => $f['manager']->id, 'name' => "Employee {$sequence}"]);
        $date = now()->addDays(7 + $sequence)->toDateString();

        if ($i < $leaves) {
            $leave = Leave::query()->create([
                'user_id'             => $user->id, 'holiday_status_id' => $onDuty->id, 'employee_id' => $employee->id,
                'employee_company_id' => $f['company']->id, 'company_id' => $f['company']->id, 'creator_id' => $user->id,
                'state'               => State::CONFIRM, 'request_date_from' => $date, 'request_date_to' => $date,
                'date_from'           => $date, 'date_to' => $date, 'number_of_days' => 1,
            ]);
            app(LeaveApprovalService::class)->submit($leave, $user);
        }

        if ($i < $requests) {
            $request = EmployeeRequest::query()->create([
                'company_id' => $f['company']->id, 'employee_id' => $employee->id, 'request_type_id' => $letterType->id, 'requested_by' => $user->id,
                'payload'    => ['letter_type' => 'experience_letter', 'addressed_to' => 'To whom it may concern', 'letter_purpose' => 'Visa', 'needed_by' => now()->addWeek()->toDateString()],
            ]);
            app(EmployeeRequestService::class)->submit($request, $user);
        }
    }
}

/** Queries of one re-render of the list, with 50 rows a page. */
function qcQueries(Testable $list): int
{
    // The fixture's chatter messages queue their follower notifications to the
    // end of the request (app()->terminating()); a test's application never
    // ends, so every Livewire call would replay them. They are not the list's.
    (fn () => $this->terminatingCallbacks = [])->call(app());

    DB::flushQueryLog();
    DB::enableQueryLog();
    $list->call('$refresh');
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function qcList(string $page, User $actor): Testable
{
    test()->actingAs($actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return Livewire::test($page)->set('tableRecordsPerPage', 50);
}

it('keeps each approval list at a fixed number of queries, whatever the number of rows', function (string $page, string $actor, string $rows, array $first, array $more): void {
    $f = qcFixture();
    qcAddRows($f, ...$first);

    $records = fn (): array => match ($rows) {
        'leaves'   => Leave::query()->where('company_id', $f['company']->id)->get()->all(),
        'requests' => EmployeeRequest::query()->where('company_id', $f['company']->id)->get()->all(),
        'queue'    => ApprovalRequest::query()->where('company_id', $f['company']->id)->get()->all(),
    };

    $list = qcList($page, $f[$actor])->assertCanSeeTableRecords($records());
    $with30 = qcQueries($list);

    qcAddRows($f, ...$more);
    $list = qcList($page, $f[$actor])->assertCanSeeTableRecords($records());
    $with50 = qcQueries($list);

    expect(count($records()))->toBeGreaterThanOrEqual(50)
        ->and($with30)->toBeLessThanOrEqual(60)
        ->and($with50 - $with30)->toBeLessThanOrEqual(3);
})->with([
    'Time Off (line manager: approve / refuse / return on every row)' => [ListTimeOff::class, 'managerUser', 'leaves', [30, 0], [20, 0]],
    'Employee Requests (HR)'                                          => [ManageEmployeeRequests::class, 'hrUser', 'requests', [0, 30], [0, 20]],
    'Approval Queue (HR, leave and letter requests)'                  => [ListApprovalRequests::class, 'hrUser', 'queue', [15, 15], [10, 10]],
]);
