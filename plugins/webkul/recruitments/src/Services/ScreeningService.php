<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Enums\ScreeningQuestionType;
use Webkul\Recruitment\Enums\ScreeningStatus;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\ApplicantAnswer;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Models\ScreeningQuestion;
use Webkul\Recruitment\Support\ScreeningRule;
use Webkul\Security\Models\User;
use Webkul\Support\Services\ApprovalEngine;

/**
 * Screening questions (automation plan A1): HR builds a question library and
 * per-job questions; answers come from a recruiter (phone screen), an import,
 * or later the careers website form. Every answer keeps a snapshot of the
 * question, is judged by ScreeningRule, and the application gets
 * passed / flagged ("not qualified"). Nothing is ever rejected automatically:
 * a recruiter can override a flag with a reason.
 */
class ScreeningService
{
    public function __construct(protected ApprovalEngine $approvals) {}

    /**
     * Copy library questions onto a job (each becomes an independent, editable job question).
     *
     * @param  array<int, int>  $libraryQuestionIds
     * @return Collection<int, JobQuestion>
     */
    public function addFromLibrary(JobPosition $job, array $libraryQuestionIds, User $actor): Collection
    {
        $this->assertCanManageQuestions($actor, $job->company_id);

        return DB::transaction(function () use ($job, $libraryQuestionIds, $actor): Collection {
            $nextSort = (int) JobQuestion::query()->where('job_id', $job->id)->max('sort');

            return ScreeningQuestion::query()
                ->where('company_id', $job->company_id)
                ->whereIn('id', $libraryQuestionIds)
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
                ->map(fn (ScreeningQuestion $template): JobQuestion => JobQuestion::query()->create([
                    'job_id'                => $job->id,
                    'screening_question_id' => $template->id,
                    'type'                  => $template->type,
                    'label'                 => $template->label,
                    'help_text'             => $template->help_text,
                    'options'               => $template->options,
                    'is_required'           => $template->is_required,
                    'knockout'              => $template->knockout,
                    'sort'                  => ++$nextSort,
                    'creator_id'            => $actor->id,
                ]));
        });
    }

    /**
     * Record (or replace) an application's answers and re-screen it.
     *
     * @param  array<int|string, mixed>  $answers  job question id => answer
     */
    public function recordAnswers(Applicant $applicant, array $answers, ?User $actor, string $source = 'manual'): ?ScreeningStatus
    {
        if ($actor) {
            $this->assertCanRecordAnswers($actor, $applicant);
        }

        return DB::transaction(function () use ($applicant, $answers, $actor, $source): ?ScreeningStatus {
            $questions = $this->questionsFor($applicant)->keyBy('id');

            foreach ($answers as $questionId => $answer) {
                if (! $questions->has((int) $questionId)) {
                    throw new RuntimeException('That screening question does not belong to this application\'s job.');
                }
            }

            foreach ($questions as $question) {
                if (! array_key_exists($question->id, $answers) && ! array_key_exists((string) $question->id, $answers)) {
                    continue;
                }

                $snapshot = $question->snapshot();
                $result = ScreeningRule::evaluate($snapshot, $answers[$question->id] ?? $answers[(string) $question->id]);

                ApplicantAnswer::query()->updateOrCreate(
                    ['applicant_id' => $applicant->id, 'job_question_id' => $question->id],
                    [
                        'company_id'        => $applicant->company_id,
                        'question_snapshot' => $snapshot,
                        'answer'            => $result['answer'],
                        'passed'            => $result['passed'],
                        'reason'            => $result['reason'],
                        'source'            => $source,
                        'recorded_by'       => $actor?->id,
                    ],
                );
            }

            return $this->rescreen($applicant, $actor);
        });
    }

    /**
     * Work out passed / flagged from the stored answers and the job's current
     * questions (a required question nobody answered yet leaves it unscreened).
     * An override stays until the answers change.
     */
    public function rescreen(Applicant $applicant, ?User $actor = null): ?ScreeningStatus
    {
        $questions = $this->questionsFor($applicant);
        $answers = ApplicantAnswer::query()->where('applicant_id', $applicant->id)->get();

        if ($questions->isEmpty() && $answers->isEmpty()) {
            return $this->store($applicant, null, []);
        }

        $failed = $answers->filter(fn (ApplicantAnswer $answer): bool => $answer->passed === false)
            ->map(fn (ApplicantAnswer $answer): array => ['question' => $answer->question_snapshot['label'] ?? '', 'reason' => $answer->reason])
            ->values()
            ->all();

        $answeredIds = $answers->pluck('job_question_id')->filter()->all();
        $unanswered = $questions->filter(fn (JobQuestion $question): bool => $question->is_required && ! in_array($question->id, $answeredIds, true));

        $status = match (true) {
            $failed !== []            => ScreeningStatus::FLAGGED,
            $unanswered->isNotEmpty() => null,
            default                   => ScreeningStatus::PASSED,
        };

        return $this->store($applicant, $status, $failed, $unanswered->pluck('label')->values()->all());
    }

