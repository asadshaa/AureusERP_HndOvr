<?php

use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

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

    expect(\Webkul\Support\Models\ApprovalRequest::query()
        ->where('subject_type', $fixture['company']->getMorphClass())
        ->where('subject_id', $fixture['company']->id)
        ->where('request_type', 'journal_posting')
        ->count())->toBe(1)
        ->and($first->fresh()->amount)->toBe('2500.0000');
});
