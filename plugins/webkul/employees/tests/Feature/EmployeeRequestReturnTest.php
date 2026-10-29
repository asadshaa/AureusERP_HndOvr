<?php

/**
 * HR parity plan 2.7 -- "Return to requester" for employee requests (loan,
 * advance, letters, claims...): the current-step approver returns it with a
 * comment, the requester is told, edits it and resubmits; approval restarts
 * at step 1 with what changed in the audit trail. Withdraw while pending or
 * returned. Authorization and company isolation.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages\ManageEmployeeRequests;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Support\EmployeeRequestKinds;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Filament\Resources\ApprovalRequestResource;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

function erReturnUser(Company $company, ?string $role = null): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    if ($role) {
        $user->assignRole(Role::query()->where('name', $role)->where('guard_name', 'web')->firstOrFail());
    }

    return $user;
}

/** @return array{company: Company, otherCompany: Company, hrUser: User, managerUser: User, employeeUser: User, employee: Employee, otherHr: User} */
function erReturnFixture(): array
{
    Auth::login(User::factory()->create(['is_active' => true]));

    $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
    $company = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);
    $otherCompany = Company::factory()->create(['currency_id' => $currency->id, 'is_active' => true]);

    $hrUser = erReturnUser($company, 'hr_manager');
    $managerUser = erReturnUser($company, 'manager');
    $manager = Employee::query()->create(['company_id' => $company->id, 'user_id' => $managerUser->id, 'name' => 'Line Manager']);

    $employeeUser = erReturnUser($company);
    $employee = Employee::query()->create([
        'company_id' => $company->id, 'user_id' => $employeeUser->id, 'parent_id' => $manager->id,
        'name'       => 'Sara Employee', 'joining_date' => '2024-03-01', 'base_salary' => '100000.0000',
    ]);

    $otherHr = erReturnUser($otherCompany, 'hr_manager');

    return compact('company', 'otherCompany', 'hrUser', 'managerUser', 'employeeUser', 'employee', 'otherHr');
}

function erReturnLoan(array $f, string $amount = '60000'): EmployeeRequest
{
    return EmployeeRequest::query()->create([
        'company_id'      => $f['company']->id,
        'employee_id'     => $f['employee']->id,
        'request_type_id' => EmployeeRequestType::query()->where('company_id', $f['company']->id)->where('code', EmployeeRequestKinds::LoanCode)->value('id'),
        'requested_by'    => $f['employeeUser']->id,
        'amount'          => $amount,
        'payload'         => ['purpose' => 'House repair', 'instalments' => '3', 'start_month' => now()->addMonth()->format('Y-m')],
    ]);
}

it('returns a loan to the employee, who edits and resubmits it; approval restarts at step 1', function (): void {
    $f = erReturnFixture();
    $service = app(EmployeeRequestService::class);
    $engine = app(ApprovalEngine::class);

    $request = erReturnLoan($f);
    $approval = $service->submit($request, $f['employeeUser']);
    $service->approve($request->fresh(), $f['managerUser'], 'OK');

    // HR (step 2) returns it.
    $returned = $service->returnToRequester($request->fresh(), $f['hrUser'], 'Spread it over 6 months');
    expect($returned->status)->toBe('returned')
        ->and($returned->approvalRequest->status)->toBe('returned')
        ->and(EmployeeRequestResource::returnComment($returned->fresh(['approvalRequest.decisions.actor'])))->toContain('Spread it over 6 months');

    // The employee is told, with a link straight to the edit form.
    $notice = $f['employeeUser']->notifications()->get()->first(fn ($n) => str_starts_with((string) ($n->data['viewData']['idempotency_key'] ?? ''), 'approval_returned_'));
    expect($notice->data['body'])->toContain('Spread it over 6 months')
        ->and(collect($notice->data['actions'])->pluck('url')->first())->toContain('employee-requests')->toContain('tableAction=edit')->toContain('tableActionRecord='.$request->id);

    // Edit and resubmit.
    $returned->update(['amount' => '50000', 'payload' => ['purpose' => 'House repair', 'instalments' => '6', 'start_month' => now()->addMonth()->format('Y-m')]]);
    expect($service->canResubmit($returned->fresh(), $f['employeeUser']))->toBeTrue();

    $resubmitted = $service->submit($returned->fresh(), $f['employeeUser']);
    $request = $request->fresh(['approvalRequest.decisions']);

    expect($resubmitted->id)->toBe($approval->id)
        ->and($request->status)->toBe('pending_approval')
        ->and($resubmitted->current_step_sequence)->toBe(1)
        ->and($resubmitted->submission_round)->toBe(2)
        ->and((string) $resubmitted->amount)->toBe('50000.0000')
        ->and($engine->canAct($resubmitted, $f['managerUser']))->toBeTrue()
        ->and($engine->canAct($resubmitted, $f['hrUser']))->toBeFalse();

    $resubmission = $request->approvalRequest->decisions->firstWhere('decision', 'resubmitted');
    expect($resubmission->actor_id)->toBe($f['employeeUser']->id)
        ->and($resubmission->previous_values['amount'])->toBe('60000.0000')
        ->and($resubmission->new_values['amount'])->toBe('50000.0000')
        ->and((int) $resubmission->new_values['payload']['instalments'])->toBe(6);

    // The line manager is notified again for the restarted step.
    expect($f['managerUser']->notifications()->get()->contains(fn ($n) => str_starts_with((string) ($n->data['viewData']['idempotency_key'] ?? ''), "approval_submit_{$approval->id}_r2_")))->toBeTrue();

    $service->approve($request->fresh(), $f['managerUser'], 'OK again');
    $approved = $service->approve($request->fresh(), $f['hrUser']);

    expect($approved->status)->toBe('approved')
        ->and($approved->payload['schedule'])->toHaveCount(6)
        ->and(ApprovalRequestResource::decisionHistoryText($approved->approvalRequest))->toContain('[RETURNED]')->toContain('[RESUBMITTED]');
});

