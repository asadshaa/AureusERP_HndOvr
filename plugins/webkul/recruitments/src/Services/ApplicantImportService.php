<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;
use Webkul\Recruitment\Enums\ScreeningStatus;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Support\ApplicantImportResult;
use Webkul\Security\Models\User;
use Webkul\Support\Services\ApprovalEngine;

/**
 * Bulk import of applicants from a CSV/Excel export of a job board
 * (Recruitment Stage 2.4). Every row goes through ApplicantIntakeService (the
 * same path the careers website will use): duplicate candidates are matched
 * by email, a candidate gets one application per job, the ad and source are
 * recorded, and screening answers are judged. Re-importing the same file
 * changes nothing new. Runs synchronously (no queue worker needed).
 */
class ApplicantImportService
{
    public const MAX_ROWS = 2000;

    /** Our field => header names that usually hold it (lower case, punctuation removed). */
    public const FIELD_ALIASES = [
        'name'       => ['name', 'full name', 'candidate', 'candidate name', 'applicant', 'applicant name'],
        'first_name' => ['first name', 'firstname', 'given name'],
        'last_name'  => ['last name', 'lastname', 'surname', 'family name'],
        'email'      => ['email', 'e mail', 'email address', 'mail', 'email id'],
        'phone'      => ['phone', 'mobile', 'phone number', 'mobile number', 'contact', 'contact number', 'cell'],
        'linkedin'   => ['linkedin', 'linkedin url', 'linkedin profile', 'profile url', 'profile'],
        'reference'  => ['id', 'application id', 'applicant id', 'reference', 'ref'],
        'cv_link'    => ['cv', 'resume', 'cv link', 'resume link', 'cv url', 'resume url'],
    ];

    public const FIELD_LABELS = [
        'name'       => 'Full name',
        'first_name' => 'First name',
        'last_name'  => 'Last name',
        'email'      => 'Email (required)',
        'phone'      => 'Phone',
        'linkedin'   => 'LinkedIn profile',
        'reference'  => 'Application ID (from the board)',
        'cv_link'    => 'CV link',
    ];

    public function __construct(
        protected ApplicantIntakeService $intake,
        protected ApprovalEngine $approvals,
    ) {}

