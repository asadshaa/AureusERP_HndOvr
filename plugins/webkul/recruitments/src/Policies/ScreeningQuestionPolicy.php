<?php

namespace Webkul\Recruitment\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Recruitment\Models\ScreeningQuestion;
use Webkul\Recruitment\Services\ScreeningService;
use Webkul\Security\Models\User;

/** The screening question library: HR with hr_manage_screening_questions, within their company. */
class ScreeningQuestionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return ScreeningService::canManageQuestions($user, $user->default_company_id);
    }

    public function view(User $user, ScreeningQuestion $question): bool
    {
        return ScreeningService::canManageQuestions($user, $question->company_id);
    }

    public function create(User $user): bool
    {
        return ScreeningService::canManageQuestions($user, $user->default_company_id);
    }

    public function update(User $user, ScreeningQuestion $question): bool
    {
        return ScreeningService::canManageQuestions($user, $question->company_id);
    }

    public function delete(User $user, ScreeningQuestion $question): bool
    {
        return ScreeningService::canManageQuestions($user, $question->company_id);
    }

    public function deleteAny(User $user): bool
    {
        return ScreeningService::canManageQuestions($user, $user->default_company_id);
    }
}
