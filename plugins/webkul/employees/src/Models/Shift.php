<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * A shift of one company (HR parity plan 3.4): name, code, start and end
 * time, required minutes, unpaid break, flexible window and the roster
 * colour. An end time before the start time is an overnight shift
 * (22:00-06:00): the attendance day is the date it starts.
 *
 * Validated on every save. A shift used by a roster pattern or a day
 * override cannot be deleted; switch it off instead.
 */
class Shift extends Model
{
    protected $table = 'employees_shifts';

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active'        => true,
        'break_minutes'    => 0,
        'flexible_minutes' => 0,
        'sort'             => 0,
    ];

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'start_time',
        'end_time',
        'required_minutes',
        'break_minutes',
        'flexible_minutes',
        'color',
        'is_active',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'required_minutes' => 'integer',
            'break_minutes'    => 'integer',
            'flexible_minutes' => 'integer',
            'is_active'        => 'boolean',
            'sort'             => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(ShiftOverride::class, 'shift_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function startSeconds(): int
    {
        return (int) self::toMinutes($this->start_time) * 60;
    }

    /** Seconds after the start date's midnight: above 86400 for an overnight shift. */
    public function endSeconds(): int
    {
        $end = (int) self::toMinutes($this->end_time) * 60;

        return $end <= $this->startSeconds() ? $end + 86400 : $end;
    }

    public function isOvernight(): bool
    {
        return $this->endSeconds() > 86400;
    }

    public function spanMinutes(): int
    {
        return intdiv($this->endSeconds() - $this->startSeconds(), 60);
    }

    /** "22:00–06:00 (+1)" */
    public function hours(): string
    {
        return WorkSchedule::clock($this->start_time).'–'.WorkSchedule::clock($this->end_time).($this->isOvernight() ? ' (+1)' : '');
    }

    /** "Night (N) 22:00–06:00 (+1)" */
    public function label(): string
    {
        return $this->name.' ('.$this->code.') '.$this->hours();
    }

    /** Is the shift used by a roster pattern of its company or by a day override? */
    public function isInUse(): bool
    {
        if ($this->overrides()->exists()) {
            return true;
        }

        return ShiftRoster::query()
            ->where('company_id', $this->company_id)
            ->get(['id', 'pattern'])
            ->contains(fn (ShiftRoster $roster): bool => in_array((int) $this->id, $roster->shiftIds(), true));
    }

    protected static function booted(): void
    {
        static::saving(function (self $shift): void {
            $shift->validateShift();

            $shift->updated_by = Auth::id() ?? $shift->updated_by;
            if (! $shift->exists) {
                $shift->created_by ??= Auth::id();
            }
        });

        static::deleting(function (self $shift): void {
            if ($shift->isInUse()) {
                throw ValidationException::withMessages(['code' => 'This shift is used by a roster pattern or a day override. Switch it off instead of deleting it.']);
            }
        });
    }

    /** @throws ValidationException */
    protected function validateShift(): void
    {
        if (! $this->company_id) {
            throw ValidationException::withMessages(['company_id' => 'A shift belongs to a company.']);
        }

        $this->name = trim((string) $this->name);
        if ($this->name === '') {
            throw ValidationException::withMessages(['name' => 'Give the shift a name.']);
        }

        $this->code = strtoupper(trim((string) $this->code));
        if (! preg_match('/^[A-Z0-9]{1,10}$/', $this->code)) {
            throw ValidationException::withMessages(['code' => 'The code is 1 to 10 letters or digits (e.g. N for Night).']);
        }

        $duplicate = static::query()
            ->where('company_id', $this->company_id)
            ->where('code', $this->code)
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['code' => 'Another shift of this company already uses the code '.$this->code.'.']);
        }

        $start = self::toMinutes($this->start_time);
        $end = self::toMinutes($this->end_time);
        if ($start === null || $end === null) {
            throw ValidationException::withMessages(['start_time' => 'Give the start and end time as HH:MM.']);
        }
        if ($start === $end) {
            throw ValidationException::withMessages(['end_time' => 'The end time must differ from the start time (an earlier end time is an overnight shift).']);
        }
        $this->start_time = sprintf('%02d:%02d:00', intdiv($start, 60), $start % 60);
        $this->end_time = sprintf('%02d:%02d:00', intdiv($end, 60), $end % 60);

        $span = $this->spanMinutes();

        $required = (int) $this->required_minutes;
        if ($required < 1 || $required > 1440) {
            throw ValidationException::withMessages(['required_minutes' => 'Required minutes must be between 1 and 1440.']);
        }

        $break = (int) ($this->break_minutes ?? 0);
        if ($break < 0 || $break >= $span) {
            throw ValidationException::withMessages(['break_minutes' => 'The unpaid break must be shorter than the shift ('.$span.' minutes).']);
        }
        $this->break_minutes = $break;

        $flexible = (int) ($this->flexible_minutes ?? 0);
        if ($flexible < 0 || $flexible > WorkSchedule::MaxFlexibleMinutes) {
            throw ValidationException::withMessages(['flexible_minutes' => 'The flexible window must be between 0 and '.WorkSchedule::MaxFlexibleMinutes.' minutes.']);
        }
        $this->flexible_minutes = $flexible;

        $color = strtolower(trim((string) ($this->color ?: '#64748b')));
        if (! preg_match('/^#[0-9a-f]{6}$/', $color)) {
            throw ValidationException::withMessages(['color' => 'Choose a colour (#RRGGBB).']);
        }
        $this->color = $color;
        $this->is_active ??= true;
    }

    public static function toMinutes(mixed $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        return $hours <= 23 && $minutes <= 59 ? $hours * 60 + $minutes : null;
    }
}
