<?php

/**
 * Screening questions (docs/RECRUITMENT_AUTOMATION_PLAN.md, phase A1): an
 * HR-editable question library, per-job questions HR can customise, answers
 * judged by must-have rules with a snapshot of the question as asked, a
 * passed / not-qualified result that never auto-rejects, and a recorded
 * recruiter override.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Database\Seeders\ScreeningQuestionSeeder;
use Webkul\Recruitment\Enums\ScreeningQuestionType;
use Webkul\Recruitment\Enums\ScreeningStatus;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\Pages\ViewJobPosition;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\RelationManagers\JobQuestionsRelationManager;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\ScreeningQuestionResource\Pages\ManageScreeningQuestions;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\ApplicantAnswer;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Models\ScreeningQuestion;
use Webkul\Recruitment\Services\ApplicantIntakeService;
use Webkul\Recruitment\Services\ScreeningService;
use Webkul\Recruitment\Support\ScreeningRule;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/Support/JobOpeningHelpers.php';

/** @return array{company: Company, job: JobPosition, hr: User, applicant: Applicant} */
function screeningFixture(): array
{
    Auth::login(User::factory()->create(['is_active' => true]));
    $company = Company::factory()->create(['is_active' => true]);
    $job = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Fleet Dispatcher', 'is_active' => true]);
    $hr = jobOpeningRoleUser($company, 'recruiter');
    $partner = Partner::query()->create(['name' => 'Ali Raza', 'sub_type' => 'individual', 'company_id' => $company->id]);
    $candidate = Candidate::query()->create(['company_id' => $company->id, 'name' => 'Ali Raza', 'email_from' => 'ali@example.test', 'partner_id' => $partner->id]);
    $applicant = Applicant::query()->create(['company_id' => $company->id, 'candidate_id' => $candidate->id, 'job_id' => $job->id, 'create_date' => now()]);

    return compact('company', 'job', 'hr', 'applicant');
}

function screeningQuestion(JobPosition $job, array $attributes): JobQuestion
{
    return JobQuestion::query()->create($attributes + ['job_id' => $job->id, 'is_required' => true, 'sort' => JobQuestion::query()->where('job_id', $job->id)->count() + 1]);
}

// ---------------------------------------------------------------------
// Rules
// ---------------------------------------------------------------------

it('judges every answer type against its must-have rule', function () {
    $yesNo = ['type' => 'yes_no', 'is_required' => true, 'knockout' => ['required_answer' => 'yes']];
    expect(ScreeningRule::evaluate($yesNo, 'yes')['passed'])->toBeTrue()
        ->and(ScreeningRule::evaluate($yesNo, true)['passed'])->toBeTrue()
        ->and(ScreeningRule::evaluate($yesNo, 'No'))->toMatchArray(['passed' => false, 'reason' => 'Answered No; this job needs Yes'])
        ->and(ScreeningRule::evaluate($yesNo, 'maybe')['passed'])->toBeFalse()
        ->and(ScreeningRule::evaluate($yesNo, '')['reason'])->toBe('Not answered');

    $number = ['type' => 'number', 'knockout' => ['min' => 2, 'max' => 10]];
    expect(ScreeningRule::evaluate($number, '3')['passed'])->toBeTrue()
        ->and(ScreeningRule::evaluate($number, 1)['reason'])->toBe('Answered 1; minimum is 2')
        ->and(ScreeningRule::evaluate($number, 12)['reason'])->toBe('Answered 12; maximum is 10')
        ->and(ScreeningRule::evaluate($number, 'abc')['passed'])->toBeFalse();

    $single = ['type' => 'single_choice', 'options' => ['Immediately', '1 month', '3 months'], 'knockout' => ['accepted' => ['Immediately', '1 month']]];
    expect(ScreeningRule::evaluate($single, '1 month')['passed'])->toBeTrue()
        ->and(ScreeningRule::evaluate($single, '3 months')['passed'])->toBeFalse()
        ->and(ScreeningRule::evaluate($single, 'Tomorrow')['reason'])->toBe('Not one of the offered options');

    $multi = ['type' => 'multi_choice', 'options' => ['Urdu', 'English', 'Punjabi'], 'knockout' => ['must_include' => ['English']]];
    expect(ScreeningRule::evaluate($multi, ['Urdu', 'English'])['passed'])->toBeTrue()
        ->and(ScreeningRule::evaluate($multi, ['Urdu'])['reason'])->toBe('Missing: English');

    expect(ScreeningRule::evaluate(['type' => 'text', 'is_required' => false], 'Anything')['passed'])->toBeNull()
        ->and(ScreeningRule::evaluate(['type' => 'number', 'is_required' => false], null)['passed'])->toBeNull();
});

