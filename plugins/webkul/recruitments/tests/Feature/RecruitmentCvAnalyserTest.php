<?php

/**
 * Free CV analyser (docs/RECRUITMENT_AUTOMATION_PLAN.md, phase A5a): CV text
 * from PDF, DOCX and TXT without new dependencies; a deterministic 0-100
 * score from the job's keywords, minimum experience and work location; a
 * remote-only flag for on-site jobs; never a rejection; automatic analysis
 * on the queue; and the recruiter's buttons, column and filters.
 */

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Webkul\Employee\Models\Skill;
use Webkul\Employee\Models\SkillType;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Enums\JobLocationType;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\ListApplicants;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\ViewApplicant;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource;
use Webkul\Recruitment\Jobs\AnalyseApplicantCv;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Services\CvAnalysis\CvAnalyser;
use Webkul\Recruitment\Services\CvAnalysis\CvTextExtractor;
use Webkul\Recruitment\Services\CvAnalysis\PdfTextParser;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Country;

require_once __DIR__.'/Support/JobOpeningHelpers.php';

const CV_FIT = "Ali Raza — Fleet dispatcher, Karachi.\nExperience: Dispatch coordinator at TCS 2018 - 2021, Fleet supervisor at Leopards 2021 - present.\nSkills: route planning, MS Excel, fleet tracking.";

const CV_REMOTE = "Sara Khan. Logistics planner with 6 years experience in dispatch and fleet planning (Excel).\nLooking for remote opportunities only; not willing to relocate.";

/** @return array{company: Company, job: JobPosition} */
function cvJobFixture(array $attributes = []): array
{
    Auth::login(User::factory()->create(['is_active' => true]));
    $company = Company::factory()->create(['is_active' => true]);
    $address = Partner::query()->create([
        'name' => 'Karachi office', 'sub_type' => 'company', 'company_id' => $company->id,
        'city' => 'Karachi', 'country_id' => Country::query()->where('code', 'PK')->value('id'),
    ]);

    $job = JobPosition::query()->create($attributes + [
        'company_id'           => $company->id,
        'name'                 => 'Fleet Dispatcher',
        'is_active'            => true,
        'address_id'           => $address->id,
        'location_type'        => JobLocationType::ON_SITE,
        'min_experience_years' => 3,
        'screening_keywords'   => ['dispatch', 'fleet', 'Excel'],
    ]);

    return compact('company', 'job');
}

/** An application whose candidate has a CV with the given text (stored privately). */
function cvApplicant(JobPosition $job, ?string $cvText, string $extension = 'txt'): Applicant
{
    $path = null;

    if ($cvText !== null) {
        $path = 'candidates/cv/'.Str::random(10).'.'.$extension;
        Storage::disk(CandidateResource::CV_DISK)->put($path, $cvText);
    }

    $candidate = Candidate::query()->create(['company_id' => $job->company_id, 'name' => 'Candidate '.Str::random(5), 'email_from' => Str::random(8).'@example.test', 'resume_path' => $path]);

    return Applicant::query()->create(['company_id' => $job->company_id, 'candidate_id' => $candidate->id, 'job_id' => $job->id, 'create_date' => now()]);
}

function cvDocx(string $text): string
{
    $path = tempnam(sys_get_temp_dir(), 'cv').'.docx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $paragraphs = collect(explode("\n", $text))->map(fn (string $line): string => '<w:p><w:r><w:t>'.htmlspecialchars($line, ENT_XML1).'</w:t></w:r></w:p>')->implode('');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$paragraphs.'</w:body></w:document>');
    $zip->close();

    return $path;
}

beforeEach(function (): void {
    Storage::fake(CandidateResource::CV_DISK);
    Queue::fake();
});

// ---------------------------------------------------------------------
// Reading CVs
// ---------------------------------------------------------------------

it('reads the text of TXT and DOCX CVs', function () {
    $extractor = app(CvTextExtractor::class);

    $txt = tempnam(sys_get_temp_dir(), 'cv').'.txt';
    file_put_contents($txt, CV_FIT);

    expect($extractor->extract($txt))->toMatchArray(['source' => 'txt'])
        ->and($extractor->extract($txt)['text'])->toContain('Fleet supervisor at Leopards');

    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('The zip extension is needed for .docx.');
    }

    $docx = $extractor->extract(cvDocx(CV_FIT));

    expect($docx['source'])->toBe('docx')
        ->and($docx['text'])->toContain('Dispatch coordinator at TCS 2018 - 2021')
        ->and($docx['text'])->toContain("Karachi.\nExperience");
});

