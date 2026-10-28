<?php

/**
 * The employee's notice when Time Off is approved or refused: type, date,
 * the time window of a half day / short leave and the approver's name,
 * sent once per decision (LeaveApprovalService::notifyDecision).
 */

use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Database\Seeders\LeaveWorkflowSeeder;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveApprovalService;

beforeEach(function () {
    config([
        'attendance.monthly_report.office_start' => '10:00',
        'attendance.monthly_report.office_end'   => '18:00',
    ]);
});

/** @return array{company: Company, managerUser: User, employeeUser: User, employee: Employee, hrUser: User} */
function ldnFixture(): array
{
    $company = Company::factory()->create(['is_active' => true]);
    app(LeaveWorkflowSeeder::class)->run();

    $managerUser = User::factory()->create(['name' => 'Sana Manager', 'default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $managerUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Sana Manager']);

    $employeeUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $employeeUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $employee = Employee::query()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id, 'parent_id' => $manager->id, 'name' => 'Leave Taker']);

    // The HR Review step (Hr_manager role), when this database's workflow has one.
    $hrUser = User::factory()->create(['name' => 'Hira HR', 'default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $hrUser->allowedCompanies()->syncWithoutDetaching([$company->id]);
    $hrUser->assignRole(Role::query()->firstOrCreate(['name' => 'Hr_manager', 'guard_name' => 'web']));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return compact('company', 'managerUser', 'employeeUser', 'employee', 'hrUser');
}

function ldnLeave(array $fixture, string $typeName, array $attributes): Leave
{
    $type = LeaveType::query()->firstOrCreate(['company_id' => $fixture['company']->id, 'name' => $typeName], ['is_active' => true]);

    return Leave::query()->create($attributes + [
        'user_id'             => $fixture['employeeUser']->id,
        'holiday_status_id'   => $type->id,
        'employee_id'         => $fixture['employee']->id,
        'employee_company_id' => $fixture['company']->id,
        'company_id'          => $fixture['company']->id,
        'creator_id'          => $fixture['employeeUser']->id,
        'state'               => State::CONFIRM,
        'request_date_from'   => '2026-11-04',
        'request_date_to'     => '2026-11-04',
        'date_from'           => '2026-11-04',
        'date_to'             => '2026-11-04',
        'number_of_days'      => 1,
    ]);
}

/** @return array<int, array{title: string, body: string}> */
function ldnNotices(User $user): array
{
    return $user->notifications()->get()
        ->filter(fn ($notification): bool => str_starts_with((string) ($notification->data['viewData']['idempotency_key'] ?? ''), 'leave_decision_'))
        ->map(fn ($notification): array => ['title' => $notification->data['title'], 'body' => $notification->data['body']])
        ->values()
        ->all();
}

/** Approves through every step of the company's workflow (line manager, then HR when configured). */
function ldnApprove(array $fixture, Leave $leave): Leave
{
    $service = app(LeaveApprovalService::class);
    $service->submit($leave, $fixture['employeeUser']);
    $leave = $service->approve($leave->fresh(), $fixture['managerUser'], 'Fine');

    if ($leave->state !== State::VALIDATE_TWO) {
        $leave = $service->approve($leave->fresh(), $fixture['hrUser'], 'Fine');
    }

    return $leave;
}

/** @return array<int, string> bodies of the final decision notices (not "forwarded for final review") */
function ldnFinalBodies(User $user): array
{
    return collect(ldnNotices($user))
        ->reject(fn (array $notice): bool => str_contains($notice['body'], 'forwarded for final review'))
        ->pluck('body')
        ->values()
        ->all();
}

it('tells the employee once that a first-half Half day was approved, with the date, window and approver', function () {
    $fixture = ldnFixture();
    $leave = ldnApprove($fixture, ldnLeave($fixture, 'Half day', ['request_unit_half' => true, 'request_date_from_period' => 'morning', 'number_of_days' => 0.5]));
    app(LeaveApprovalService::class)->synchronize($leave);

    expect($leave->state)->toBe(State::VALIDATE_TWO)
        ->and(collect(ldnNotices($fixture['employeeUser']))->sortBy('body')->values()->all())->toBe([
            [
                'title' => 'Your Half day was approved',
                'body'  => 'Your Half day on Wed, 04 Nov 2026 (first half, 10:00–14:00) was approved by Hira HR (HR).',
            ],
            [
                'title' => 'Your Half day was approved by Line Manager',
                'body'  => 'Your Half day on Wed, 04 Nov 2026 (first half, 10:00–14:00) was approved by Sana Manager (Line Manager) and forwarded for final review.',
            ],
        ]);
});

it('tells the employee that a Short leave was refused, with its from-to window, the approver and the reason', function () {
    $fixture = ldnFixture();
    $leave = ldnLeave($fixture, 'Short leave', ['request_unit_hours' => true, 'request_hour_from' => 14.5, 'request_hour_to' => 16.5, 'number_of_hours' => 2, 'number_of_days' => 0.25]);

    $service = app(LeaveApprovalService::class);
    $service->submit($leave, $fixture['employeeUser']);
    $service->reject($leave->fresh(), $fixture['managerUser'], 'Client visit');

    expect(ldnNotices($fixture['employeeUser']))->toBe([[
        'title' => 'Your Short leave was refused',
        'body'  => 'Your Short leave on Wed, 04 Nov 2026 (14:30–16:30) was refused by Sana Manager (Line Manager). Reason: Client visit',
    ]]);
});

it('words a multi-day leave as a date range and a second half as 14:00–18:00', function () {
    $fixture = ldnFixture();

    ldnApprove($fixture, ldnLeave($fixture, 'Annual Leave', ['request_date_from' => '2026-11-09', 'request_date_to' => '2026-11-11', 'date_from' => '2026-11-09', 'date_to' => '2026-11-11', 'number_of_days' => 3]));
    ldnApprove($fixture, ldnLeave($fixture, 'Half day', ['request_date_from' => '2026-11-12', 'request_date_to' => '2026-11-12', 'date_from' => '2026-11-12', 'date_to' => '2026-11-12', 'request_unit_half' => true, 'request_date_from_period' => 'afternoon', 'number_of_days' => 0.5]));

    $bodies = ldnFinalBodies($fixture['employeeUser']);

    expect($bodies)->toEqualCanonicalizing([
        'Your Annual Leave from Mon, 09 Nov 2026 to Wed, 11 Nov 2026 (3 days) was approved by Hira HR (HR).',
        'Your Half day on Thu, 12 Nov 2026 (second half, 14:00–18:00) was approved by Hira HR (HR).',
    ]);
});

it('sends no decision notice when leave_decision notifications are switched off', function () {
    config(['attendance.notifications.leave_decision' => false]);
    $fixture = ldnFixture();

    ldnApprove($fixture, ldnLeave($fixture, 'Half day', ['request_unit_half' => true, 'request_date_from_period' => 'morning', 'number_of_days' => 0.5]));

    expect(ldnNotices($fixture['employeeUser']))->toBe([]);
});