it('keeps only the rule that fits the question type and drops options that no longer exist', function () {
    $f = screeningFixture();
    $question = screeningQuestion($f['job'], [
        'type'     => ScreeningQuestionType::SINGLE_CHOICE, 'label' => 'Notice period?',
        'options'  => [' Immediately ', '1 month', '', '1 month'],
        'knockout' => ['accepted' => ['Immediately', 'Gone'], 'required_answer' => 'yes', 'min' => 3],
    ]);

    expect($question->refresh()->options)->toBe(['Immediately', '1 month'])
        ->and($question->knockout)->toBe(['accepted' => ['Immediately']]);

    $question->update(['type' => ScreeningQuestionType::TEXT]);
    expect($question->refresh()->knockout)->toBeNull()->and($question->options)->toBeNull();
});

// ---------------------------------------------------------------------
// Library and per-job questions (HR customises both)
// ---------------------------------------------------------------------

it('gives every new company an editable starter library, without overwriting HR changes', function () {
    $company = Company::factory()->create(['is_active' => true]);
    $library = ScreeningQuestion::query()->where('company_id', $company->id)->get();

    expect($library)->toHaveCount(5)
        ->and($library->firstWhere('type', ScreeningQuestionType::YES_NO)->knockout)->toBe(['required_answer' => 'yes']);

    $library->first()->update(['help_text' => 'HR wording']);
    app(ScreeningQuestionSeeder::class)->provisionForCompany($company);

    expect(ScreeningQuestion::query()->where('company_id', $company->id)->count())->toBe(5)
        ->and($library->first()->refresh()->help_text)->toBe('HR wording');
});

it('copies library questions onto a job as independent questions HR can edit per job', function () {
    $f = screeningFixture();
    $library = ScreeningQuestion::query()->where('company_id', $f['company']->id)->orderBy('sort')->take(2)->get();
    $foreign = ScreeningQuestion::query()->where('company_id', '!=', $f['company']->id)->value('id');

    $added = app(ScreeningService::class)->addFromLibrary($f['job'], [...$library->pluck('id')->all(), $foreign], $f['hr']);

    expect($added)->toHaveCount(2)
        ->and($added->pluck('screening_question_id')->all())->toBe($library->pluck('id')->all());

    // Editing the job's copy leaves the library alone, and vice versa.
    $added->first()->update(['label' => 'Can you work from our Karachi depot?']);
    $library->first()->update(['label' => 'Library wording changed']);

    expect($library->first()->refresh()->label)->toBe('Library wording changed')
        ->and($added->first()->refresh()->label)->toBe('Can you work from our Karachi depot?');
});

it('lets only HR with the screening permission in that company manage questions', function () {
    $f = screeningFixture();
    $viewer = jobOpeningUser($f['company'], 'view_recruitment_job::position');
    $outsider = jobOpeningRoleUser(Company::factory()->create(['is_active' => true]), 'recruiter');

    expect(fn () => app(ScreeningService::class)->addFromLibrary($f['job'], [1], $viewer))->toThrow(RuntimeException::class, 'not allowed')
        ->and(fn () => app(ScreeningService::class)->addFromLibrary($f['job'], [1], $outsider))->toThrow(RuntimeException::class, 'not allowed')
        ->and(jobOpeningRoleUser($f['company'], 'hiring_manager')->can(HrPermissions::ManageScreeningQuestions))->toBeTrue()
        ->and(jobOpeningRoleUser($f['company'], 'hr_auditor')->can(HrPermissions::ManageScreeningQuestions))->toBeFalse();
});

// ---------------------------------------------------------------------
// Answers, result and override
// ---------------------------------------------------------------------

