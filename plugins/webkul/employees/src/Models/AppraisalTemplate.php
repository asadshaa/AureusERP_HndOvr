<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * An appraisal form of one company: sections of competencies / KPIs, each
 * item with a weight and rated on the 1-5 scale, the scale's labels, the
 * rating bands of the final weighted score, optional next-period goals and
 * when the employee sees the manager's review. Launching a cycle copies
 * the template (snapshot()), so editing it never changes a running cycle.
 *
 * @property array<int, array{key: string, title: string, description: ?string, items: array<int, array{key: string, title: string, description: ?string, type: string, weight: float}>}> $sections
 * @property array<int|string, string> $rating_scale
 * @property array<int, array{min: float, label: string, color: string}> $rating_bands
 */
class AppraisalTemplate extends Model
{
    protected $table = 'employees_appraisal_templates';

    protected $fillable = [
        'company_id', 'code', 'name', 'description', 'sections', 'rating_scale', 'rating_bands',
        'include_goals', 'results_visibility', 'is_default', 'is_active', 'creator_id',
    ];

    protected function casts(): array
    {
        return [
            'sections'      => 'array',
            'rating_scale'  => 'array',
            'rating_bands'  => 'array',
            'include_goals' => 'boolean',
            'is_default'    => 'boolean',
            'is_active'     => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(PerformanceCycle::class, 'template_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The company's default active template, or its first active one. */
    public static function preferredFor(int $companyId): ?self
    {
        return self::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /** Sum of the item weights. */
    public function totalWeight(): float
    {
        return (float) collect($this->sections ?? [])
            ->flatMap(fn (array $section): array => $section['items'] ?? [])
            ->sum(fn (array $item): float => (float) ($item['weight'] ?? 0));
    }

    public function itemCount(): int
    {
        return collect($this->sections ?? [])->sum(fn (array $section): int => count($section['items'] ?? []));
    }

    /**
     * The copy a cycle keeps from launch on.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'template_id'   => $this->getKey(),
            'name'          => $this->name,
            'sections'      => self::normaliseSections($this->sections ?? []),
            'rating_scale'  => self::normaliseScale($this->rating_scale ?? []),
            'rating_bands'  => self::normaliseBands($this->rating_bands ?? []),
            'include_goals' => (bool) $this->include_goals,
        ];
    }

    /**
     * Every section and item keeps a stable key (answers are stored by item
     * key), weights are numbers >= 0, empty rows are dropped.
     *
     * @param  array<int|string, mixed>  $sections
     * @return array<int, array{key: string, title: string, description: ?string, items: array<int, array{key: string, title: string, description: ?string, type: string, weight: float}>}>
     */
    public static function normaliseSections(array $sections): array
    {
        $usedKeys = [];
        $result = [];

        $key = function (mixed $candidate) use (&$usedKeys): string {
            $candidate = Str::of((string) $candidate)->lower()->replaceMatches('/[^a-z0-9_]/', '')->limit(40, '')->toString();

            while ($candidate === '' || isset($usedKeys[$candidate])) {
                $candidate = Str::lower(Str::random(8));
            }

            $usedKeys[$candidate] = true;

            return $candidate;
        };

        foreach (array_values($sections) as $section) {
            if (! is_array($section) || blank($section['title'] ?? null)) {
                continue;
            }

            $items = [];
            foreach (array_values((array) ($section['items'] ?? [])) as $item) {
                if (! is_array($item) || blank($item['title'] ?? null)) {
                    continue;
                }

                $items[] = [
                    'key'         => $key($item['key'] ?? null),
                    'title'       => trim((string) $item['title']),
                    'description' => filled($item['description'] ?? null) ? trim((string) $item['description']) : null,
                    'type'        => array_key_exists((string) ($item['type'] ?? ''), AppraisalKinds::itemTypes()) ? (string) $item['type'] : AppraisalKinds::ItemCompetency,
                    'weight'      => max(0.0, round((float) ($item['weight'] ?? 0), 2)),
                ];
            }

            $result[] = [
                'key'         => $key($section['key'] ?? null),
                'title'       => trim((string) $section['title']),
                'description' => filled($section['description'] ?? null) ? trim((string) $section['description']) : null,
                'items'       => $items,
            ];
        }

        return $result;
    }

    /**
     * Labels of ratings 1-5; a missing label falls back to the default.
     *
     * @param  array<int|string, mixed>  $scale
     * @return array<int, string>
     */
    public static function normaliseScale(array $scale): array
    {
        $result = [];

        foreach (AppraisalKinds::defaultRatingScale() as $rating => $default) {
            $label = $scale[$rating] ?? $scale[(string) $rating] ?? null;
            $result[$rating] = filled($label) ? trim((string) $label) : $default;
        }

        return $result;
    }

    /**
     * Bands with a minimum score, highest first; the defaults when none are set.
     *
     * @param  array<int|string, mixed>  $bands
     * @return array<int, array{min: float, label: string, color: string}>
     */
    public static function normaliseBands(array $bands): array
    {
        $result = collect($bands)
            ->filter(fn (mixed $band): bool => is_array($band) && filled($band['label'] ?? null) && is_numeric($band['min'] ?? null))
            ->map(fn (array $band): array => [
                'min'   => round(min(5.0, max(0.0, (float) $band['min'])), 2),
                'label' => trim((string) $band['label']),
                'color' => array_key_exists((string) ($band['color'] ?? ''), AppraisalKinds::bandColors()) ? (string) $band['color'] : 'gray',
            ])
            ->sortByDesc('min')
            ->values()
            ->all();

        return $result !== [] ? $result : AppraisalKinds::defaultRatingBands();
    }

    protected static function booted(): void
    {
        static::creating(function (self $template): void {
            $template->creator_id ??= Auth::id();
        });

        static::saving(function (self $template): void {
            $template->sections = self::normaliseSections($template->sections ?? []);
            $template->rating_scale = self::normaliseScale($template->rating_scale ?? []);
            $template->rating_bands = self::normaliseBands($template->rating_bands ?? []);

            if (! array_key_exists($template->results_visibility, AppraisalKinds::visibilityOptions())) {
                $template->results_visibility = AppraisalKinds::VisibleAfterClose;
            }
        });

        // One default per company.
        static::saved(function (self $template): void {
            if ($template->is_default && ($template->wasRecentlyCreated || $template->wasChanged('is_default'))) {
                self::query()
                    ->where('company_id', $template->company_id)
                    ->whereKeyNot($template->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }
}