it('reads a text PDF with the built-in reader when pdftotext is not installed', function () {
    config(['recruitment.cv_analysis.pdftotext_path' => 'Z:/definitely/not/pdftotext.exe']);

    $pdf = Pdf::loadHTML('<p>Ali Raza</p><p>Fleet dispatcher in Karachi (2018 - 2023)</p><p>MS Excel &amp; route planning</p>')->output();
    $path = tempnam(sys_get_temp_dir(), 'cv').'.pdf';
    file_put_contents($path, $pdf);

    $result = app(CvTextExtractor::class)->extract($path);

    expect($result['source'])->toBe('pdf')
        ->and($result['text'])->toContain('Fleet dispatcher in Karachi')
        ->and($result['text'])->toContain('MS Excel & route planning')
        ->and(app(PdfTextParser::class)->parse('not a pdf'))->toBe('');
});

it('reads PDFs with embedded Unicode fonts, as Word and Google Docs export them', function () {
    $pdf = Pdf::loadHTML('<div style="font-family: \'DejaVu Sans\'"><p>Fleet dispatcher — Karachi. Café résumé.</p><p>Dispatch coordinator 2018 – 2021, 10 trucks</p></div>')->output();

    $text = app(PdfTextParser::class)->parse($pdf);

    expect($text)->toContain('Fleet dispatcher — Karachi. Café résumé.')
        ->and($text)->toContain('Dispatch coordinator 2018 – 2021, 10 trucks');
});

it('returns no text for missing and unsupported files', function () {
    $extractor = app(CvTextExtractor::class);

    $image = tempnam(sys_get_temp_dir(), 'cv').'.png';
    file_put_contents($image, 'binary');

    expect($extractor->extract('Z:/nowhere/cv.pdf'))->toBe(['text' => '', 'source' => 'missing'])
        ->and($extractor->extract($image))->toBe(['text' => '', 'source' => 'unsupported']);
});

// ---------------------------------------------------------------------
// Scoring
// ---------------------------------------------------------------------

it('scores a CV that fits the job at 100 and explains why', function () {
    ['job' => $job] = cvJobFixture();

    $result = app(CvAnalyser::class)->score(CV_FIT, $job);

    expect($result)->toMatchArray([
        'status'           => 'analysed',
        'score'            => 100,
        'matched'          => ['dispatch', 'fleet', 'Excel'],
        'missing'          => [],
        'experience_years' => (float) (now()->year - 2018),
        'required_years'   => 3,
        'flags'            => [],
    ])
        ->and(implode(' ', $result['reasons']))->toContain('Keywords: 3 of 3 found')
        ->and(implode(' ', $result['reasons']))->toContain('the CV mentions Karachi');
});

it('flags a remote-only CV for an on-site job and caps its score, without rejecting it', function () {
    ['job' => $job] = cvJobFixture();

    $result = app(CvAnalyser::class)->score(CV_REMOTE, $job);

    expect($result['flags'])->toBe(['remote_only'])
        ->and($result['score'])->toBe(40)
        ->and($result['experience_years'])->toBe(6.0)
        ->and(implode(' ', $result['reasons']))->toContain('remote opportunities only');

    $job->update(['location_type' => JobLocationType::REMOTE]);

    expect(app(CvAnalyser::class)->score(CV_REMOTE, $job->refresh()))->toMatchArray(['flags' => [], 'score' => 100]);
});

it('weighs only the criteria the job sets and explains missing ones', function () {
    ['job' => $job] = cvJobFixture(['min_experience_years' => null, 'location_type' => null, 'screening_keywords' => ['dispatch', 'forklift']]);
    $analyser = app(CvAnalyser::class);

    expect($analyser->score(CV_FIT, $job))->toMatchArray(['score' => 50, 'matched' => ['dispatch'], 'missing' => ['forklift']]);

    $job->update(['screening_keywords' => []]);
    expect($analyser->score(CV_FIT, $job->refresh()))->toMatchArray(['status' => 'no_criteria', 'score' => null])
        ->and($analyser->score('', $job))->toMatchArray(['status' => 'no_text', 'score' => null]);

    $job->update(['min_experience_years' => 5]);
    $unknown = $analyser->score('Dispatcher. Fleet. Excel.', $job->refresh());
    expect($unknown['score'])->toBe(50)
        ->and(implode(' ', $unknown['reasons']))->toContain('could not find years of experience');
});

it('uses the job\'s skills as keywords too', function () {
    ['job' => $job] = cvJobFixture(['screening_keywords' => ['dispatch']]);
    $type = SkillType::query()->create(['name' => 'Logistics '.Str::random(4)]);
    $skill = Skill::query()->create(['name' => 'Route planning', 'skill_type_id' => $type->id]);
    $job->skills()->attach($skill->id);

    expect(app(CvAnalyser::class)->score(CV_FIT, $job->refresh()))
        ->toMatchArray(['matched' => ['Route planning', 'dispatch'], 'missing' => []]);
});

