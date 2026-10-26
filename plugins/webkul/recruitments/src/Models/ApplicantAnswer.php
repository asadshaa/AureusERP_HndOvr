<?php

namespace Webkul\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Security\Models\User;

/**
 * An applicant's answer to one screening question, with the question as it
 * was asked (question_snapshot) and the must-have rule's verdict.
 */
class ApplicantAnswer extends Model
{
    protected $table = 'recruitments_applicant_answers';

    protected $fillable = [
        'company_id',
        'applicant_id',
        'job_question_id',
        'question_snapshot',
        'answer',
        'passed',
        'reason',
        'source',
        'recorded_by',
    ];

    protected $casts = [
        'question_snapshot' => 'array',
        'answer'            => 'json',
        'passed'            => 'boolean',
    ];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function jobQuestion(): BelongsTo
    {
        return $this->belongsTo(JobQuestion::class, 'job_question_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The answer as text, for lists and notifications. */
    public function displayAnswer(): string
    {
        $answer = $this->answer;

        return match (true) {
            $answer === null || $answer === ''    => '—',
            is_array($answer)                     => implode(', ', $answer),
            is_bool($answer)                      => $answer ? 'Yes' : 'No',
            default                               => (string) $answer,
        };
    }
}
