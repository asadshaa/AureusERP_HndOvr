<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class Stage extends Model implements Sortable
{
    use HasFactory;
    use SortableTrait;

    protected $table = 'recruitments_stages';

    protected $fillable = [
        'sort',
        'is_default',
        'creator_id',
        'company_id',
        'name',
        'pipeline_code',
        'legend_blocked',
        'legend_done',
        'legend_normal',
        'requirements',
        'fold',
        'hired_stage',
    ];

    protected $casts = [
        'is_default'  => 'boolean',
        'hired_stage' => 'boolean',
        'fold'        => 'boolean',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jobs()
    {
        return $this->belongsToMany(EmployeeJobPosition::class, 'recruitments_stages_jobs', 'stage_id', 'job_id');
    }

    /** Stages of a company's pipeline: the shared ones (no company) and the company's own. */
    public function scopeAvailableTo(Builder $query, ?int $companyId): Builder
    {
        return $query->where(fn (Builder $inner): Builder => $inner
            ->whereNull($inner->qualifyColumn('company_id'))
            ->when($companyId, fn (Builder $own): Builder => $own->orWhere($own->qualifyColumn('company_id'), $companyId)));
    }

    /**
     * Where a new (or reopened) application of the company starts: its
     * default stage (the company's own before a shared one), else the first
     * stage of its pipeline.
     */
    public static function defaultFor(?int $companyId): ?self
    {
        return static::query()
            ->availableTo($companyId)
            ->orderByDesc('is_default')
            ->orderByRaw('CASE WHEN company_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sort')
            ->orderBy('id')
            ->first();
    }

    /** A stage with no company is shared by every company's pipeline. */
    public function isShared(): bool
    {
        return ($this->exists ? $this->getOriginal('company_id') : $this->company_id) === null;
    }

    /**
     * Shared stages belong to every company, so only a system administrator
     * (the panel's Admin role) may change or remove them. Company-owned stages
     * follow the normal stage permissions.
     */
    public static function userManagesSharedStages(?User $user): bool
    {
        return (bool) $user?->roles->contains(fn ($role): bool => $role->isSystemRole());
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($stage) {
            $stage->creator_id ??= Auth::id();
        });

        // Server-side enforcement of the policy rule (covers bulk actions and
        // crafted requests). No signed-in user = seeders / console: allowed.
        $guardShared = function (self $stage): void {
            // Also covers turning a company stage into a shared one (company cleared).
            $touchesShared = $stage->isShared() || ($stage->isDirty('company_id') && $stage->company_id === null);

            if ($touchesShared && Auth::check() && ! static::userManagesSharedStages(Auth::user())) {
                throw new AuthorizationException('Shared pipeline stages are used by every company and can only be changed by an administrator.');
            }
        };

        static::updating($guardShared);
        static::deleting($guardShared);
    }
}