it('flags an applicant who only wants remote work for an on-site job, and passes one who meets every rule', function () {
    $f = screeningFixture();
    $onSite = screeningQuestion($f['job'], ['type' => ScreeningQuestionType::YES_NO, 'label' => 'Can you work on-site in Karachi?', 'knockout' => ['required_answer' => 'yes']]);
    $years = screeningQuestion($f['job'], ['type' => ScreeningQuestionType::NUMBER, 'label' => 'Years of experience', 'knockout' => ['min' => 2]]);
    $service = app(ScreeningService::class);

    $status = $service->recordAnswers($f['applicant'], [$onSite->id => 'no', $years->id => 5], $f['hr']);

    expect($status)->toBe(ScreeningStatus::FLAGGED)
        ->and($f['applicant']->refresh()->screening_status)->toBe('flagged')
        ->and($f['applicant']->applicant_properties['screening']['failed'][0])->toEqualCanonicalizing(['question' => 'Can you work on-site in Karachi?', 'reason' => 'Answered No; this job needs Yes'])
        ->and(Applicant::query()->whereKey($f['applicant']->id)->exists())->toBeTrue();

    // Answering again replaces the answer (no duplicates) and re-screens.
    expect($service->recordAnswers($f['applicant'], [$onSite->id => 'yes'], $f['hr']))->toBe(ScreeningStatus::PASSED)
        ->and(ApplicantAnswer::query()->where('applicant_id', $f['applicant']->id)->count())->toBe(2);
});

it('waits for required answers before passing, and refuses a question from another job', function () {
    $f = screeningFixture();
    $first = screeningQuestion($f['job'], ['type' => ScreeningQuestionType::YES_NO, 'label' => 'Licence?']);
    screeningQuestion($f['job'], ['type' => ScreeningQuestionType::NUMBER, 'label' => 'Years?']);
    $otherJob = JobPosition::query()->create(['company_id' => $f['company']->id, 'name' => 'Other', 'is_active' => true]);
    $foreign = screeningQuestion($otherJob, ['type' => ScreeningQuestionType::TEXT, 'label' => 'Other job question']);

    expect(app(ScreeningService::class)->recordAnswers($f['applicant'], [$first->id => 'yes'], $f['hr']))->toBeNull()
        ->and($f['applicant']->refresh()->applicant_properties['screening']['unanswered'])->toBe(['Years?']);

    expect(fn () => app(ScreeningService::class)->recordAnswers($f['applicant'], [$foreign->id => 'x'], $f['hr']))
        ->toThrow(RuntimeException::class, 'does not belong');
});

it('keeps the question as it was asked when HR edits or deletes it later', function () {
    $f = screeningFixture();
    $question = screeningQuestion($f['job'], ['type' => ScreeningQuestionType::YES_NO, 'label' => 'Original wording?', 'knockout' => ['required_answer' => 'yes']]);
    app(ScreeningService::class)->recordAnswers($f['applicant'], [$question->id => 'yes'], $f['hr']);

    $question->update(['label' => 'New wording?', 'knockout' => ['required_answer' => 'no']]);
    $question->delete();

    $answer = ApplicantAnswer::query()->where('applicant_id', $f['applicant']->id)->sole();
    expect($answer->question_snapshot['label'])->toBe('Original wording?')
        ->and($answer->question_snapshot['knockout'])->toBe(['required_answer' => 'yes'])
        ->and($answer->job_question_id)->toBeNull()
        ->and($answer->passed)->toBeTrue();
});

it('lets a recruiter accept a flagged applicant with a recorded reason, until the answers change', function () {
    $f = screeningFixture();
    $question = screeningQuestion($f['job'], ['type' => ScreeningQuestionType::YES_NO, 'label' => 'On-site?', 'knockout' => ['required_answer' => 'yes']]);
    $service = app(ScreeningService::class);
    $service->recordAnswers($f['applicant'], [$question->id => 'no'], $f['hr']);

    expect(fn () => $service->override($f['applicant'], $f['hr'], '  '))->toThrow(RuntimeException::class, 'reason');

    $accepted = $service->override($f['applicant'], $f['hr'], 'Will relocate within a month');
    expect($accepted->screening_status)->toBe('overridden')
        ->and($accepted->applicant_properties['screening']['override'])->toMatchArray(['by' => $f['hr']->id, 'reason' => 'Will relocate within a month']);

    expect(fn () => $service->override($accepted, $f['hr'], 'again'))->toThrow(RuntimeException::class, 'Only an applicant flagged');

    $service->recordAnswers($accepted, [$question->id => 'no'], $f['hr']);
    expect($accepted->refresh()->screening_status)->toBe('flagged')
        ->and($accepted->applicant_properties['screening']['previous_override']['reason'])->toBe('Will relocate within a month');
});

