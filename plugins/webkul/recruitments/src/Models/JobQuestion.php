<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Recruitment\Enums\ScreeningQuestionType;
use Webkul\Recruitment\Support\ScreeningRule;
use Webkul\Security\Models\User;

/**
 * A screening question asked for one job (copied from the library or written
 * for the job). Its company is always the job's company.
 */
class JobQuestion extends Model
{
    protected $table = 'recruitments_job_questions';

    protected $fillable = [
        'job_id',
        'screening_question_id',
        'type',
        'label',
        'help_text',
        'options',
        'is_required',
        'knockout',
        'sort',
        'creator_id',
    ];

    protected $casts = [
        'type'        => ScreeningQuestionType::class,
        'options'     => 'array',
        'knockout'    => 'array',
        'is_required' => 'boolean',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_id');
    }

    public function libraryQuestion(): BelongsTo
    {
        return $this->belongsTo(ScreeningQuestion::class, 'screening_question_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ApplicantAnswer::class, 'job_question_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * The question as asked, stored with each answer.
     *
     * @return array{id: int, type: string, label: string, options: array<int, string>, is_required: bool, knockout: array<string, mixed>|null}
     */
    public function snapshot(): array
    {
        return [
            'id'          => (int) $this->id,
            'type'        => $this->type->value,
            'label'       => (string) $this->label,
            'options'     => array_values((array) $this->options),
            'is_required' => (bool) $this->is_required,
            'knockout'    => $this->knockout ?: null,
        ];
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

        static::saving(function (JobQuestion $question): void {
            $question->creator_id ??= Auth::id();

            $jobCompany = JobPosition::withTrashed()->whereKey($question->job_id)->value('company_id');
            if (! $jobCompany) {
                throw new RuntimeException("Set the job opening's company before adding screening questions.");
            }

            $question->company_id = (int) $jobCompany;
        });
    }
}