it('lets only current-step approvers return and only the requester resubmit', function (): void {
    $f = erReturnFixture();
    $service = app(EmployeeRequestService::class);
    $request = erReturnLoan($f);
    $service->submit($request, $f['employeeUser']);

    // Step 1 is the line manager's: HR, the employee and another company's HR cannot return it.
    foreach ([$f['hrUser'], $f['employeeUser'], $f['otherHr']] as $user) {
        expect(fn () => $service->returnToRequester($request->fresh(), $user, 'No'))->toThrow(RuntimeException::class);
    }
    expect($request->fresh()->status)->toBe('pending_approval');

    $service->returnToRequester($request->fresh(), $f['managerUser'], 'Wrong amount');

    foreach ([$f['managerUser'], $f['hrUser'], $f['otherHr']] as $user) {
        expect($service->canResubmit($request->fresh(), $user))->toBeFalse()
            ->and(fn () => $service->submit($request->fresh(), $user))->toThrow(RuntimeException::class);
    }
    expect($request->fresh()->status)->toBe('returned');
});

it('shows Return, Resubmit, Edit and Withdraw only to the right people in Employee Requests', function (): void {
    $f = erReturnFixture();
    $service = app(EmployeeRequestService::class);
    $request = erReturnLoan($f);
    $service->submit($request, $f['employeeUser']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs($f['managerUser']);
    Livewire::test(ManageEmployeeRequests::class)
        ->assertTableActionVisible('return_request', $request)
        ->assertTableActionHidden('withdraw_request', $request)
        ->callTableAction('return_request', $request, ['comment' => ''])
        ->assertHasTableActionErrors(['comment' => 'required']);
    expect($request->fresh()->status)->toBe('pending_approval');

    Livewire::test(ManageEmployeeRequests::class)
        ->callTableAction('return_request', $request, ['comment' => 'Add the quotation'])
        ->assertHasNoTableActionErrors();

    expect($request->fresh()->status)->toBe('returned');

    $this->actingAs($f['managerUser']);
    Livewire::test(ManageEmployeeRequests::class)
        ->assertTableActionHidden('return_request', $request)
        ->assertTableActionHidden('resubmit_request', $request)
        ->assertTableActionHidden('edit', $request);

    $this->actingAs($f['employeeUser']);
    Livewire::test(ManageEmployeeRequests::class)
        ->assertTableActionVisible('resubmit_request', $request)
        ->assertTableActionVisible('edit', $request)
        ->assertTableActionVisible('withdraw_request', $request)
        ->assertTableColumnStateSet('current_approver', 'Returned to requester', $request)
        ->callTableAction('resubmit_request', $request);

    expect($request->fresh()->status)->toBe('pending_approval')
        ->and($request->fresh()->approvalRequest->submission_round)->toBe(2);
});

it('lets the employee withdraw a pending or returned request, not the approver', function (): void {
    $f = erReturnFixture();
    $service = app(EmployeeRequestService::class);

    $pending = erReturnLoan($f);
    $service->submit($pending, $f['employeeUser']);
    expect($service->canWithdraw($pending->fresh(), $f['managerUser']))->toBeFalse()
        ->and(fn () => $service->withdraw($pending->fresh(), $f['hrUser']))->toThrow(RuntimeException::class);

    $withdrawn = $service->withdraw($pending->fresh(), $f['employeeUser'], 'No longer needed');
    expect($withdrawn->status)->toBe('withdrawn')
        ->and($withdrawn->approvalRequest->status)->toBe('withdrawn')
        ->and(app(ApprovalEngine::class)->canAct($withdrawn->approvalRequest, $f['managerUser']))->toBeFalse()
        ->and($service->canWithdraw($withdrawn, $f['employeeUser']))->toBeFalse();

    $returned = erReturnLoan($f, '40000');
    $service->submit($returned, $f['employeeUser']);
    $service->returnToRequester($returned->fresh(), $f['managerUser'], 'Fix');
    expect($service->withdraw($returned->fresh(), $f['employeeUser'])->status)->toBe('withdrawn');

    // Never counted as pending: the Employee Requests badge only counts pending_approval.
    $this->actingAs($f['hrUser']);
    expect(EmployeeRequestResource::getNavigationBadge())->toBeNull();
});
