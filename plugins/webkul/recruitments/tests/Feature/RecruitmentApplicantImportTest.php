<?php

/**
 * Bulk import of applicants from job board exports (Recruitment Stage 2.4):
 * CSV/Excel reading, column matching, intake per row with attribution,
 * duplicate protection, one application per job, screening on import, and
 * row-by-row problem reporting.
 */

use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Enums\ScreeningQuestionType;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages\ListApplicants;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Services\ApplicantImportService;
use Webkul\Recruitment\Services\JobPostingService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/Support/JobOpeningHelpers.php';

/** @return array{company: Company, job: JobPosition, hr: User} */
function importFixture(): array
{
    Auth::login(User::factory()->create(['is_active' => true]));
    $company = Company::factory()->create(['is_active' => true]);
    $job = JobPosition::query()->create(['company_id' => $company->id, 'name' => 'Fleet Dispatcher', 'is_active' => true]);
    $hr = jobOpeningRoleUser($company, 'recruiter');

    return compact('company', 'job', 'hr');
}

function importCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

it('reads CSV exports with a BOM, semicolons, duplicate headers and blank lines, and suggests the columns', function () {
    $path = importCsv("\xEF\xBB\xBFFull Name;E-mail;Mobile Number;Mobile Number;LinkedIn Profile\nAli Raza;ALI@example.test;0300;0301;https://linkedin.com/in/ali\n;;;;\nSara Khan;sara@example.test;;;\n");
    $service = app(ApplicantImportService::class);

    $file = $service->read($path, 'export.csv');

    expect($file['headers'])->toBe(['Full Name', 'E-mail', 'Mobile Number', 'Mobile Number (2)', 'LinkedIn Profile'])
        ->and($file['rows'])->toHaveCount(2)
        ->and($file['rows'][0]['E-mail'])->toBe('ALI@example.test')
        ->and($service->suggestMapping($file['headers']))->toMatchArray([
            'name' => 'Full Name', 'email' => 'E-mail', 'phone' => 'Mobile Number', 'linkedin' => 'LinkedIn Profile', 'reference' => null,
        ]);
});

it('reads Excel exports', function () {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('The zip extension is needed for .xlsx.');
    }

    $sheet = new Spreadsheet;
    $sheet->getActiveSheet()->fromArray([['Name', 'Email'], ['Ali Raza', 'ali@example.test']]);
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    (new Xlsx($sheet))->save($path);

    expect(app(ApplicantImportService::class)->read($path, 'export.xlsx')['rows'])->toBe([['Name' => 'Ali Raza', 'Email' => 'ali@example.test']]);
});

it('imports every row with its ad, matches existing candidates, and changes nothing new on a second import', function () {
    $f = importFixture();
    $manager = jobOpeningUser($f['company'], 'hr_manage_job_postings');
    $posting = app(JobPostingService::class)->addChannels($f['job'], [['channel' => 'rozee']], jobOpeningRoleUser($f['company'], 'recruiter'))->first();
    $partner = Partner::query()->create(['name' => 'Old Candidate', 'sub_type' => 'individual', 'company_id' => $f['company']->id]);
    Candidate::query()->create(['company_id' => $f['company']->id, 'name' => 'Old Candidate', 'email_from' => 'sara@example.test', 'partner_id' => $partner->id]);

    $service = app(ApplicantImportService::class);
    $file = $service->read(importCsv("Name,Email,Phone\nAli Raza,Ali@Example.test,0300\nSara Khan,sara@example.test,\n"), 'rozee.csv');
    $mapping = $service->suggestMapping($file['headers']);

    $first = $service->import($f['job'], $file['rows'], $mapping, $f['hr'], $posting, null, [], 'rozee.csv');

    expect($first->created)->toBe(2)
        ->and($first->failed)->toBe([])
        ->and(Candidate::query()->where('company_id', $f['company']->id)->where('email_from', 'sara@example.test')->count())->toBe(1);

    $ali = Applicant::query()->where('job_id', $f['job']->id)->whereHas('candidate', fn ($q) => $q->where('email_from', 'ali@example.test'))->sole();
    expect($ali->job_posting_id)->toBe($posting->id)
        ->and($ali->source_id)->toBe($posting->refresh()->utm_source_id)
        ->and($ali->source_details)->toContain('Imported from rozee.csv');

    $second = $service->import($f['job'], $file['rows'], $mapping, $f['hr'], $posting, null, [], 'rozee.csv');

    expect($second->created)->toBe(0)
        ->and($second->updated)->toBe(2)
        ->and(Applicant::query()->where('job_id', $f['job']->id)->count())->toBe(2)
        ->and($manager->exists)->toBeTrue();
});

