<?php

namespace Webkul\Employee\Support;

use Carbon\CarbonImmutable;

/**
 * Names and defaults of the appraisal forms that run on the performance
 * cycles (HR Parity 3.2): review statuses, cycle statuses, when the employee
 * sees the manager's review, participant scopes, the 1-5 rating scale and
 * the rating bands.
 */
final class AppraisalKinds
{
    /** The employee fills in the self review. */
    public const StatusSelfReview = 'self_review';

    /** The line manager (or HR when there is none) fills in the manager review. */
    public const StatusManagerReview = 'manager_review';

    /** Manager review done; HR may calibrate; the employee acknowledges once the result is shared. */
    public const StatusCompleted = 'completed';

    /** The employee acknowledged the result. */
    public const StatusAcknowledged = 'acknowledged';

    public const CycleDraft = 'draft';

    public const CycleActive = 'active';

    public const CycleClosed = 'closed';

    /** The employee sees the manager review once HR closes the cycle (default). */
    public const VisibleAfterClose = 'after_close';

    /** The employee sees the manager review as soon as it is submitted. */
    public const VisibleAfterManagerReview = 'after_manager_review';

    public const ParticipantsAll = 'all';

    public const ParticipantsDepartments = 'departments';

    public const ItemCompetency = 'competency';

    public const ItemKpi = 'kpi';

    public const GoalNextPeriod = 'next';

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::StatusSelfReview    => 'Self review',
            self::StatusManagerReview => 'Manager review',
            self::StatusCompleted     => 'Reviewed',
            self::StatusAcknowledged  => 'Acknowledged',
        ];
    }

    public static function statusLabel(?string $status): string
    {
        return self::statuses()[$status] ?? ($status ? ucfirst(str_replace('_', ' ', $status)) : '—');
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::StatusSelfReview    => 'info',
            self::StatusManagerReview => 'warning',
            self::StatusCompleted     => 'primary',
            self::StatusAcknowledged  => 'success',
            default                   => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function cycleStatuses(): array
    {
        return [
            self::CycleDraft  => 'Draft',
            self::CycleActive => 'Running',
            self::CycleClosed => 'Closed',
        ];
    }

    /** @return array<string, string> */
    public static function visibilityOptions(): array
    {
        return [
            self::VisibleAfterClose         => 'When HR closes the cycle',
            self::VisibleAfterManagerReview => 'As soon as the manager submits',
        ];
    }

    /** @return array<string, string> */
    public static function participantScopes(): array
    {
        return [
            self::ParticipantsAll         => 'All active employees',
            self::ParticipantsDepartments => 'Selected departments',
        ];
    }

    /** @return array<string, string> */
    public static function itemTypes(): array
    {
        return [
            self::ItemCompetency => 'Competency',
            self::ItemKpi        => 'KPI',
        ];
    }

    /** @return array<int, string> rating => label */
    public static function defaultRatingScale(): array
    {
        return [
            1 => 'Unsatisfactory',
            2 => 'Needs improvement',
            3 => 'Meets expectations',
            4 => 'Exceeds expectations',
            5 => 'Outstanding',
        ];
    }

    /**
     * Highest band first: a score belongs to the first band whose minimum it reaches.
     *
     * @return array<int, array{min: float, label: string, color: string}>
     */
    public static function defaultRatingBands(): array
    {
        return [
            ['min' => 4.5, 'label' => 'Outstanding', 'color' => 'success'],
            ['min' => 3.5, 'label' => 'Exceeds expectations', 'color' => 'info'],
            ['min' => 2.5, 'label' => 'Meets expectations', 'color' => 'primary'],
            ['min' => 1.5, 'label' => 'Needs improvement', 'color' => 'warning'],
            ['min' => 0, 'label' => 'Unsatisfactory', 'color' => 'danger'],
        ];
    }

    /** @return array<string, string> */
    public static function bandColors(): array
    {
        return [
            'success' => 'Green',
            'info'    => 'Blue',
            'primary' => 'Brand',
            'warning' => 'Amber',
            'danger'  => 'Red',
            'gray'    => 'Grey',
        ];
    }

    public static function timezone(): string
    {
        return ChecklistKinds::timezone();
    }

    /** Today in the companies' local time. */
    public static function today(): CarbonImmutable
    {
        return ChecklistKinds::today();
    }
}
