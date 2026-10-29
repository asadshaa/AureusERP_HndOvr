<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * An onboarding or exit checklist template of one company. Starting a
 * checklist copies its tasks, so later template edits never change a
 * checklist already running.
 *
 * @property string $type
 * @property bool $is_default
 * @property bool $is_active
 */
class ChecklistTemplate extends Model
{
    protected $table = 'employees_checklist_templates';

    protected $fillable = [
        'company_id',
        'type',
        'code',
        'name',
        'description',
        'is_default',
        'is_active',
        'creator_id',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ChecklistTemplateTask::class, 'template_id')->orderBy('sort')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The template a checklist of this type starts from: the default one first, else the oldest active one. */
    public static function preferredFor(int $companyId, string $type): ?self
    {
        return static::query()
            ->where('company_id', $companyId)
            ->where('type', $type)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    public function typeLabel(): string
    {
        return ChecklistKinds::typeLabel($this->type);
    }

    protected static function booted(): void
    {
        static::creating(function (self $template): void {
            $template->creator_id ??= Auth::id();
        });
    }
}
