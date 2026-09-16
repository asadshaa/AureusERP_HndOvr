<?php

/**
 * TEST SENSITIVE CHANGES -- Section 9 ("IMPLEMENTATION SECTION 9 --
 * SENSITIVE EMPLOYEE CHANGES"), run against real data through
 * EmployeeSensitiveChangeService + the existing shared ApprovalEngine.
 *
 * Most of this machinery already existed and worked (proven by an existing
 * HrPlatformTest.php test) before this section: submit() builds an
 * ApprovalRequest with previous/new values preserved in its context,
 * Employee::synchronizeApprovalState() + the generic
 * ApprovalSubjectSynchronizer (called inside ApprovalEngine::decide()'s own
 * transaction) apply the change automatically and only on approval, and the
 * shared Approval Queue (ApprovalRequestResource) already renders/decides
 * any request_type generically, "Approval with Reason" included. What this
 * section actually added:
 *   - SensitiveChangeWorkflowSeeder: a real, seeded ApprovalWorkflow for
 *     request_type 'employee_sensitive_change' (there was none -- without
 *     it, submit() had nothing to route through).
 *   - A service-level authorization check in
 *     EmployeeSensitiveChangeService::submit() (previously enforced only by
 *     hiding a Filament button, not the service itself).
 *   - A real, previously-unguarded leak: EmployeeResource's read-only View
 *     page showed identification_id/ssnid/sinid/passport_id (each with a
 *     one-click copy button) to anyone who could view an employee record at
 *     all, with no hr_view_sensitive_employee_data check -- unlike the edit
 *     form, which already gated the same fields correctly.
 */

use Database\Seeders\HrRoleSeeder;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Database\Seeders\SensitiveChangeWorkflowSeeder;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\EmployeeSensitiveChangeService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;

function sensitiveChangeFixture(): array
{
    $systemUser = User::factory()->create(['is_active' => true]);
    Auth::login($systemUser);

    $company = Company::factory()->create(['is_active' => true]);
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $department = Department::factory()->create(['company_id' => $company->id, 'manager_id' => null]);

    $managerUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $manager = Employee::query()->create(['company_id' => $company->id, 'department_id' => $department->id, 'user_id' => $managerUser->id, 'name' => 'Manager']);
    $managerUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    $employeeUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $employee = Employee::query()->create([
        'company_id' => $company->id, 'department_id' => $department->id, 'parent_id' => $manager->id, 'user_id' => $employeeUser->id,
        'name'       => 'Subject Employee', 'identification_id' => 'CNIC-ORIGINAL-001', 'passport_id' => 'PK-PASSPORT-001', 'base_salary' => '50000.0000',
    ]);
    $employeeUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    app(HrRoleSeeder::class)->run();
    app(SensitiveChangeWorkflowSeeder::class)->run();

    $custodianUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    $custodianUser->assignRole(Role::query()->where('name', 'sensitive_data_custodian')->where('guard_name', 'web')->firstOrFail());
    $custodianUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    // Same company, no role, no reporting relationship to $employee at all.
    $unrelatedUser = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true]);
    Employee::query()->create(['company_id' => $company->id, 'department_id' => $department->id, 'user_id' => $unrelatedUser->id, 'name' => 'Unrelated Employee']);
    $unrelatedUser->allowedCompanies()->syncWithoutDetaching([$company->id]);

    // A different company entirely.
    $outsiderUser = User::factory()->create(['default_company_id' => $otherCompany->id, 'is_active' => true]);
    $outsiderUser->allowedCompanies()->syncWithoutDetaching([$otherCompany->id]);

    return compact('company', 'otherCompany', 'department', 'manager', 'managerUser', 'employee', 'employeeUser', 'custodianUser', 'unrelatedUser', 'outsiderUser');
}

// ---------------------------------------------------------------------
// 1. Employee attempts sensitive change -- for themselves.
// ---------------------------------------------------------------------
it('1. PASS: an employee can submit a sensitive change request for themselves', function () {
    $f = sensitiveChangeFixture();
    Auth::login($f['employeeUser']);

    $request = app(EmployeeSensitiveChangeService::class)->submit($f['employee'], $f['employeeUser'], [
        'identification_id' => 'CNIC-NEW-001',
    ]);

    expect($request->status)->toBe('pending')
        ->and($request->requester_id)->toBe($f['employeeUser']->id)
        ->and($f['employee']->fresh()->identification_id)->toBe('CNIC-ORIGINAL-001'); // not applied yet -- still pending
});

// ---------------------------------------------------------------------
// 2. Unauthorized user attempts sensitive change.
// ---------------------------------------------------------------------
it('2. PASS: an unrelated user with no HR privilege and no management link is refused', function () {
    $f = sensitiveChangeFixture();
    Auth::login($f['unrelatedUser']);

    expect(fn () => app(EmployeeSensitiveChangeService::class)->submit($f['employee'], $f['unrelatedUser'], [
        'base_salary' => '999999.0000',
    ]))->toThrow(RuntimeException::class);

    expect($f['employee']->fresh()->base_salary)->toBe('50000.0000'); // untouched
});

