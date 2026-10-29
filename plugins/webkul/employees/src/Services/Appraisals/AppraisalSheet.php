<?php

namespace Webkul\Employee\Services\Appraisals;

use Webkul\Employee\Models\PerformanceGoal;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;

/**
 * What one person may read of an appraisal, laid out for the appraisal page
 * and the printable PDF: the form's sections with the self and manager
 * ratings and comments, the scores and band, goals, calibration and the
 * acknowledgement. Anything the viewer may not see (AppraisalAccess) is
 * simply not in the result.
 */
class AppraisalSheet
{
    public function __construct(
        protected AppraisalAccess $access,
    ) {}

    /** @return array<string, mixed> */
    public function build(PerformanceReview $review, User $viewer): array
    {
        $review->loadMissing(['cycle', 'company', 'employee.department', 'employee.job', 'employee.parent', 'reviewer.user', 'calibrator', 'managerReviewer', 'nextPeriodGoals']);

        $cycle = $review->cycle;
        $own = $this->access->isOwn($viewer, $review);
        $showSelf = $this->access->canSeeSelfReview($viewer, $review);
        $showManager = $this->access->canSeeManagerReview($viewer, $review);
        $showCalibration = $showManager && $review->calibrated_at !== null && $this->access->canSeeCalibration($viewer, $review);
        $scale = AppraisalScoring::scale($cycle);
        $selfAnswers = (array) ($review->self_answers['items'] ?? []);
        $managerAnswers = (array) ($review->manager_answers['items'] ?? []);

        $answer = function (array $answers, string $key, ?string $legacyScore) use ($scale): ?array {
            $given = is_array($answers[$key] ?? null) ? $answers[$key] : [];
            $rating = AppraisalScoring::rating($given['rating'] ?? null);

            if ($rating === null && $key === AppraisalScoring::OverallKey && $legacyScore !== null) {
                return ['rating' => number_format((float) $legacyScore, 2), 'label' => null, 'comment' => null];
            }

            if ($rating === null && blank($given['comment'] ?? null)) {
                return null;
            }

            return ['rating' => $rating, 'label' => $rating !== null ? ($scale[$rating] ?? null) : null, 'comment' => $given['comment'] ?? null];
        };

        $sections = [];
        foreach (AppraisalScoring::sections($cycle) as $section) {
            $items = [];

            foreach ($section['items'] as $item) {
                $items[] = $item + [
                    'self'    => $showSelf ? $answer($selfAnswers, $item['key'], $review->self_rating) : null,
                    'manager' => $showManager ? $answer($managerAnswers, $item['key'], $review->manager_rating) : null,
                ];
            }

            $sections[] = [
                'title'       => $section['title'],
                'description' => $section['description'],
                'weight'      => array_sum(array_column($section['items'], 'weight')),
                'items'       => $items,
            ];
        }

        $band = $showManager && $review->final_band ? ['label' => $review->final_band, 'color' => AppraisalScoring::bandColor($cycle, $review->final_band)] : null;

        return [
            'review'                   => $review,
            'cycle'                    => $cycle,
            'employee'                 => $review->employee,
            'company'                  => $review->company,
            'own'                      => $own,
            'show_self'                => $showSelf,
            'show_manager'             => $showManager,
            'manager_started'          => $showManager && ($review->isReviewed() || $managerAnswers !== [] || $review->manager_rating !== null),
            'reviewed'                 => $review->isReviewed(),
            'results_pending'          => $own && $review->isReviewed() && ! $showManager,
            'status_label'             => AppraisalKinds::statusLabel($review->status),
            'status_color'             => AppraisalKinds::statusColor($review->status),
            'reviewer_label'           => $this->access->hasManager($review) ? (string) $review->reviewer?->name : 'HR (no line manager)',
            'manager_reviewed_by'      => $showManager ? $review->managerReviewer?->name : null,
            'scale'                    => $scale,
            'bands'                    => AppraisalScoring::bands($cycle),
            'sections'                 => $sections,
            'self_score'               => $showSelf && $review->self_rating !== null ? (float) $review->self_rating : null,
            'manager_score'            => $showManager && $review->manager_rating !== null ? (float) $review->manager_rating : null,
            'final_score'              => $showManager && $review->final_rating !== null ? (float) $review->final_rating : null,
            'band'                     => $band,
            'self_comments'            => $showSelf ? $review->self_comments : null,
            'self_skipped'             => $review->self_skipped_at !== null && $review->submitted_at === null,
            'manager_comments'         => $showManager ? $review->manager_comments : null,
            'improvement_plan'         => $showManager ? $review->improvement_plan : null,
            'promotion_recommendation' => $showManager && ! $own ? $review->promotion_recommendation : null,
            'goals'                    => $showManager
                ? $review->nextPeriodGoals->map(fn (PerformanceGoal $goal): array => [
                    'title'       => $goal->title,
                    'description' => $goal->description,
                    'due_date'    => $goal->due_date?->format('j M Y'),
                    'weight'      => (float) $goal->weight > 0 ? rtrim(rtrim(number_format((float) $goal->weight, 2), '0'), '.') : null,
                ])->all()
                : [],
            'calibration'      => $showCalibration ? [
                'rating'   => (float) $review->calibrated_rating,
                'original' => $review->manager_rating !== null ? (float) $review->manager_rating : null,
                'reason'   => $review->calibration_reason,
                'by'       => $review->calibrator?->name,
                'at'       => $review->calibrated_at?->timezone(AppraisalKinds::timezone())->format('j M Y H:i'),
            ] : null,
            'calibrated'              => $review->calibrated_at !== null && $showManager,
            'acknowledged_at'         => $review->acknowledged_at?->timezone(AppraisalKinds::timezone())->format('j M Y H:i'),
            'acknowledgement_comment' => $review->acknowledgement_comment,
            'submitted_at'            => $review->submitted_at?->timezone(AppraisalKinds::timezone())->format('j M Y'),
            'completed_at'            => $showManager ? $review->completed_at?->timezone(AppraisalKinds::timezone())->format('j M Y') : null,
        ];
    }
}
