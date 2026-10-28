<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\EmployeeCodeSequence;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/** Employee code prefix settings: company-wide HR who may create employees, active company only. */
class EmployeeCodeSequencePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('create_employee_employee') && $user->can(HrPermissions::ViewAllRecords);
    }

    public function view(User $user, EmployeeCodeSequence $sequence): bool
    {
        return $this->viewAny($user) && CurrentCompany::canAccess((int) $sequence->company_id, $user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EmployeeCodeSequence $sequence): bool
    {
        return $this->viewAny($user) && (int) $sequence->company_id === (int) CurrentCompany::id($user);
    }

    public function delete(User $user, EmployeeCodeSequence $sequence): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
