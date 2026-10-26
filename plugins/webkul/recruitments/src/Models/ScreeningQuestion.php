<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Webkul\Recruitment\Enums\ScreeningQuestionType;
use Webkul\Recruitment\Support\ScreeningRule;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * A reusable screening question in the company's library. HR edits these
 * freely; adding one to a job copies it (JobQuestion), so library edits never
 * change questions already asked.
 */
class ScreeningQuestion extends Model
{
    protected $table = 'recruitments_screening_questions';

    protected $fillable = [
        'company_id',
        'type',
        'label',
        'help_text',
        'options',
        'is_required',
        'knockout',
        'is_active',
        'sort',
        'creator_id',
    ];

    protected $casts = [
        'type'        => ScreeningQuestionType::class,
        'options'     => 'array',
        'knockout'    => 'array',
        'is_required' => 'boolean',
        'is_active'   => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function scopeActiveForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId)->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function ($question): void {
            $type = $question->type instanceof ScreeningQuestionType ? $question->type : ScreeningQuestionType::from((string) $question->type);
            $options = $type->hasOptions() ? array_values(array_unique(array_filter(array_map('trim', (array) $question->options), 'strlen'))) : [];
            $question->options = $options === [] ? null : $options;
            $question->knockout = ScreeningRule::normalizeKnockout($type, (array) $question->knockout, $options);
        });

        static::creating(function (ScreeningQuestion $question): void {
            $question->creator_id ??= Auth::id();
            $question->company_id ??= Auth::user()?->default_company_id;
        });
    }
}
