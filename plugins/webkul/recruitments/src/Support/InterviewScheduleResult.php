<?php

namespace Webkul\Recruitment\Support;

use Illuminate\Support\Carbon;
use Webkul\Recruitment\Models\Interview;

/**
 * What scheduling or rescheduling an interview did: the saved interview and
 * the interviewers who already have another scheduled interview at that
 * time. Overlaps never block; the screen shows them as a warning.
 */
final class InterviewScheduleResult
{
    /**
     * @param  array<int, array{user_id: int, user_name: string, starts_at: Carbon, ends_at: Carbon}>  $overlaps
     */
    public function __construct(
        public readonly Interview $interview,
        public readonly array $overlaps = [],
    ) {}

    public function hasOverlaps(): bool
    {
        return $this->overlaps !== [];
    }

    /** "Sara Khan also has an interview 10:00-11:00 on 2 Nov 2026." lines, or null. */
    public function overlapMessage(): ?string
    {
        return self::describeOverlaps($this->overlaps);
    }

    /**
     * @param  array<int, array{user_id: int, user_name: string, starts_at: Carbon, ends_at: Carbon}>  $overlaps
     */
    public static function describeOverlaps(array $overlaps): ?string
    {
        if ($overlaps === []) {
            return null;
        }

        $timezone = Interview::displayTimezone();

        return collect($overlaps)
            ->map(fn (array $overlap): string => __(':name also has an interview :from-:to on :date.', [
                'name' => $overlap['user_name'],
                'from' => $overlap['starts_at']->copy()->setTimezone($timezone)->format('H:i'),
                'to'   => $overlap['ends_at']->copy()->setTimezone($timezone)->format('H:i'),
                'date' => $overlap['starts_at']->copy()->setTimezone($timezone)->format('j M Y'),
            ]))
            ->implode("\n");
    }
}
