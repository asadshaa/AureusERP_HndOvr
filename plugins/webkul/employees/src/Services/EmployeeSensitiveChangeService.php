<?php

namespace Webkul\Employee\Services;

use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;

class EmployeeSensitiveChangeService
{
    private const FIELDS = [
        'identification_id',
        'passport_id',
        'ssnid',
        'sinid',
        'bank_account_id',
        'salary_grade',
        'base_salary',
        'salary_currency_id',
    ];

    public function __construct(
        protected ApprovalEngine $approvals,
        protected HrHierarchyService $hierarchy,
    ) {}

    /**
     * The Filament action that calls this is already gated on
     * hr_manage_sensitive_employee_data (->visible()), but that only hides
     * a button -- it doesn't stop this method being called directly (a
     * future API route, an Artisan command, or simply a different UI entry
     * point that forgets the check). Enforcing it here too closes that gap,
     * and mirrors EmployeeRequestService::assertRequestIntegrity()'s own
     * "HR-privileged OR within HR hierarchy scope" rule -- proven by an
     * existing test: HrPlatformTest's "enforces company team and manager
     * hierarchy and audits approved sensitive employee changes" already has
     * a plain manager, not an HR-privileged user, submitting a change for
     * their own direct report, and that has to keep working. This also
     * means an employee can submit a change for themselves (self is always
     * within one's own HrHierarchyService::visibleEmployeeIds() scope, the
     * same "self and reports" concept every other HR self-service screen in
     * this app already uses) -- it still can't take effect without a
     * sensitive_data_custodian's separate approval, so this isn't a way to
     * bypass review. A requester with neither the permission nor HR-scope
     * visibility of this employee at all -- some unrelated user, or someone
     * outside this company -- is refused.
     *
     * @param  array<string, mixed>  $changes
     */
    public function submit(Employee $employee, User $requester, array $changes): ApprovalRequest
    {
        if (! $requester->can('hr_manage_sensitive_employee_data')) {
            $this->hierarchy->assertCanManage($requester, $employee);
        }

        $changes = array_intersect_key($changes, array_flip(self::FIELDS));
        if ($changes === []) {
            throw new RuntimeException('No supported sensitive employee changes were supplied.');
        }

        return $this->approvals->submit(
            $employee,
            $requester,
            'employee_sensitive_change',
            isset($changes['base_salary']) ? (string) $changes['base_salary'] : null,
            [
                'company_id'      => (int) $employee->company_id,
                'employee_id'     => (int) $employee->id,
                'department_id'   => $employee->department_id,
                'previous_values' => $employee->only(array_keys($changes)),
                'new_values'      => $changes,
            ],
        );
    }

    public function applyApproved(ApprovalRequest $request): Employee
    {
        if ($request->request_type !== 'employee_sensitive_change' || $request->status !== 'approved') {
            throw new RuntimeException('Only approved employee sensitive-change requests can be applied.');
        }

        $employee = Employee::query()
            ->whereKey($request->subject_id)
            ->where('company_id', $request->company_id)
            ->firstOrFail();
        $changes = array_intersect_key((array) data_get($request->context, 'new_values', []), array_flip(self::FIELDS));
        $employee->update($changes);

        return $employee->fresh();
    }
}
