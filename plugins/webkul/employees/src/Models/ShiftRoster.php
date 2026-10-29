<?php

namespace Webkul\Employee\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Support\Models\Company;

/**
 * A rotating roster pattern of one company (HR parity plan 3.4): an ordered
 * list of steps {shift_id (null = day off), days}, e.g. Morning x5, Off x2,
 * Evening x5, Off x2, Night x5, Off x2 (a 21-day cycle). Assigned to
 * employees or a department from a start date (ShiftRosterAssignment): day 1
 * of the cycle is the start date, and the cycle repeats.
 *
 * Validated on every save: at least one working step, every shift of the
 * same company, 1-60 days per step, a cycle of at most 366 days.
 */
class ShiftRoster extends Model
{
    protected $table = 'employees_shift_rosters';

    public const MaxStepDays = 60;

    public const MaxCycleDays = 366;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'company_id',
        'name',
        'pattern',
        'description',
        'is_active',
    ];

    /** @var array{0: string, 1: array<int, ?int>}|null the expanded cycle and the raw pattern it was built from */
    private ?array $expandedCycle = null;

    protected function casts(): array
    {
        return [
            'pattern'    => 'array',
            'cycle_days' => 'integer',
            'is_active'  => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftRosterAssignment::class, 'roster_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return array<int, array{shift_id: ?int, days: int}> */
    public function steps(): array
    {
        return array_values(array_map(fn ($step): array => [
            'shift_id' => isset($step['shift_id']) && $step['shift_id'] !== '' && $step['shift_id'] !== null ? (int) $step['shift_id'] : null,
            'days'     => (int) ($step['days'] ?? 0),
        ], array_filter((array) $this->pattern, 'is_array')));
    }

    /** @return array<int, int> the distinct shifts the pattern uses */
    public function shiftIds(): array
    {
        return array_values(array_unique(array_filter(array_column($this->steps(), 'shift_id'))));
    }

    /** @return array<int, ?int> one entry per day of the cycle: shift id or null (off) */
    public function cycle(): array
    {
        $raw = (string) ($this->attributes['pattern'] ?? '');
        if ($this->expandedCycle !== null && $this->expandedCycle[0] === $raw) {
            return $this->expandedCycle[1];
        }

        $cycle = [];
        foreach ($this->steps() as $step) {
            for ($day = 0; $day < max(0, $step['days']); $day++) {
                $cycle[] = $step['shift_id'];
            }
        }

        $this->expandedCycle = [$raw, $cycle];

        return $cycle;
    }

    /** The shift id on $date of an assignment that started on $startDate (null = day off or before the start). */
    public function shiftIdOn(string $startDate, string $date): ?int
    {
        $cycle = $this->cycle();
        if ($cycle === [] || $date < $startDate) {
            return null;
        }

        $offset = (int) CarbonImmutable::parse($startDate, 'UTC')->diffInDays(CarbonImmutable::parse($date, 'UTC'));

        return $cycle[$offset % count($cycle)];
    }

    /**
     * "M×5, Off×2, E×5, Off×2" with the given shift codes (id => code).
     *
     * @param  array<int, string>  $codes
     */
    public function label(array $codes = []): string
    {
        return implode(', ', array_map(
            fn (array $step): string => ($step['shift_id'] === null ? 'Off' : ($codes[$step['shift_id']] ?? '#'.$step['shift_id'])).'×'.$step['days'],
            $this->steps(),
        ));
    }

    protected static function booted(): void
    {
        static::saving(function (self $roster): void {
            $roster->validatePattern();

            $roster->updated_by = Auth::id() ?? $roster->updated_by;
            if (! $roster->exists) {
                $roster->created_by ??= Auth::id();
            }
        });
    }

    /** @throws ValidationException */
    protected function validatePattern(): void
    {
        if (! $this->company_id) {
            throw ValidationException::withMessages(['company_id' => 'A roster pattern belongs to a company.']);
        }

        $this->name = trim((string) $this->name);
        if ($this->name === '') {
            throw ValidationException::withMessages(['name' => 'Give the pattern a name.']);
        }

        $steps = $this->steps();
        if ($steps === []) {
            throw ValidationException::withMessages(['pattern' => 'Add at least one step (a shift or Off, and a number of days).']);
        }

        foreach ($steps as $step) {
            if ($step['days'] < 1 || $step['days'] > self::MaxStepDays) {
                throw ValidationException::withMessages(['pattern' => 'Each step lasts 1 to '.self::MaxStepDays.' days.']);
            }
        }

        $cycleDays = array_sum(array_column($steps, 'days'));
        if ($cycleDays > self::MaxCycleDays) {
            throw ValidationException::withMessages(['pattern' => 'The whole cycle can be at most '.self::MaxCycleDays.' days.']);
        }

        $shiftIds = array_values(array_unique(array_filter(array_column($steps, 'shift_id'))));
        if ($shiftIds === []) {
            throw ValidationException::withMessages(['pattern' => 'The pattern needs at least one working shift.']);
        }

        $own = Shift::query()->where('company_id', $this->company_id)->whereIn('id', $shiftIds)->count();
        if ($own !== count($shiftIds)) {
            throw ValidationException::withMessages(['pattern' => 'Every shift of the pattern must belong to the same company.']);
        }

        $this->pattern = $steps;
        $this->cycle_days = $cycleDays;
    }
}
