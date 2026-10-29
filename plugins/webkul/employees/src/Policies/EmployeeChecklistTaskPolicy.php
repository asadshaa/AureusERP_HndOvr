<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeChecklistTask;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Services\ApprovalEngine;

/**
 * Who may see and work on onboarding / exit checklists:
 *
 * - HR (hr_manage_onboarding, the employee in their HR scope, active
 *   company): start and cancel checklists, and complete, skip, reopen or
 *   reassign every task.
 * - The assignee of a task, the employee's current line manager for a
 *   line-manager task, and the company's HR approvers for a task waiting
 *   in the HR queue (no assignee): complete or skip it.
 * - Line managers see their reports' checklists; an employee sees only
 *   their own tasks of the "employee" kind.
 * - Nobody acts on another company's checklists; changes only in the
 *   active company, like every other HR record.
 */
class EmployeeChecklistTaskPolicy
{
    use HandlesAuthorization;

    /** @var array<string, bool> */
    private array $hrApproverCache = [];

    public function __construct(
        protected HrHierarchyService $hierarchy,
        protected ApprovalEngine $approvals,
    ) {}

    /** HR: start / cancel this employee's checklists and manage every task. */
    public function manageEmployee(User $user, Employee $employee): bool
    {
        return $user->can(HrPermissions::ManageChecklists)
            && $employee->company_id !== null
            && (int) $employee->company_id === (int) CurrentCompany::id($user)
            && $this->hierarchy->canManage($user, $employee);
    }

    /** May open the checklist tab of this employee (HR, line managers up the tree, the employee). */
    public function viewEmployeeChecklists(User $user, Employee $employee): bool
    {
        return $employee->company_id !== null
            && CurrentCompany::canAccess((int) $employee->company_id, $user)
            && $this->hierarchy->canManage($user, $employee);
    }

    /** Every task of the employee (HR, line managers), not only the employee's own ones. */
    public function seesAllTasksOf(User $user, Employee $employee): bool
    {
        if ($this->manageEmployee($user, $employee)) {
            return true;
        }

        if ($this->isEmployeeSelf($user, $employee)) {
            return false;
        }

        return $this->viewEmployeeChecklists($user, $employee);
    }

    public function view(User $user, EmployeeChecklistTask $task): bool
    {
        $employee = $task->employee;

        if (! $employee instanceof Employee || (int) $employee->company_id !== (int) $task->company_id
            || ! CurrentCompany::canAccess((int) $task->company_id, $user)) {
            return false;
        }

        if ($this->isAssignee($user, $task) || $this->seesAllTasksOf($user, $employee)) {
            return true;
        }

        return $this->isEmployeeSelf($user, $employee) && $task->responsible_role === ChecklistKinds::RoleEmployee;
    }

    /** Done / skip. */
    public function update(User $user, EmployeeChecklistTask $task): bool
    {
        $employee = $task->employee;

        if (! $employee instanceof Employee
            || (int) $employee->company_id !== (int) $task->company_id
            || (int) $task->company_id !== (int) CurrentCompany::id($user)) {
            return false;
        }

        if ($this->manageEmployee($user, $employee) || $this->isAssignee($user, $task)) {
            return true;
        }

        if ($task->responsible_role === ChecklistKinds::RoleLineManager && $this->isLineManagerOf($user, $employee)) {
            return true;
        }

        return $task->assignee_id === null && $this->isCompanyHr($user, (int) $task->company_id);
    }

    /** Reopen, reassign, cancel: HR only. */
    public function manage(User $user, EmployeeChecklistTask $task): bool
    {
        return $task->employee instanceof Employee
            && (int) $task->employee->company_id === (int) $task->company_id
            && $this->manageEmployee($user, $task->employee);
    }

    public function isAssignee(User $user, EmployeeChecklistTask $task): bool
    {
        return $task->assignee_id !== null && (int) $task->assignee_id === (int) $user->getKey();
    }

    public function isEmployeeSelf(User $user, Employee $employee): bool
    {
        return $employee->user_id !== null && (int) $employee->user_id === (int) $user->getKey();
    }

    public function isLineManagerOf(User $user, Employee $employee): bool
    {
        if (! $employee->parent_id) {
            return false;
        }

        $managerUserId = $employee->relationLoaded('parent')
            ? $employee->parent?->user_id
            : Employee::query()->whereKey($employee->parent_id)->value('user_id');

        return $managerUserId !== null && (int) $managerUserId === (int) $user->getKey();
    }

    /**
     * One of the company's HR approvers (ApprovalEngine), who work the HR
     * queue (tasks without an assignee) company-wide. The role / permission
     * pre-check keeps the approver query away from plain employees.
     */
    public function isCompanyHr(User $user, int $companyId): bool
    {
        if (! CurrentCompany::canAccess($companyId, $user)) {
            return false;
        }

        if (! $user->can(HrPermissions::ViewAllRecords) && ! $user->hasRole(ApprovalEngine::HR_ROLE_NAMES)) {
            return false;
        }

        return $this->hrApproverCache[$companyId.':'.$user->getKey()] ??= $this->approvals->isCompanyHrApprover($user, $companyId);
    }
}
