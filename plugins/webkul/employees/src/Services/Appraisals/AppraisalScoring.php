<?php

namespace Webkul\Employee\Services\Appraisals;

use Webkul\Employee\Models\AppraisalTemplate;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Support\AppraisalKinds;

/**
 * The form of a cycle's appraisals and how it is scored. The form is the
 * template snapshot the cycle took at launch; a cycle without one (launched
 * before appraisal templates existed) is a single "Overall rating" item.
 *
 * Score = sum(weight x rating) / sum(weight) over the rated items with a
 * weight (a plain average when no item has a weight), rounded to 2
 * decimals. Its band is the first band, highest minimum first, whose
 * minimum the score reaches.
 */
final class AppraisalScoring
{
    public const OverallKey = 'overall';

    /**
     * @return array<int, array{key: string, title: string, description: ?string, items: array<int, array{key: string, title: string, description: ?string, type: string, weight: float}>}>
     */
    public static function sections(?PerformanceCycle $cycle): array
    {
        $sections = AppraisalTemplate::normaliseSections((array) ($cycle?->template_snapshot['sections'] ?? []));
        $sections = array_values(array_filter($sections, fn (array $section): bool => $section['items'] !== []));

        if ($sections !== []) {
            return $sections;
        }

        return [[
            'key'         => 'general',
            'title'       => 'Overall',
            'description' => null,
            'items'       => [[
                'key'         => self::OverallKey,
                'title'       => 'Overall rating',
                'description' => 'Overall performance in the period.',
                'type'        => AppraisalKinds::ItemCompetency,
                'weight'      => 1.0,
            ]],
        ]];
    }

    /** @return array<string, array{key: string, title: string, description: ?string, type: string, weight: float, section: string}> */
    public static function items(?PerformanceCycle $cycle): array
    {
        $items = [];

        foreach (self::sections($cycle) as $section) {
            foreach ($section['items'] as $item) {
                $items[$item['key']] = $item + ['section' => $section['title']];
            }
        }

        return $items;
    }

    /** @return array<int, string> */
    public static function scale(?PerformanceCycle $cycle): array
    {
        return AppraisalTemplate::normaliseScale((array) ($cycle?->template_snapshot['rating_scale'] ?? []));
    }

    /** @return array<int, array{min: float, label: string, color: string}> */
    public static function bands(?PerformanceCycle $cycle): array
    {
        return AppraisalTemplate::normaliseBands((array) ($cycle?->template_snapshot['rating_bands'] ?? []));
    }

    public static function includesGoals(?PerformanceCycle $cycle): bool
    {
        return (bool) ($cycle?->template_snapshot['include_goals'] ?? false);
    }

    /**
     * @param  array<string, array{weight: float}>  $items
     * @param  array<string, mixed>  $ratings  item key => rating 1-5 (or null)
     */
    public static function weightedScore(array $items, array $ratings): ?float
    {
        $weighted = 0.0;
        $weights = 0.0;
        $plain = [];

        foreach ($items as $key => $item) {
            $rating = self::rating($ratings[$key] ?? null);

            if ($rating === null) {
                continue;
            }

            $plain[] = $rating;
            $weight = (float) ($item['weight'] ?? 0);

            if ($weight > 0) {
                $weighted += $weight * $rating;
                $weights += $weight;
            }
        }

        if ($plain === []) {
            return null;
        }

        return round($weights > 0 ? $weighted / $weights : array_sum($plain) / count($plain), 2);
    }

    /**
     * @param  array<int, array{min: float, label: string, color: string}>  $bands
     * @return array{min: float, label: string, color: string}|null
     */
    public static function band(?float $score, array $bands): ?array
    {
        if ($score === null) {
            return null;
        }

        foreach (AppraisalTemplate::normaliseBands($bands) as $band) {
            if ($score + 0.00001 >= $band['min']) {
                return $band;
            }
        }

        return null;
    }

    /** Band colour of a stored band label in this cycle. */
    public static function bandColor(?PerformanceCycle $cycle, ?string $label): string
    {
        foreach (self::bands($cycle) as $band) {
            if ($band['label'] === $label) {
                return $band['color'];
            }
        }

        return 'gray';
    }

    /** A rating 1-5, or null for anything else. */
    public static function rating(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $rating = (int) $value;

        return ((float) $value === (float) $rating && $rating >= 1 && $rating <= 5) ? $rating : null;
    }

    /**
     * Item ratings of a stored answer set.
     *
     * @param  array<string, mixed>|null  $answers
     * @return array<string, ?int>
     */
    public static function ratingsOf(?array $answers): array
    {
        return collect((array) ($answers['items'] ?? []))
            ->map(fn (mixed $answer): ?int => self::rating(is_array($answer) ? ($answer['rating'] ?? null) : null))
            ->all();
    }
}
