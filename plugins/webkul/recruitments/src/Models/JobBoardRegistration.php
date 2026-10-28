<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Whether a company has registered its website feed with a free job board
 * (or created its free account on a manual board): one row per company and
 * channel, written only through JobBoardService. Every lookup is by company.
 */
class JobBoardRegistration extends Model
{
    protected $table = 'recruitments_job_board_registrations';

    protected $fillable = [
        'company_id',
        'channel',
        'is_registered',
        'registered_at',
        'notes',
        'updated_by',
    ];

    protected $casts = [
        'is_registered' => 'boolean',
        'registered_at' => 'date',
    ];

    protected $attributes = [
        'is_registered' => false,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($query->qualifyColumn('company_id'), $companyId);
    }
}