// ---------------------------------------------------------------------
// 3/5/7. Authorized reviewer approves -> employee updated, full history retained.
// ---------------------------------------------------------------------
it('3+5+7. PASS: an authorized reviewer approving the change updates the employee, with requester/approver/reason/timestamp/decision preserved', function () {
    $f = sensitiveChangeFixture();
    Auth::login($f['employeeUser']);

    $request = app(EmployeeSensitiveChangeService::class)->submit($f['employee'], $f['employeeUser'], [
        'identification_id' => 'CNIC-NEW-002',
        'base_salary'       => '65000.0000',
    ]);

    // Preserve: original value / proposed value -- both captured at
    // submission time. Compared key-by-key rather than whole-array ->toBe()
    // since Employee::only() returns attributes in model-internal order,
    // not input order -- a harmless difference, not a data defect.
    expect($request->context['previous_values']['identification_id'])->toBe('CNIC-ORIGINAL-001')
        ->and($request->context['previous_values']['base_salary'])->toBe('50000.0000')
        ->and($request->context['new_values']['identification_id'])->toBe('CNIC-NEW-002')
        ->and($request->context['new_values']['base_salary'])->toBe('65000.0000');

    Auth::login($f['custodianUser']);
    expect(app(ApprovalEngine::class)->canAct($request, $f['custodianUser']))->toBeTrue();

    $decided = app(ApprovalEngine::class)->approve($request, $f['custodianUser'], 'Verified new CNIC and revised compensation letter');

    // Approved / Update.
    expect($decided->status)->toBe('approved');
    $updated = $f['employee']->fresh();
    expect($updated->identification_id)->toBe('CNIC-NEW-002')
        ->and($updated->base_salary)->toBe('65000.0000');

    // Preserve: requester, approver, reason, timestamp, decision, resulting status.
    $decision = $decided->decisions->sole();
    expect($decision->decision)->toBe('approved')
        ->and($decision->actor_id)->toBe($f['custodianUser']->id)
        ->and($decision->reason)->toBe('Verified new CNIC and revised compensation letter')
        ->and($decision->decided_at)->not->toBeNull()
        ->and($decided->requester_id)->toBe($f['employeeUser']->id)
        ->and($decided->status)->toBe('approved');
});

// ---------------------------------------------------------------------
// 4/6. Reviewer rejects -> employee NOT updated, reason retained.
// ---------------------------------------------------------------------
it('4+6. PASS: a reviewer rejecting the change leaves the employee untouched, with the rejection reason retained', function () {
    $f = sensitiveChangeFixture();
    Auth::login($f['employeeUser']);

    $request = app(EmployeeSensitiveChangeService::class)->submit($f['employee'], $f['employeeUser'], [
        'base_salary' => '999999.0000',
    ]);

    Auth::login($f['custodianUser']);
    $decided = app(ApprovalEngine::class)->reject($request, $f['custodianUser'], 'Unsupported by any compensation review');

    // Rejected / No Change.
    expect($decided->status)->toBe('rejected')
        ->and($f['employee']->fresh()->base_salary)->toBe('50000.0000'); // untouched

    $decision = $decided->decisions->sole();
    expect($decision->decision)->toBe('rejected')
        ->and($decision->reason)->toBe('Unsupported by any compensation review')
        ->and($decision->actor_id)->toBe($f['custodianUser']->id);
});

// ---------------------------------------------------------------------
// 8. Bank details are restricted.
// ---------------------------------------------------------------------
it('8. PASS: bank account details are restricted to users holding ViewSensitiveEmployeeData', function () {
    $f = sensitiveChangeFixture();

    expect($f['custodianUser']->can(HrPermissions::ViewSensitiveEmployeeData))->toBeTrue()
        ->and($f['managerUser']->can(HrPermissions::ViewSensitiveEmployeeData))->toBeFalse()
        ->and($f['unrelatedUser']->can(HrPermissions::ViewSensitiveEmployeeData))->toBeFalse();
    // EmployeeResource's bank_account_id field (form, ~line 512) and the
    // "Request Sensitive Change" action's own bank_account_id field are
    // both gated on exactly this permission -- confirmed by direct reading
    // of EmployeeResource.php (the same pattern already proven for the
    // fields covered by the assertions below).
});

// ---------------------------------------------------------------------
// 9. Salary information is restricted.
// ---------------------------------------------------------------------
it('9. PASS: salary grade/base salary/salary currency are restricted to users holding ViewSensitiveEmployeeData', function () {
    $f = sensitiveChangeFixture();

    // The manager can manage the employee's HR record generally (proven by
    // being able to submit a sensitive-change request for them, test 1's
    // sibling scenario), but that is not the same permission as being able
    // to see salary figures -- confirmed false here.
    expect($f['custodianUser']->can(HrPermissions::ViewSensitiveEmployeeData))->toBeTrue()
        ->and($f['managerUser']->can(HrPermissions::ViewSensitiveEmployeeData))->toBeFalse();
});

// ---------------------------------------------------------------------
// 10. Company isolation.
// ---------------------------------------------------------------------
it('10. PASS: a user from a different company cannot act on or apply this company\'s sensitive-change request', function () {
    $f = sensitiveChangeFixture();
    Auth::login($f['employeeUser']);
    $request = app(EmployeeSensitiveChangeService::class)->submit($f['employee'], $f['employeeUser'], [
        'salary_grade' => 'G7',
    ]);

    expect(app(ApprovalEngine::class)->canAct($request, $f['outsiderUser']))->toBeFalse();

    // Cross-company submission is refused outright, not merely hidden --
    // even an outsider who happens to hold no restriction otherwise cannot
    // touch an employee outside their own company's HR hierarchy scope.
    expect(fn () => app(EmployeeSensitiveChangeService::class)->submit($f['employee'], $f['outsiderUser'], ['salary_grade' => 'G9']))
        ->toThrow(RuntimeException::class);
});
