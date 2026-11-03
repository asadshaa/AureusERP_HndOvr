<?php

use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\ApprovalReminderService;

function approvalEngineFixture(): array
{
    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $requester = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $firstApprover = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $secondApprover = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    foreach ([$requester, $firstApprover, $secondApprover] as $user) {
        $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
    }
    $role = Role::query()->firstOrCreate(['name' => 'finance approver', 'guard_name' => 'web']);
    $secondApprover->assignRole($role);

    $workflow = ApprovalWorkflow::query()->create([
        'company_id'    => $company->id,
        'creator_id'    => $requester->id,
        'name'          => 'High-value journal approval',
        'request_type'  => 'journal_posting',
        'minimum_amount'=> '1000.0000',
        'conditions'    => [['field' => 'department', 'operator' => 'equals', 'value' => 'Operations']],
        'priority'      => 200,
        'is_active'     => true,
    ]);
    $firstStep = $workflow->steps()->create([
        'sequence'           => 1, 'name' => 'Controller review', 'approver_user_id' => $firstApprover->id,
        'required_approvals' => 1,
    ]);
    $secondStep = $workflow->steps()->create([
        'sequence'           => 2, 'name' => 'Finance approval', 'approver_role_id' => $role->id,
        'required_approvals' => 1,
    ]);

    return compact('company', 'requester', 'firstApprover', 'secondApprover', 'workflow', 'firstStep', 'secondStep');
}

it('routes a company-scoped request through ordered user and role approval steps with audit decisions', function (): void {
    $fixture = approvalEngineFixture();
    $engine = app(ApprovalEngine::class);
    $request = $engine->submit(
        $fixture['company'],
        $fixture['requester'],
        'journal_posting',
        '2500.0000',
        ['company_id' => $fixture['company']->id, 'department' => 'Operations'],
    );

    expect($request->status)->toBe('pending')
        ->and($request->current_step_sequence)->toBe(1)
        ->and($engine->canAct($request, $fixture['firstApprover']))->toBeTrue()
        ->and($engine->canAct($request, $fixture['secondApprover']))->toBeFalse();

    $request = $engine->approve(
        $request,
        $fixture['firstApprover'],
        'Validated supporting journal',
        ['state' => 'draft'],
        ['state' => 'awaiting_finance'],
    );
    expect($request->status)->toBe('pending')
        ->and($request->current_step_sequence)->toBe(2)
        ->and($engine->canAct($request, $fixture['secondApprover']))->toBeTrue();

    $request = $engine->approve($request, $fixture['secondApprover'], 'Approved');
    expect($request->status)->toBe('approved')
        ->and($request->current_step_sequence)->toBeNull()
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->decisions)->toHaveCount(2)
        ->and($request->decisions->first()->previous_values)->toBe(['state' => 'draft'])
        ->and($request->decisions->first()->new_values)->toBe(['state' => 'awaiting_finance']);
});

it('rejects wrong-company actors and requires matching thresholds and conditions', function (): void {
    $fixture = approvalEngineFixture();
    $otherCompany = Company::factory()->create(['currency_id' => $fixture['company']->currency_id, 'is_active' => true]);
    $outsider = User::factory()->create(['default_company_id' => $otherCompany->id, 'is_active' => true]);
    $outsider->allowedCompanies()->syncWithoutDetaching([$otherCompany->id]);
    $engine = app(ApprovalEngine::class);

    expect($engine->matchingWorkflow(
        $fixture['company']->id,
        'journal_posting',
        '500.0000',
        ['department' => 'Operations'],
    ))->toBeNull();

    $request = $engine->submit(
        $fixture['company'],
        $fixture['requester'],
        'journal_posting',
        '1500.0000',
        ['company_id' => $fixture['company']->id, 'department' => 'Operations'],
    );

    expect($engine->canAct($request, $outsider))->toBeFalse()
        ->and(fn () => $engine->approve($request, $outsider))->toThrow(RuntimeException::class, 'not an approver');

    $request = $engine->reject($request, $fixture['firstApprover'], 'Supporting evidence is incomplete.');
    expect($request->status)->toBe('rejected')
        ->and($request->decisions->first()->reason)->toBe('Supporting evidence is incomplete.');
});

/**
 * Employee -> line manager, an HR manager, and a one-step "line manager"
 * workflow (request type "manager_check") in one company.
 *
 * @return array{company: Company, employeeUser: User, managerUser: User, hrUser: User}
 */
