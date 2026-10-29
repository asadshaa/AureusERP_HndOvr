<?php

namespace Webkul\Support\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;

class ApprovalWorkflow extends Model
{
    protected $table = 'support_approval_workflows';

    protected $fillable = [
        'company_id',
        'creator_id',
        'name',
        'request_type',
        'minimum_amount',
        'maximum_amount',
        'conditions',
        'priority',
        'is_active',
        'reminders_enabled',
        'remind_after_hours',
        'remind_every_hours',
        'max_reminders',
    ];

    protected function casts(): array
    {
        return [
            'minimum_amount'     => 'decimal:4',
            'maximum_amount'     => 'decimal:4',
            'conditions'         => 'array',
            'is_active'          => 'boolean',
            'reminders_enabled'  => 'boolean',
            'remind_after_hours' => 'integer',
            'remind_every_hours' => 'integer',
            'max_reminders'      => 'integer',
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

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class, 'workflow_id')->orderBy('sequence');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'workflow_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    protected static function booted(): void
    {
        static::creating(function (self $workflow): void {
            $workflow->creator_id ??= Auth::id();
        });
    }
}
