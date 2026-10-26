<?php

/**
 * ApprovalEngine::actionableRequestsFor() (dashboard action center and the
 * approval queue badge) must return exactly what filtering every pending
 * request with canAct() returns, across every routing type, while its query
 * count stays flat as the number of pending requests grows.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Database\Seeders\AttendanceWorkflowSeeder;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Recruitment\Database\Seeders\JobOpeningWorkflowSeeder;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;

function actionableUser(Company $company): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::GLOBAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user->refresh();
}

function actionableEmployee(Company $company, User $user, ?Employee $manager = null): Employee
{
    return Employee::query()->create([
        'company_id' => $company->id, 'user_id' => $user->id, 'name' => 'Emp '.Str::random(5),
        'is_active'  => true, 'employment_status' => 'active', 'parent_id' => $manager?->id,
    ]);
}

/**
 * A company with a manager M, reports of M and of another manager, and pending
 * requests of every routing kind.
 *
 * @return array{company: Company, manager: User, other: User}
 */
function actionableFixture(int $reportsPerManager = 3): array
{
    $company = Company::factory()->create(['is_active' => true]);
    app(AttendanceWorkflowSeeder::class)->provisionForCompany($company);
    app(JobOpeningWorkflowSeeder::class)->provisionForCompany($company);
    $type = EmployeeRequestType::query()->where('company_id', $company->id)->where('code', 'attendance_time_change')->firstOrFail();
    $engine = app(ApprovalEngine::class);

    $manager = actionableUser($company);
    $managerEmployee = actionableEmployee($company, $manager);
    $other = actionableUser($company);
    $otherEmployee = actionableEmployee($company, $other);

    // Requests about M's reports (subject employee chain) and the other manager's reports.
    foreach ([$managerEmployee, $otherEmployee] as $boss) {
        for ($i = 0; $i < $reportsPerManager; $i++) {
            $reportUser = actionableUser($company);
            $report = actionableEmployee($company, $reportUser, $boss);
            $subject = EmployeeRequest::query()->create([
                'company_id'   => $company->id, 'employee_id' => $report->id, 'request_type_id' => $type->id,
                'requested_by' => $reportUser->id, 'title' => 'Time change', 'status' => 'pending_approval',
            ]);
            $engine->submit($subject, $reportUser, 'attendance_time_change');

            // A job opening by the same report: routed through the requester's chain.
            $job = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Job '.Str::random(4), 'is_active' => true]);
            $engine->submit($job, $reportUser, JobPosition::APPROVAL_REQUEST_TYPE);
        }
    }

    // Named-user step and role step.
    $role = Role::query()->create(['name' => 'Approver '.Str::random(5), 'guard_name' => 'web']);
    $manager->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['named' => ['approver_user_id' => $manager->id], 'role' => ['approver_role_id' => $role->id]] as $kind => $stepColumns) {
        $workflow = ApprovalWorkflow::query()->create(['company_id' => $company->id, 'request_type' => "test_{$kind}", 'name' => "Test {$kind}", 'is_active' => true]);
        ApprovalStep::query()->create(['workflow_id' => $workflow->id, 'sequence' => 1, 'name' => $kind, 'required_approvals' => 1] + $stepColumns);
        $job = JobPosition::query()->create(['company_id' => $company->id, 'name' => "Job {$kind}", 'is_active' => true]);
        $engine->submit($job, $other, "test_{$kind}");
    }

    return ['company' => $company, 'manager' => $manager->refresh(), 'other' => $other->refresh()];
}

function actionableByLoop(User $actor, int $companyId): array
{
    $engine = app(ApprovalEngine::class);

    return ApprovalRequest::query()->where('company_id', $companyId)->where('status', 'pending')->get()
        ->filter(fn (ApprovalRequest $request): bool => $engine->canAct($request, $actor))
        ->pluck('id')->sort()->values()->all();
}

it('returns exactly the requests canAct() accepts, for every kind of approver', function () {
    $f = actionableFixture();
    $engine = app(ApprovalEngine::class);

    foreach ([$f['manager'], $f['other']] as $actor) {
        $batched = $engine->actionableRequestsFor(User::query()->find($actor->id), $f['company']->id)->pluck('id')->sort()->values()->all();

        expect($batched)->toBe(actionableByLoop(User::query()->find($actor->id), $f['company']->id));
    }

    // The manager approves their reports' requests (6) plus the named and role steps (2).
    expect($engine->actionableRequestsFor($f['manager'], $f['company']->id))->toHaveCount(8);
});

it('returns nothing for a company the actor cannot access', function () {
    $f = actionableFixture(1);
    $outsider = actionableUser(Company::factory()->create(['is_active' => true]));

    expect(app(ApprovalEngine::class)->actionableRequestsFor($outsider, $f['company']->id))->toBeEmpty();
});

it('keeps the query count flat as pending requests grow', function () {
    $count = function (int $reports): int {
        $f = actionableFixture($reports);
        $actor = User::query()->find($f['manager']->id);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ApprovalEngine::class)->actionableRequestsFor($actor, $f['company']->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $small = $count(2);
    $large = $count(8);

    expect($large - $small)->toBeLessThanOrEqual(2);
});
