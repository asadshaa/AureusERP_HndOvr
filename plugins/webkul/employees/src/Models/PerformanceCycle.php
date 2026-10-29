<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class PerformanceCycle extends Model
{
    protected $table = 'employees_performance_cycles';

    protected $fillable = [
        'company_id', 'creator_id', 'name', 'starts_on', 'ends_on', 'status', 'competency_framework', 'settings',
        'template_id', 'template_snapshot', 'self_review_due_on', 'manager_review_due_on', 'participant_scope',
        'department_ids', 'results_visibility', 'launched_at', 'launched_by', 'closed_at', 'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on'             => 'date',
            'ends_on'               => 'date',
            'competency_framework'  => 'array',
            'settings'              => 'array',
            'template_snapshot'     => 'array',
            'self_review_due_on'    => 'date',
            'manager_review_due_on' => 'date',
            'department_ids'        => 'array',
            'launched_at'           => 'datetime',
            'closed_at'             => 'datetime',
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

    public function template(): BelongsTo
    {
        return $this->belongsTo(AppraisalTemplate::class, 'template_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class, 'cycle_id');
    }

    public function isActive(): bool
    {
        return $this->status === AppraisalKinds::CycleActive;
    }

    public function isClosed(): bool
    {
        return $this->status === AppraisalKinds::CycleClosed;
    }

    /** The employee sees the manager review as soon as it is submitted (otherwise once the cycle is closed). */
    public function showsResultsAfterManagerReview(): bool
    {
        return $this->results_visibility === AppraisalKinds::VisibleAfterManagerReview;
    }

    /** "1 Jan – 31 Dec 2026" */
    public function periodLabel(): string
    {
        if (! $this->starts_on || ! $this->ends_on) {
            return '';
        }

        return $this->starts_on->format('j M').($this->starts_on->year !== $this->ends_on->year ? ' '.$this->starts_on->year : '')
            .' – '.$this->ends_on->format('j M Y');
    }

    protected static function booted(): void
    {
        static::creating(function (self $cycle): void {
            $cycle->creator_id ??= Auth::id();
        });
    }
}
