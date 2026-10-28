<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\DocumentType;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeDocument;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Who may see and change employee documents:
 *
 * - HR: hr_manage_employee_documents (or hr_view_employee_documents for
 *   read-only) AND the employee inside their HR scope (HrHierarchyService:
 *   company membership + hierarchy; HR roles see the whole company).
 *   Changes only in the active company, like every other HR record.
 * - The employee: their own documents of types marked "visible to
 *   employee" (read + download), and may upload one, which waits for an HR
 *   check.
 * - Line managers: nothing extra; holding view_employee_employee for their
 *   reports does not open their documents.
 */
class EmployeeDocumentPolicy
{
    use HandlesAuthorization;

    public function __construct(protected HrHierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can(HrPermissions::ManageEmployeeDocuments) || $user->can(HrPermissions::ViewEmployeeDocuments);
    }

    public function view(User $user, EmployeeDocument $document): bool
    {
        $employee = $document->employee;

        if (! $employee instanceof Employee || (int) $employee->company_id !== (int) $document->company_id) {
            return false;
        }

        if ($this->hrCanView($user, $employee)) {
            return true;
        }

        return $this->isOwner($user, $employee) && (bool) $document->documentType?->visible_to_employee;
    }

    public function download(User $user, EmployeeDocument $document): bool
    {
        return $this->view($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->can(HrPermissions::ManageEmployeeDocuments);
    }

    public function update(User $user, EmployeeDocument $document): bool
    {
        return $document->employee instanceof Employee && $this->hrCanManage($user, $document->employee);
    }

    public function delete(User $user, EmployeeDocument $document): bool
    {
        return $this->update($user, $document);
    }

    public function restore(User $user, EmployeeDocument $document): bool
    {
        return $this->update($user, $document);
    }

    public function forceDelete(User $user, EmployeeDocument $document): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }

    /** HR read access to this employee's documents. */
    public function hrCanView(User $user, Employee $employee): bool
    {
        return $this->viewAny($user) && $this->hierarchy->canManage($user, $employee);
    }

    /** HR write access: permission, HR scope and the employee's company is the active one. */
    public function hrCanManage(User $user, Employee $employee): bool
    {
        return $user->can(HrPermissions::ManageEmployeeDocuments)
            && (int) $employee->company_id === (int) CurrentCompany::id($user)
            && $this->hierarchy->canManage($user, $employee);
    }

    public function isOwner(User $user, Employee $employee): bool
    {
        return $employee->user_id !== null && (int) $employee->user_id === (int) $user->getKey();
    }

    /** May this user upload a document of this type for this employee (HR, or the employee for a visible type)? */
    public function upload(User $user, Employee $employee, ?DocumentType $type = null): bool
    {
        if ($type !== null && ((int) $type->company_id !== (int) $employee->company_id || ! $type->is_active)) {
            return false;
        }

        if ($this->hrCanManage($user, $employee)) {
            return true;
        }

        return $this->isOwner($user, $employee)
            && (int) $employee->company_id === (int) CurrentCompany::id($user)
            && ($type === null || $type->visible_to_employee);
    }

    /** May this user open the Documents tab of this employee at all? */
    public function viewForEmployee(User $user, Employee $employee): bool
    {
        return $this->hrCanView($user, $employee) || $this->isOwner($user, $employee);
    }
}
