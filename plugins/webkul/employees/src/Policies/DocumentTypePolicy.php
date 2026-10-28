<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\DocumentType;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/** Document types are configured by HR (hr_manage_employee_documents), in the active company only. */
class DocumentTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can(HrPermissions::ManageEmployeeDocuments);
    }

    public function view(User $user, DocumentType $type): bool
    {
        return $this->viewAny($user) && CurrentCompany::canAccess((int) $type->company_id, $user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, DocumentType $type): bool
    {
        return $this->viewAny($user) && (int) $type->company_id === (int) CurrentCompany::id($user);
    }

    /** Only a type nobody has used yet; otherwise switch it off. */
    public function delete(User $user, DocumentType $type): bool
    {
        return $this->update($user, $type) && ! $type->documents()->withTrashed()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