it('reports bad rows one by one and keeps one application per candidate per job', function () {
    $f = importFixture();
    $partner = Partner::query()->create(['name' => 'Hina', 'sub_type' => 'individual', 'company_id' => $f['company']->id]);
    $hina = Candidate::query()->create(['company_id' => $f['company']->id, 'name' => 'Hina', 'email_from' => 'hina@example.test', 'partner_id' => $partner->id]);
    Applicant::query()->create(['company_id' => $f['company']->id, 'candidate_id' => $hina->id, 'job_id' => $f['job']->id, 'create_date' => now()]);

    $service = app(ApplicantImportService::class);
    $file = $service->read(importCsv("First Name,Last Name,Email\nAli,Raza,ali@example.test\nAli,Again,ALI@example.test\nNo,Email,\nBad,Email,not-an-email\n,,nameless@example.test\nHina,Ahmed,hina@example.test\n"), 'export.csv');

    $result = $service->import($f['job'], $file['rows'], $service->suggestMapping($file['headers']), $f['hr']);

    expect($result->created)->toBe(1)
        ->and($result->alreadyApplied)->toBe(1)
        ->and($result->skipped)->toBe([3 => 'Same email as row 2 in this file'])
        ->and(array_keys($result->failed))->toBe([4, 5, 6])
        ->and($result->failed[4])->toBe('No email address')
        ->and($result->failed[6])->toBe('No name')
        ->and($result->problemLines()[0])->toBe('Row 3 skipped: Same email as row 2 in this file')
        ->and(Applicant::query()->where('job_id', $f['job']->id)->count())->toBe(2);
});

it('judges screening answers found in the file', function () {
    $f = importFixture();
    $question = JobQuestion::query()->create(['job_id' => $f['job']->id, 'type' => ScreeningQuestionType::YES_NO, 'label' => 'Can you work on-site?', 'is_required' => true, 'knockout' => ['required_answer' => 'yes'], 'sort' => 1]);
    $service = app(ApplicantImportService::class);
    $file = $service->read(importCsv("Name,Email,Can you work on-site?\nAli,ali@example.test,Yes\nSara,sara@example.test,No\n"), 'export.csv');

    $result = $service->import($f['job'], $file['rows'], $service->suggestMapping($file['headers']), $f['hr'], null, null, [$question->id => 'Can you work on-site?']);

    expect($result->created)->toBe(2)
        ->and($result->flagged)->toBe(1)
        ->and(Applicant::query()->where('job_id', $f['job']->id)->pluck('screening_status')->sort()->values()->all())->toBe(['flagged', 'passed']);
});

it('refuses users who cannot add applicants, other companies\' jobs and another job\'s ad', function () {
    $f = importFixture();
    $service = app(ApplicantImportService::class);
    $rows = [['Name' => 'Ali', 'Email' => 'ali@example.test']];
    $mapping = ['name' => 'Name', 'email' => 'Email'];
    $otherCompany = Company::factory()->create(['is_active' => true]);
    $foreignJob = JobPosition::query()->create(['company_id' => $otherCompany->id, 'name' => 'Foreign', 'is_active' => true]);
    $otherJob = JobPosition::query()->create(['company_id' => $f['company']->id, 'name' => 'Other', 'is_active' => true]);
    $otherAd = app(JobPostingService::class)->addChannels($otherJob, [['channel' => 'rozee']], $f['hr'])->first();

    expect(fn () => $service->import($f['job'], $rows, $mapping, jobOpeningUser($f['company'])))->toThrow(RuntimeException::class, 'not allowed')
        ->and(fn () => $service->import($foreignJob, $rows, $mapping, $f['hr']))->toThrow(RuntimeException::class, 'not allowed')
        ->and(fn () => $service->import($f['job'], $rows, $mapping, $f['hr'], $otherAd))->toThrow(RuntimeException::class, 'different job')
        ->and(fn () => $service->import($f['job'], $rows, ['name' => 'Name'], $f['hr']))->toThrow(RuntimeException::class, 'email');

    expect(JobPosting::query()->whereKey($otherAd->id)->exists())->toBeTrue();
});

it('imports from the "Import CVs" button on the applicant list', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    $f = importFixture();
    test()->actingAs($f['hr']);

    Livewire::test(ListApplicants::class)
        ->callAction('importApplicants', data: [
            'job_id'  => $f['job']->id,
            'file'    => UploadedFile::fake()->createWithContent('linkedin-export.csv', "Name,Email\nAli Raza,ali@example.test\nSara Khan,sara@example.test\n"),
            'headers' => ['Name', 'Email'],
            'mapping' => ['name' => 'Name', 'email' => 'Email'],
        ])
        ->assertHasNoActionErrors();

    expect(Applicant::query()->where('job_id', $f['job']->id)->count())->toBe(2)
        ->and($f['hr']->notifications()->get()->first()?->data['title'])->toBe('Imported 2 row(s) for Fleet Dispatcher');
});
