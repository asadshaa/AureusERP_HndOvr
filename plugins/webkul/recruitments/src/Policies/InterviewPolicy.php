<?php

namespace Webkul\Recruitment\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Recruitment\Models\Interview;
use Webkul\Recruitment\Services\InterviewService;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Interviews list (Recruitment > Applications > Interviews). HR (anyone who
 * may list applications, or holds view_any_recruitment_interview) sees the
 * active company's interviews; any other user sees only the interviews they
 * are an interviewer on (or organised), without getting rights on the
 * applications themselves. Interviews are never created, edited or deleted
 * here: scheduling, changes and feedback go through InterviewService.
 */
class InterviewPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        if (app(InterviewService::class)->seesAllInterviews($user)) {
            return true;
        }

        $companyId = CurrentCompany::id($user);

        return $companyId !== null && Interview::query()
            ->where('company_id', $companyId)
            ->withParticipant($user)
            ->exists();
    }

    public function view(User $user, Interview $interview): bool
    {
        return app(InterviewService::class)->canView($user, $interview);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Interview $interview): bool
    {
        return false;
    }

    public function delete(User $user, Interview $interview): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Interview $interview): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Interview $interview): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
