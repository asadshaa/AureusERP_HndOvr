<?php

namespace Webkul\Recruitment\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Recruitment\Enums\JobOfferStatus;
use Webkul\Recruitment\Models\JobOffer;
use Webkul\Recruitment\Services\JobOfferService;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Offers list (Recruitment > Applications > Offers). Recruitment users see
 * the active company's offers; anyone else (a hiring manager without
 * recruitment rights, the CEO...) only the offers they approve. Offers are
 * drafted from an application; every change goes through JobOfferService,
 * which checks again.
 */
class JobOfferPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        $service = app(JobOfferService::class);

        if ($service->seesAllOffers($user)) {
            return true;
        }

        $companyId = CurrentCompany::id($user);

        return $companyId !== null && JobOffer::query()
            ->where('company_id', $companyId)
            ->where(fn ($query) => $query
                ->where('hiring_manager_id', $user->id)
                ->orWhereHas('approvalRequest.decisions', fn ($decisions) => $decisions->where('actor_id', $user->id))
                ->orWhere(fn ($awaiting) => $awaiting->awaitingDecisionBy($user)))
            ->exists();
    }

    public function view(User $user, JobOffer $offer): bool
    {
        return app(JobOfferService::class)->canView($user, $offer);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, JobOffer $offer): bool
    {
        return app(JobOfferService::class)->canEdit($user, $offer);
    }

    /** Only a draft that never went for approval; anything later is kept for the audit trail. */
    public function delete(User $user, JobOffer $offer): bool
    {
        return $offer->status === JobOfferStatus::DRAFT
            && $offer->approval_request_id === null
            && app(JobOfferService::class)->canManage($user, $offer);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, JobOffer $offer): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, JobOffer $offer): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