function approvalManagerFixture(): array
{
    $company = Company::factory()->create(['currency_id' => Currency::query()->where('code', 'PKR')->value('id'), 'is_active' => true]);
    $member = function (string $name, ?string $role = null) use ($company): User {
        $user = User::factory()->create(['name' => $name, 'default_company_id' => $company->id, 'is_active' => true]);
        $user->allowedCompanies()->syncWithoutDetaching([$company->id]);
        if ($role) {
            $user->assignRole(Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        }

        return $user;
    };

    $managerUser = $member('Mo Manager');
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Mo Manager']);
    $employeeUser = $member('Esa Employee');
    Employee::query()->create(['company_id' => $company->id, 'user_id' => $employeeUser->id, 'name' => 'Esa Employee', 'parent_id' => $manager->id]);
    $hrUser = $member('Hana HR', 'hr_manager');

    $workflow = ApprovalWorkflow::query()->create([
        'company_id' => $company->id, 'request_type' => 'manager_check', 'name' => 'Manager check', 'is_active' => true,
    ]);
    $workflow->steps()->create(['sequence' => 1, 'name' => 'Line Manager Review', 'hierarchy_route' => 'requester_manager', 'required_approvals' => 1]);

    return compact('company', 'employeeUser', 'managerUser', 'hrUser');
}

function approvalManagerSubmit(array $f): ApprovalRequest
{
    return app(ApprovalEngine::class)->submit($f['company'], $f['employeeUser'], 'manager_check', null, ['company_id' => (int) $f['company']->id]);
}

it('lets the company HR act when the line manager is inactive, the same people who are notified', function (): void {
    $f = approvalManagerFixture();
    $f['managerUser']->forceFill(['is_active' => false])->save();
    $engine = app(ApprovalEngine::class);

    $request = approvalManagerSubmit($f);

    expect($engine->resolveAuthorizedApprovers($request)->pluck('id')->all())->toBe([$f['hrUser']->id])
        ->and($engine->canAct($request, $f['hrUser']))->toBeTrue()
        ->and($engine->canAct($request, $f['managerUser']))->toBeFalse()
        ->and($engine->canAct($request, $f['employeeUser']))->toBeFalse()
        ->and($engine->describeCurrentApprover($request))->toStartWith('Forwarded to HR')
        ->and(app(ApprovalReminderService::class)->approversToRemind($request, $request->currentStep())->pluck('id')->all())->toBe([$f['hrUser']->id]);

    $approved = $engine->approve($request, $f['hrUser'], 'Manager has left');
    expect($approved->status)->toBe('approved')
        ->and($approved->decisions->first()->actor_id)->toBe($f['hrUser']->id);
});

it('hands a step to HR when the line manager is deactivated after it started, decided at decision time', function (): void {
    $f = approvalManagerFixture();
    $engine = app(ApprovalEngine::class);
    $request = approvalManagerSubmit($f);

    expect($engine->canAct($request, $f['managerUser']))->toBeTrue()
        ->and($engine->canAct($request, $f['hrUser']))->toBeFalse()
        ->and(fn () => $engine->approve($request->fresh(), $f['hrUser']))->toThrow(RuntimeException::class, 'not an approver');

    // The exit checklist disables the leaver's login mid-flight.
    User::query()->whereKey($f['managerUser']->id)->update(['is_active' => false]);

    $current = $request->fresh();
    expect($engine->resolveAuthorizedApprovers($current)->pluck('id')->all())->toBe([$f['hrUser']->id])
        ->and($engine->canAct($current, $f['hrUser']))->toBeTrue()
        ->and(fn () => $engine->approve($request->fresh(), $f['managerUser']->fresh()))->toThrow(RuntimeException::class, 'not an approver');

    // The stale request instance the HR user holds still decides correctly: decide() re-checks on the locked row.
    expect($engine->approve($request, $f['hrUser'])->status)->toBe('approved');
});

it('hands a step to HR when the line manager is not a member of the request company', function (): void {
    $f = approvalManagerFixture();
    $otherCompany = Company::factory()->create(['currency_id' => $f['company']->currency_id, 'is_active' => true]);
    $f['managerUser']->forceFill(['default_company_id' => $otherCompany->id])->save();
    $f['managerUser']->allowedCompanies()->sync([$otherCompany->id]);
    ApprovalEngine::flushMemo();
    $engine = app(ApprovalEngine::class);

    $request = approvalManagerSubmit($f);

    expect($engine->resolveAuthorizedApprovers($request)->pluck('id')->all())->toBe([$f['hrUser']->id])
        ->and($engine->canAct($request, $f['managerUser']))->toBeFalse()
        ->and($engine->canAct($request, $f['hrUser']))->toBeTrue()
        ->and($engine->approve($request, $f['hrUser'])->status)->toBe('approved');
});

it('refuses a second submission for the same subject while one is already pending instead of silently discarding it', function (): void {
    $fixture = approvalEngineFixture();
    $engine = app(ApprovalEngine::class);
    $first = $engine->submit(
        $fixture['company'],
        $fixture['requester'],
        'journal_posting',
        '2500.0000',
        ['company_id' => $fixture['company']->id, 'department' => 'Operations'],
    );

    // Before the fix, a second submit() for the same subject/type/company while
    // one is still pending silently returned $first untouched — this new
    // context (a different amount) was dropped with no error and no trace.
    expect(fn () => $engine->submit(
        $fixture['company'],
        $fixture['requester'],
        'journal_posting',
        '9000.0000',
        ['company_id' => $fixture['company']->id, 'department' => 'Operations'],
    ))->toThrow(RuntimeException::class, 'already exists');

    expect(ApprovalRequest::query()
        ->where('subject_type', $fixture['company']->getMorphClass())
        ->where('subject_id', $fixture['company']->id)
        ->where('request_type', 'journal_posting')
        ->count())->toBe(1)
        ->and($first->fresh()->amount)->toBe('2500.0000');
});
