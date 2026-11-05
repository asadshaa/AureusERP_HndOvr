<?php

namespace Webkul\Employee\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Webkul\Employee\Database\Factories\WorkLocationFactory;
use Webkul\Employee\Enums\WorkLocation as WorkLocationEnum;
use Webkul\Employee\Services\Attendance\GeoDistanceCalculator;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class WorkLocation extends Model
{
    use HasCustomFields, HasFactory, SoftDeletes;

    protected $table = 'employees_work_locations';

    protected $fillable = [
        'company_id',
        'creator_id',
        'name',
        'location_type',
        'location_number',
        'latitude',
        'longitude',
        'geofence_radius_meters',
        'geofence_enabled',
        'is_active',
    ];

    protected $casts = [
        'is_active'              => 'boolean',
        'location_type'          => WorkLocationEnum::class,
        'latitude'               => 'decimal:7',
        'longitude'              => 'decimal:7',
        'geofence_radius_meters' => 'integer',
        'geofence_enabled'       => 'boolean',
    ];

    private const GEOFENCE_COLUMNS = ['latitude', 'longitude', 'geofence_radius_meters', 'geofence_enabled'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeGeofenced(Builder $query): Builder
    {
        return $query->where('geofence_enabled', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNotNull('geofence_radius_meters');
    }

    /** Active, not soft-deleted, not a Home location, and a complete geofence is configured. */
    public function isGeofenceUsable(): bool
    {
        return $this->is_active
            && ! $this->trashed()
            && $this->location_type !== WorkLocationEnum::Home
            && $this->geofence_enabled
            && $this->latitude !== null
            && $this->longitude !== null
            && $this->geofence_radius_meters !== null;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($workLocation) {
            $workLocation->creator_id ??= Auth::id();
        });

        static::saving(fn (self $workLocation) => $workLocation->assertGeofenceIsValid());
    }

    /**
     * Server-side enforcement of the geofence rules, independent of any UI:
     * home locations never hold coordinates (privacy), coordinates and radius
     * must be sane, and only users allowed to manage geofences may change them.
     */
    private function assertGeofenceIsValid(): void
    {
        // A crafted request must not be able to place a workplace in a company the
        // signed-in user cannot act in (the UI select is only a convenience).
        if ($this->isDirty('company_id') && Auth::check()) {
            $user = Auth::user();
            $allowed = (int) $user->default_company_id === (int) $this->company_id
                || $user->allowedCompanies()->whereKey($this->company_id)->exists();
            if (! $allowed) {
                throw new AuthorizationException('You do not have access to this company.');
            }
        }

        if ($this->location_type === WorkLocationEnum::Home
            && ($this->latitude !== null || $this->longitude !== null || $this->geofence_enabled)) {
            throw new InvalidArgumentException('A home location cannot have a map position or check-in area. Clear those fields, or choose a different location type.');
        }

        if ($this->isDirty(self::GEOFENCE_COLUMNS) && Auth::check()
            && ! Auth::user()->can(HrPermissions::ManageAttendanceGeofences)) {
            throw new AuthorizationException('You are not allowed to change the check-in area of a workplace. Ask HR to make this change.');
        }

        if ($this->latitude !== null || $this->longitude !== null) {
            if (! app(GeoDistanceCalculator::class)->isValidCoordinate($this->latitude, $this->longitude)) {
                throw new InvalidArgumentException('The map position (latitude and longitude) is not valid. Check the numbers and try again.');
            }
        }

        if ($this->geofence_radius_meters !== null
            && ($this->geofence_radius_meters < (int) config('hr_attendance_geofence.radius_min_meters')
                || $this->geofence_radius_meters > (int) config('hr_attendance_geofence.radius_max_meters'))) {
            throw new InvalidArgumentException('The check-in area size (radius) is too small or too large. Enter a value within the allowed range.');
        }

        if ($this->geofence_enabled
            && ($this->latitude === null || $this->longitude === null || $this->geofence_radius_meters === null)) {
            throw new InvalidArgumentException('To set a check-in area, enter the latitude, longitude and radius.');
        }
    }

    protected static function newFactory(): WorkLocationFactory
    {
        return WorkLocationFactory::new();
    }
}
