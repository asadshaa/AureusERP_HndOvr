<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * Why an application was refused. A reason with no company is shared by
 * every company (the seeded ones); reasons added on the configuration screen
 * belong to the active company only.
 */
class RefuseReason extends Model implements Sortable
{
    use SortableTrait;

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    protected $table = 'recruitments_refuse_reasons';

    protected $fillable = ['creator_id', 'company_id', 'sort', 'name', 'template', 'is_active'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** Active reasons a company's applications can be refused with: shared ones and the company's own. */
    public function scopeAvailableTo(Builder $query, ?int $companyId): Builder
    {
        return $query
            ->where($query->qualifyColumn('is_active'), true)
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull($inner->qualifyColumn('company_id'))
                ->when($companyId, fn (Builder $own): Builder => $own->orWhere($own->qualifyColumn('company_id'), $companyId)));
    }

    /**
     * Options for the refuse dialog of an application of $companyId.
     *
     * @return array<int, string>
     */
    public static function optionsFor(?int $companyId): array
    {
        return static::query()->availableTo($companyId)->orderBy('sort')->orderBy('id')->pluck('name', 'id')->all();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($refuseReason) {
            $refuseReason->creator_id ??= Auth::id();
            // Created from the screen: the active company's own reason.
            $refuseReason->company_id ??= CurrentCompany::id();
        });
    }
}
