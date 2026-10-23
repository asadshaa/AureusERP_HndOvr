<?php

namespace Webkul\Support\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Security\Models\User;
use Webkul\Support\Database\Factories\CalendarAttendanceFactory;

class CalendarAttendance extends Model implements Sortable
{
    use HasFactory, SortableTrait;

    protected $table = 'calendar_attendances';

    protected $fillable = [
        'sort',
        'name',
        'day_of_week',
        'day_period',
        'week_type',
        'display_type',
        'date_from',
        'date_to',
        'hour_from',
        'hour_to',
        'duration_days',
        'calendar_id',
        'creator_id',
        'resource_type',
        'resource_id',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function calendar()
    {
        return $this->belongsTo(Calendar::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Two-week calendars alternate by ISO week: 0 for an even ISO week, 1 for an
     * odd one. (Previously derived from the day of the year, which does not line
     * up with weeks.)
     */
    public static function getWeekType(\DateTime|Carbon $date): int
    {
        return Carbon::instance($date)->isoWeek() % 2 === 0 ? 0 : 1;
    }

    /**
     * Which two-week slots a row applies to: "even" -> [0], "odd" -> [1], and
     * anything else (null, "all", legacy "both") -> both weeks.
     *
     * @return array<int, int>
     */
    public static function weekSlotsFor(mixed $weekType): array
    {
        return match ($weekType instanceof \BackedEnum ? $weekType->value : $weekType) {
            'even'  => [0],
            'odd'   => [1],
            default => [0, 1],
        };
    }

    /**
     * hour_from / hour_to are strings that hold either a decimal hour ("10",
     * "10.5", legacy rows) or a clock time ("10:30" / "10:30:00", what the
     * Filament TimePicker saves). Both become minutes after midnight, so a
     * 10:30 start is no longer read as 10:00.
     */
    public static function toMinutes(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_string($value) && preg_match('/^\s*(\d{1,2}):(\d{2})(?::(\d{2}))?\s*$/', $value, $m)) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }

        return is_numeric($value) ? (int) round(((float) $value) * 60) : 0;
    }

    /** The same value as decimal hours (e.g. "10:30:00" -> 10.5). */
    public static function toDecimalHours(mixed $value): float
    {
        return self::toMinutes($value) / 60;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($calendarAttendance) {
            $calendarAttendance->creator_id ??= Auth::id();
        });
    }

    protected static function newFactory(): CalendarAttendanceFactory
    {
        return CalendarAttendanceFactory::new();
    }
}