it('screens applications that arrive with answers through intake (import, later the careers website)', function () {
    $f = screeningFixture();
    $question = screeningQuestion($f['job'], ['type' => ScreeningQuestionType::YES_NO, 'label' => 'On-site?', 'knockout' => ['required_answer' => 'yes']]);

    $applicant = app(ApplicantIntakeService::class)->import('manual', [
        'external_application_id' => 'WEB-1', 'candidate_name' => 'Sara Khan', 'candidate_email' => 'sara@example.test',
        'job_id'                  => $f['job']->id, 'source_details' => 'Careers form', 'screening_answers' => [$question->id => 'no'],
    ], jobOpeningUser($f['company']));

    expect($applicant->screening_status)->toBe('flagged')
        ->and($applicant->answers()->sole()->source)->toBe('manual')
        ->and(app(ScreeningService::class)->publicQuestionsFor($f['job']))->toBe([
            ['id' => $question->id, 'type' => 'yes_no', 'label' => 'On-site?', 'help_text' => null, 'options' => [], 'required' => true],
        ]);
});

it('refuses answers from a user who cannot edit the application', function () {
    $f = screeningFixture();
    $question = screeningQuestion($f['job'], ['type' => ScreeningQuestionType::YES_NO, 'label' => 'On-site?']);

    expect(fn () => app(ScreeningService::class)->recordAnswers($f['applicant'], [$question->id => 'yes'], jobOpeningUser($f['company'])))
        ->toThrow(RuntimeException::class, 'not allowed');
});

// ---------------------------------------------------------------------
// Screens
// ---------------------------------------------------------------------

it('lets HR manage the library and the job questions on screen, and keeps the tab read-only for viewers', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $f = screeningFixture();
    $otherCompanyQuestion = ScreeningQuestion::query()->where('company_id', '!=', $f['company']->id)->first();
    test()->actingAs($f['hr']);

    Livewire::test(ManageScreeningQuestions::class)
        ->assertCanSeeTableRecords(ScreeningQuestion::query()->where('company_id', $f['company']->id)->get())
        ->assertCanNotSeeTableRecords([$otherCompanyQuestion])
        ->callAction('create', data: ['type' => 'number', 'label' => 'Expected salary (PKR)?', 'is_required' => false, 'knockout' => ['max' => 300000], 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(ScreeningQuestion::query()->where('company_id', $f['company']->id)->where('label', 'Expected salary (PKR)?')->sole()->knockout)->toBe(['max' => 300000]);

    $libraryIds = ScreeningQuestion::query()->where('company_id', $f['company']->id)->orderBy('sort')->take(2)->pluck('id')->all();
    $tab = fn () => Livewire::test(JobQuestionsRelationManager::class, ['ownerRecord' => $f['job']->refresh(), 'pageClass' => ViewJobPosition::class]);
    $tab()->callTableAction('addFromLibrary', data: ['questions' => $libraryIds])->assertHasNoTableActionErrors();
    $tab()->callTableAction('create', data: ['type' => 'yes_no', 'label' => 'Do you have a heavy-vehicle licence?', 'is_required' => true, 'knockout' => ['required_answer' => 'yes']])
        ->assertHasNoTableActionErrors();

    expect($f['job']->questions()->pluck('label')->last())->toBe('Do you have a heavy-vehicle licence?')
        ->and($f['job']->questions()->count())->toBe(3);

    test()->actingAs(jobOpeningUser($f['company'], 'view_recruitment_job::position', 'view_any_recruitment_job::position'));
    $tab()->assertTableActionHidden('addFromLibrary')->assertTableActionHidden('create');
});