it('counts overlapping job periods once and reads stated years', function () {
    $analyser = app(CvAnalyser::class);

    expect($analyser->experienceYears('worked 2012 - 2015, then 2014 - 2018 and 2020 to 2021'))->toBe(7.0)
        ->and($analyser->experienceYears('7+ years of experience in logistics'))->toBe(7.0)
        ->and($analyser->experienceYears('no dates here'))->toBeNull();
});

// ---------------------------------------------------------------------
// Storing, re-use and isolation
// ---------------------------------------------------------------------

it('stores the score and reasons on the application and reads each CV file only once', function () {
    ['job' => $job] = cvJobFixture();
    $applicant = cvApplicant($job, CV_FIT);

    $analysis = app(CvAnalyser::class)->analyse($applicant);

    $applicant->refresh();
    $candidate = $applicant->candidate;

    expect($analysis['score'])->toBe(100)
        ->and((float) $applicant->screening_score)->toBe(100.0)
        ->and($applicant->applicant_properties['cv_analysis'])->toMatchArray(['score' => 100, 'version' => CvAnalyser::VERSION, 'text_source' => 'txt'])
        ->and($candidate->cv_text)->toContain('Fleet supervisor')
        ->and($candidate->toArray())->not->toHaveKey('cv_text');

    $extractedAt = $candidate->cv_text_extracted_at;
    $this->travel(5)->minutes();
    app(CvAnalyser::class)->analyse($applicant->refresh());

    expect($candidate->refresh()->cv_text_extracted_at->equalTo($extractedAt))->toBeTrue();
});

it('never scores an application against a job of another company', function () {
    ['job' => $job] = cvJobFixture();
    $applicant = cvApplicant($job, CV_FIT);
    $applicant->forceFill(['company_id' => Company::factory()->create()->id])->saveQuietly();

    expect(app(CvAnalyser::class)->analyse($applicant->refresh()))->toBeNull()
        ->and($applicant->refresh()->screening_score)->toBeNull();
});

// ---------------------------------------------------------------------
// Automatic analysis
// ---------------------------------------------------------------------

it('queues an analysis for new applications and for every application of a candidate whose CV changes', function () {
    ['job' => $job] = cvJobFixture();
    $applicant = cvApplicant($job, CV_FIT);

    Queue::assertPushed(AnalyseApplicantCv::class, fn (AnalyseApplicantCv $job): bool => $job->applicantId === $applicant->id);

    Queue::fake();
    $applicant->candidate->update(['resume_path' => 'candidates/cv/new.pdf']);

    Queue::assertPushed(AnalyseApplicantCv::class, 1);

    Queue::fake();
    config(['recruitment.cv_analysis.auto' => false]);
    cvApplicant($job, CV_FIT);

    Queue::assertNothingPushed();
});

it('analyses CVs from the command, now or on the queue', function () {
    ['job' => $job] = cvJobFixture();
    $fit = cvApplicant($job, CV_FIT);
    $remote = cvApplicant($job, CV_REMOTE);

    $this->artisan('recruitments:analyse-cvs', ['--job' => $job->id])
        ->expectsOutputToContain('Analysed 2 application(s); 2 got a CV score.')
        ->assertSuccessful();

    expect((float) $fit->refresh()->screening_score)->toBe(100.0)
        ->and((float) $remote->refresh()->screening_score)->toBe(40.0);

    Queue::fake();
    $this->artisan('recruitments:analyse-cvs', ['--job' => $job->id, '--queue' => true, '--only-missing' => true])->assertSuccessful();
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------------
// Recruiter screens
// ---------------------------------------------------------------------

it('lets the recruiter analyse a CV, sort by CV score and filter remote-only CVs', function () {
    ['company' => $company, 'job' => $job] = cvJobFixture();
    $fit = cvApplicant($job, CV_FIT);
    $remote = cvApplicant($job, CV_REMOTE);
    $recruiter = jobOpeningRoleUser($company, 'recruiter');
    Auth::login($recruiter);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    Livewire::test(ViewApplicant::class, ['record' => $fit->getRouteKey()])
        ->assertActionVisible('analyseCv')
        ->callAction('analyseCv')
        ->assertNotified('CV score: 100');

    app(CvAnalyser::class)->analyse($remote);

    Livewire::test(ListApplicants::class)
        ->sortTable('screening_score', 'desc')
        ->assertCanSeeTableRecords([$fit, $remote], inOrder: true)
        ->filterTable('cv_remote_only', true)
        ->assertCanSeeTableRecords([$remote])
        ->assertCanNotSeeTableRecords([$fit]);
});