    /** A recruiter accepts a flagged applicant anyway; the reason is kept with the application. */
    public function override(Applicant $applicant, User $actor, string $reason): Applicant
    {
        $this->assertCanRecordAnswers($actor, $applicant);

        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Give a reason for accepting this applicant despite the screening result.');
        }

        if ($applicant->screening_status !== ScreeningStatus::FLAGGED->value) {
            throw new RuntimeException('Only an applicant flagged as not qualified can be overridden.');
        }

        $properties = (array) $applicant->applicant_properties;
        $properties['screening']['override'] = [
            'by'     => $actor->id,
            'by_name'=> $actor->name,
            'at'     => now()->toIso8601String(),
            'reason' => $reason,
        ];

        $applicant->forceFill(['screening_status' => ScreeningStatus::OVERRIDDEN->value, 'applicant_properties' => $properties])->save();

        return $applicant->refresh();
    }

    /**
     * The questions a public application form (careers website) must show for a job.
     *
     * @return array<int, array{id: int, type: string, label: string, help_text: ?string, options: array<int, string>, required: bool}>
     */
    public function publicQuestionsFor(JobPosition $job): array
    {
        return JobQuestion::query()->where('job_id', $job->id)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (JobQuestion $question): array => [
                'id'        => (int) $question->id,
                'type'      => $question->type->value,
                'label'     => (string) $question->label,
                'help_text' => $question->help_text,
                'options'   => $question->type->hasOptions() ? array_values((array) $question->options) : [],
                'required'  => (bool) $question->is_required,
            ])
            ->all();
    }

    /** @return Collection<int, JobQuestion> */
    public function questionsFor(Applicant $applicant): Collection
    {
        if (! $applicant->job_id) {
            return collect();
        }

        return JobQuestion::query()
            ->where('job_id', $applicant->job_id)
            ->where('company_id', $applicant->company_id)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    public static function canManageQuestions(?User $user, mixed $companyId): bool
    {
        return $user !== null
            && $user->can(HrPermissions::ManageScreeningQuestions)
            && $companyId
            && app(ApprovalEngine::class)->userCanAccessCompany($user, (int) $companyId);
    }

    protected function assertCanManageQuestions(User $actor, mixed $companyId): void
    {
        if (! static::canManageQuestions($actor, $companyId)) {
            throw new RuntimeException('You are not allowed to manage screening questions for this company.');
        }
    }

    protected function assertCanRecordAnswers(User $actor, Applicant $applicant): void
    {
        if (! Gate::forUser($actor)->allows('update', $applicant)
            || ! $applicant->company_id
            || ! $this->approvals->userCanAccessCompany($actor, (int) $applicant->company_id)) {
            throw new RuntimeException('You are not allowed to record screening answers for this application.');
        }
    }

    /**
     * @param  array<int, array{question: string, reason: ?string}>  $failed
     * @param  array<int, string>  $unanswered
     */
    protected function store(Applicant $applicant, ?ScreeningStatus $status, array $failed, array $unanswered = []): ?ScreeningStatus
    {
        $properties = (array) $applicant->applicant_properties;
        $previousOverride = $properties['screening']['override'] ?? $properties['screening']['previous_override'] ?? null;

        $properties['screening'] = array_filter([
            'evaluated_at' => now()->toIso8601String(),
            'failed'       => $failed,
            'unanswered'   => $unanswered,
            // A changed result supersedes an earlier override; keep it for the record.
            'previous_override' => $previousOverride,
        ], fn ($value): bool => $value !== null && $value !== []);

        $applicant->forceFill([
            'screening_status'     => $status?->value,
            'applicant_properties' => $properties,
        ])->save();

        return $status;
    }

    /** Types whose must-have rule uses the question's options. */
    public static function optionTypes(): array
    {
        return [ScreeningQuestionType::SINGLE_CHOICE->value, ScreeningQuestionType::MULTI_CHOICE->value];
    }
}