    /**
     * Read the header row and data rows of a CSV or Excel file.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<string, string>>}
     */
    public function read(string $path, ?string $originalName = null): array
    {
        $extension = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));

        $matrix = match ($extension) {
            'csv', 'txt'  => $this->readCsv($path),
            'xlsx', 'xls' => $this->readSpreadsheet($path, $extension),
            default       => throw new RuntimeException('Upload a CSV or Excel (.xlsx, .xls) file.'),
        };

        $matrix = array_values(array_filter($matrix, fn (array $row): bool => implode('', array_map('trim', array_map('strval', $row))) !== ''));

        if ($matrix === []) {
            throw new RuntimeException('The file is empty.');
        }

        $headers = $this->uniqueHeaders(array_shift($matrix));

        if (count($matrix) > self::MAX_ROWS) {
            throw new RuntimeException('The file has '.count($matrix).' rows; import at most '.self::MAX_ROWS.' at a time.');
        }

        $rows = array_map(function (array $row) use ($headers): array {
            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[$header] = trim((string) ($row[$i] ?? ''));
            }

            return $assoc;
        }, $matrix);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Best guess of which column holds each field.
     *
     * @param  array<int, string>  $headers
     * @return array<string, string|null>
     */
    public function suggestMapping(array $headers): array
    {
        $normalized = collect($headers)->mapWithKeys(fn (string $header): array => [$header => $this->normalizeHeader($header)]);

        return collect(self::FIELD_ALIASES)->map(function (array $aliases) use ($normalized): ?string {
            foreach ($aliases as $alias) {
                $match = $normalized->search($alias, true);
                if ($match !== false) {
                    return $match;
                }
            }

            return null;
        })->all();
    }

    /**
     * Import the rows for one job.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array<string, string|null>  $mapping  field => header
     * @param  array<int|string, string|null>  $questionMapping  job question id => header
     */
    public function import(
        JobPosition $job,
        array $rows,
        array $mapping,
        User $actor,
        ?JobPosting $posting = null,
        ?int $sourceId = null,
        array $questionMapping = [],
        string $fileName = 'import',
    ): ApplicantImportResult {
        $this->authorize($job, $actor);

        if ($posting && ((int) $posting->job_id !== (int) $job->id || (int) $posting->company_id !== (int) $job->company_id)) {
            throw new RuntimeException('The selected ad belongs to a different job.');
        }

        if (blank($mapping['email'] ?? null)) {
            throw new RuntimeException('Choose which column holds the email address.');
        }

        if (blank($mapping['name'] ?? null) && blank($mapping['first_name'] ?? null)) {
            throw new RuntimeException('Choose which column holds the name (or first name).');
        }

        $questionIds = JobQuestion::query()->where('job_id', $job->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $questionMapping = collect($questionMapping)->filter()->filter(fn ($header, $id): bool => in_array((int) $id, $questionIds, true))->all();

        $result = new ApplicantImportResult;
        $seenEmails = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // header is row 1

            try {
                $this->importRow($job, $row, $rowNumber, $mapping, $questionMapping, $actor, $posting, $sourceId, $fileName, $seenEmails, $result);
            } catch (ValidationException $e) {
                $result->failed[$rowNumber] = collect($e->errors())->flatten()->implode(' ');
            } catch (RuntimeException|InvalidArgumentException $e) {
                $result->failed[$rowNumber] = $e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $result->failed[$rowNumber] = 'Unexpected error; see the application log.';
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $mapping
     * @param  array<int|string, string>  $questionMapping
     * @param  array<string, int>  $seenEmails
     */
    protected function importRow(
        JobPosition $job,
        array $row,
        int $rowNumber,
        array $mapping,
        array $questionMapping,
        User $actor,
        ?JobPosting $posting,
        ?int $sourceId,
        string $fileName,
        array &$seenEmails,
        ApplicantImportResult $result,
    ): void {
        $value = fn (string $field): string => filled($mapping[$field] ?? null) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';

        $email = Str::lower($value('email'));
        $name = $value('name') !== '' ? $value('name') : trim($value('first_name').' '.$value('last_name'));

        if ($email === '') {
            $result->failed[$rowNumber] = 'No email address';

            return;
        }

        if (isset($seenEmails[$email])) {
            $result->skipped[$rowNumber] = "Same email as row {$seenEmails[$email]} in this file";

            return;
        }
        $seenEmails[$email] = $rowNumber;

        if ($name === '') {
            $result->failed[$rowNumber] = 'No name';

            return;
        }

        $reference = $value('reference');
        $externalId = 'import:'.($reference !== '' ? Str::limit($reference, 200, '') : substr(sha1($job->id.'|'.$email), 0, 24));

        // One application per candidate per job: someone who already applied
        // (entered by hand or through another channel) is left as is.
        $existingForJob = Applicant::withTrashed()
            ->where('company_id', $job->company_id)
            ->where('job_id', $job->id)
            ->whereHas('candidate', fn ($query) => $query->where('email_from', $email))
            ->first();

        if ($existingForJob && $existingForJob->external_application_id !== $externalId) {
            $result->alreadyApplied++;

            return;
        }

        $linkedin = $value('linkedin');
        $answers = collect($questionMapping)
            ->map(fn (string $header): string => trim((string) ($row[$header] ?? '')))
            ->filter(fn (string $answer): bool => $answer !== '')
            ->all();

        $sourceDetails = Str::limit(trim(($posting ? $posting->displayName().' — ' : '').'Imported from '.$fileName), 255, '');

        $applicant = $this->intake->import('import', array_filter([
            'company_id'              => (int) $job->company_id,
            'external_application_id' => $externalId,
            'candidate_name'          => Str::limit($name, 255, ''),
            'candidate_email'         => $email,
            'candidate_phone'         => $value('phone') ?: null,
            'job_id'                  => (int) $job->id,
            'job_posting_id'          => $posting?->id,
            'source_id'               => $posting ? null : $sourceId,
            'source_details'          => $sourceDetails,
            'portfolio_url'           => filter_var($linkedin, FILTER_VALIDATE_URL) ? $linkedin : null,
            'screening_answers'       => $answers ?: null,
        ], fn ($item): bool => $item !== null), $actor);

        if ($value('cv_link') !== '') {
            $properties = (array) $applicant->applicant_properties;
            $properties['source_provenance']['cv_link'] = Str::limit($value('cv_link'), 2048, '');
            $applicant->forceFill(['applicant_properties' => $properties])->saveQuietly();
        }

        $existingForJob ? $result->updated++ : $result->created++;

        if ($applicant->screening_status === ScreeningStatus::FLAGGED->value) {
            $result->flagged++;
        }
    }

    protected function authorize(JobPosition $job, User $actor): void
    {
        if (! Gate::forUser($actor)->allows('create', Applicant::class)
            || ! $job->company_id
            || ! $this->approvals->userCanAccessCompany($actor, (int) $job->company_id)) {
            throw new RuntimeException('You are not allowed to import applicants for this job.');
        }
    }

    /** @return array<int, array<int, mixed>> */
    protected function readCsv(string $path): array
    {
        $contents = (string) file_get_contents($path);
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $firstLine = strtok($contents, "\n") ?: '';
        $delimiter = collect([',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")])->sortDesc()->keys()->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /** @return array<int, array<int, mixed>> */
    protected function readSpreadsheet(string $path, string $extension): array
    {
        if ($extension === 'xlsx' && ! class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Excel (.xlsx) files need the PHP zip extension on this server. Save the sheet as CSV and import that instead.');
        }

        return IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<int, string>
     */
    protected function uniqueHeaders(array $row): array
    {
        $headers = [];

        foreach ($row as $i => $header) {
            $header = trim((string) $header) ?: 'Column '.($i + 1);
            $candidate = $header;
            $n = 2;
            while (in_array($candidate, $headers, true)) {
                $candidate = "{$header} ({$n})";
                $n++;
            }
            $headers[] = $candidate;
        }

        return $headers;
    }

    protected function normalizeHeader(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower($header)));
    }
}
