<?php

namespace Webkul\Recruitment\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Recruitment\Models\OfferLetterTemplate;
use Webkul\Recruitment\Services\JobOfferService;
use Webkul\Security\Models\User;

/** Offer letter templates: HR of the active company (JobOfferService::canManageTemplates). */
class OfferLetterTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return app(JobOfferService::class)->canManageTemplates($user);
    }

    public function view(User $user, OfferLetterTemplate $template): bool
    {
        return app(JobOfferService::class)->canManageTemplates($user, (int) $template->company_id);
    }

    public function create(User $user): bool
    {
        return app(JobOfferService::class)->canManageTemplates($user);
    }

    public function update(User $user, OfferLetterTemplate $template): bool
    {
        return app(JobOfferService::class)->canManageTemplates($user, (int) $template->company_id);
    }

    public function delete(User $user, OfferLetterTemplate $template): bool
    {
        return app(JobOfferService::class)->canManageTemplates($user, (int) $template->company_id);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
