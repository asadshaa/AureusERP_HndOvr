<?php

namespace Webkul\Recruitment\Services\Website;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\IntakeFailure;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Models\WebsiteConnection;
use Webkul\Recruitment\Services\ApplicantIntakeService;
use Webkul\Recruitment\Services\JobPostingChannelRegistry;
use Webkul\Recruitment\Services\JobPostingLinks;
use Webkul\Recruitment\Services\SourceAdapters\CompanyWebsiteApplicantSourceAdapter;
use Webkul\Recruitment\Support\WebsiteIntakeResult;
use Webkul\Security\Models\User;

/**
 * Pulls applications from each company careers website (automation plan
 * §6 A4, API contract §7) and imports them through ApplicantIntakeService:
 *
 *  - a connection only ever creates records in its own company; an
 *    application for a job of another company is refused and recorded;
 *  - idempotent: the applicant's external_application_id is
 *    "website:{site host}:{website id}", so a re-run (e.g. after a lost
 *    acknowledgement) acknowledges again and never imports twice;
 *  - one application per candidate per job (matched by email), as the file
 *    import does: a repeat application is acknowledged, not duplicated;
 *  - the CV is downloaded, checked again (PDF/DOC/DOCX by content, at most
 *    CandidateResource::CV_MAX_KB) and stored on the private CV disk;
 *  - the website is acknowledged only after the database commit;
 *  - anything that fails is kept in recruitments_intake_failures with what
 *    the website sent, retried automatically a few times, then left to HR
 *    on the Website intake page (Retry / Dismiss).
 *
 * The pull runs without a signed-in user: the intake's system path
 * (ApplicantIntakeService::importForCompany) takes the company from the
 * connection, never from the payload.
 */
class WebsiteIntakeService
{
    public const SOURCE = CompanyWebsiteApplicantSourceAdapter::KEY;

    /** @var array<int, string> */
    public const CV_TYPES = ['pdf', 'doc', 'docx'];

    public function __construct(
        protected ApplicantIntakeService $intake,
        protected JobPostingLinks $links,
        protected WebsiteIntakeNotifier $notifier,
    ) {}

    /**
     * Every switched-on connection of an active company (or one company's).
     *
     * @return Collection<int, WebsiteIntakeResult>
     */
    public function pullAll(?int $companyId = null): Collection
    {
        return WebsiteConnection::query()
            ->active()
            ->when($companyId, fn ($query) => $query->forCompany((int) $companyId))
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get()
            ->map(fn (WebsiteConnection $connection): WebsiteIntakeResult => $this->pull($connection))
            ->values();
    }

    /** "Check for new applications now" on the Website intake page. */
    public function pullForCompany(User $actor, int $companyId): WebsiteIntakeResult
    {
        $this->authorize($actor, $companyId);

        $connection = WebsiteConnection::activeFor($companyId)
            ?? throw new RuntimeException('The careers website is not connected or is switched off.');

        return $this->pull($connection);
    }

    public function pull(WebsiteConnection $connection): WebsiteIntakeResult
    {
        $result = new WebsiteIntakeResult($connection);
        $lock = Cache::lock(static::lockKey($connection), 900);

        if (! $lock->get()) {
            $result->busy = true;

            return $result;
        }

        try {
            $client = WordPressCareersClient::for($connection);

            try {
                $applications = $client->applications((int) config('recruitment.website.pull_limit', 50));
            } catch (WebsiteRequestException $e) {
                $result->error = $e->getMessage();
                $connection->forceFill(['last_pull_error' => Str::limit($e->getMessage(), 1000, '')])->saveQuietly();

                return $result;
            }

            foreach ($applications as $application) {
                $this->handle($connection, $client, $application, $result, manual: false);
            }

            $connection->forceFill(['last_pulled_at' => now(), 'last_pull_error' => null])->saveQuietly();
        } finally {
            $lock->release();
        }

        $this->notifier->applicationsReceived($result->created);

        return $result;
    }

    /** HR's "Retry" on the Website intake page: import it now with what the website sent. */
    public function retry(IntakeFailure $failure, User $actor): WebsiteIntakeResult
    {
        $this->authorize($actor, (int) $failure->company_id);

        if (! $failure->isOpen()) {
            throw new RuntimeException('This application was already imported or dismissed.');
        }

        $connection = WebsiteConnection::activeFor((int) $failure->company_id);

        if (! $connection || ($failure->connection_id && (int) $failure->connection_id !== (int) $connection->id)) {
            throw new RuntimeException('Switch the careers website connection on before retrying.');
        }

        $lock = Cache::lock(static::lockKey($connection), 900);

        if (! $lock->get()) {
            throw new RuntimeException('The website is being checked right now. Try again in a minute.');
        }

        $result = new WebsiteIntakeResult($connection);

        try {
            $this->handle($connection, WordPressCareersClient::for($connection), (array) $failure->payload, $result, manual: true);
        } finally {
            $lock->release();
        }

        $this->notifier->applicationsReceived($result->created);

        return $result;
    }

    /**
     * HR decides not to import it. The website is told it was handled, so it
     * stops offering it and deletes its copy of the CV; what it sent stays
     * here as the record of the decision.
     */
    public function dismiss(IntakeFailure $failure, User $actor): ?string
    {
        $this->authorize($actor, (int) $failure->company_id);

        if (! $failure->isOpen()) {
            throw new RuntimeException('This application was already imported or dismissed.');
        }

        $failure->forceFill([
            'resolved_at' => now(),
            'resolution'  => IntakeFailure::RESOLVED_DISMISSED,
            'resolved_by' => $actor->id,
        ])->save();

        $connection = WebsiteConnection::activeFor((int) $failure->company_id);
        $applicationId = $failure->applicationId();

        if (! $connection || ! $applicationId) {
            return null;
        }

        try {
            WordPressCareersClient::for($connection)->ack($applicationId, null);
        } catch (WebsiteRequestException $e) {
            return 'Dismissed. The website could not be told yet; Aureus tells it on the next check. '.$e->getMessage();
        }

        return null;
    }

    public static function lockKey(WebsiteConnection $connection): string
    {
        return 'recruitments:website-intake:'.$connection->id;
    }

    /**
     * One application from the website. Never throws: a problem is recorded
     * as an intake failure.
     *
     * @param  array<string, mixed>  $application
     */
    protected function handle(WebsiteConnection $connection, WordPressCareersClient $client, array $application, WebsiteIntakeResult $result, bool $manual): string
    {
        $companyId = (int) $connection->company_id;
        $applicationId = filter_var($application['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $externalId = $applicationId
            ? $connection->externalIdFor($applicationId)
            : 'website:'.$connection->host().':invalid:'.substr(sha1((string) json_encode($application)), 0, 20);

        $failure = IntakeFailure::query()
            ->forCompany($companyId)
            ->where('source', self::SOURCE)
            ->where('external_id', $externalId)
            ->first();

        if ($failure && $failure->resolution === IntakeFailure::RESOLVED_DISMISSED) {
            // HR dismissed it; the website may not have heard yet.
            if ($applicationId) {
                $this->acknowledge($client, $applicationId, null, $result);
            }

            return $result->add(WebsiteIntakeResult::DISMISSED);
        }

        if (! $manual && $failure?->isOpen() && $failure->attempts >= max(1, (int) config('recruitment.website.auto_retry_attempts', 3))) {
            return $result->add(WebsiteIntakeResult::WAITING_FOR_HR);
        }

        $storedCv = null;

        try {
            if (! $applicationId) {
                throw new RuntimeException('The website sent an application without a valid id.');
            }

            // Imported before (the acknowledgement did not reach the website): acknowledge again.
            $existing = Applicant::withTrashed()
                ->where('company_id', $companyId)
                ->where('external_application_id', $externalId)
                ->first();

            if ($existing) {
                $this->resolve($failure, $existing);
                $this->acknowledgeAfterCommit($client, $applicationId, (int) $existing->id, $result);

                return $result->add(WebsiteIntakeResult::ALREADY_IMPORTED);
            }

            $data = $this->validated($application);
            $website = $this->links->resolveToken($data['job_token']);

            if (! $website) {
                throw new RuntimeException('The job of this application is not known in Aureus (or was deleted).');
            }

            if ((int) $website->company_id !== $companyId) {
                throw new RuntimeException('Refused: the job belongs to another company than this careers website.');
            }

            $job = $website->job;
            [$posting, $attributionNote] = $this->attribution($job, $website, $data['ref'], $data['src']);

            // One application per candidate per job, as the file import does.
            $alreadyApplied = Applicant::withTrashed()
                ->where('company_id', $companyId)
                ->where('job_id', $job->id)
                ->whereHas('candidate', fn ($query) => $query->where('email_from', $data['email']))
                ->first();

            if ($alreadyApplied) {
                $this->resolve($failure, $alreadyApplied);
                $this->acknowledgeAfterCommit($client, $applicationId, (int) $alreadyApplied->id, $result);

                return $result->add(WebsiteIntakeResult::ALREADY_APPLIED);
            }

            [$answers, $unknownQuestions] = $this->answers($job, (array) ($data['answers'] ?? []));
            $storedCv = $this->downloadCv($client, $applicationId, $data['cv']);

            $applicant = DB::transaction(function () use ($connection, $companyId, $externalId, $applicationId, $data, $job, $posting, $website, $attributionNote, $answers, $unknownQuestions, $storedCv, $failure): Applicant {
                $applicant = $this->intake->importForCompany(self::SOURCE, array_filter([
                    'company_id'              => $companyId,
                    'external_application_id' => $externalId,
                    'candidate_name'          => $data['name'],
                    'candidate_email'         => $data['email'],
                    'candidate_phone'         => $data['phone'],
                    'job_id'                  => (int) $job->id,
                    'job_posting_id'          => (int) $posting->id,
                    'source_details'          => $this->sourceDetails($posting, $website, $data['src'], $attributionNote),
                    'resume_path'             => $storedCv['path'] ?? null,
                    'screening_answers'       => $answers ?: null,
                ], fn ($value): bool => $value !== null), $companyId);

                $properties = (array) $applicant->applicant_properties;
                $properties['source_provenance']['website'] = array_filter([
                    'application_id'    => $applicationId,
                    'site'              => $connection->host(),
                    'submitted_at'      => $data['submitted_at'],
                    'job_token'         => $data['job_token'],
                    'ref'               => $data['ref'],
                    'src'               => $data['src'],
                    'attribution'       => $attributionNote,
                    'consent'           => $data['consent'],
                    'cv'                => $storedCv ? Arr::only($storedCv, ['filename', 'type', 'size']) : null,
                    'unknown_questions' => $unknownQuestions ?: null,
                ], fn ($value): bool => $value !== null && $value !== []);
                $applicant->forceFill(['applicant_properties' => $properties])->saveQuietly();

                $this->resolve($failure, $applicant);

                return $applicant;
            });

            $result->created->push($applicant);
            $this->acknowledgeAfterCommit($client, $applicationId, (int) $applicant->id, $result);

            return $result->add(WebsiteIntakeResult::IMPORTED);
        } catch (Throwable $e) {
            if ($storedCv) {
                Storage::disk(CandidateResource::CV_DISK)->delete($storedCv['path']);
            }

            $this->recordFailure($connection, $failure, $externalId, $application, $e);

            return $result->add(WebsiteIntakeResult::FAILED);
        }
    }

    /**
     * @param  array<string, mixed>  $application
     * @return array{job_token: string, ref: ?string, src: ?string, name: string, email: string, phone: ?string, answers: ?array, submitted_at: ?string, consent: ?array, cv: ?array}
     */
    protected function validated(array $application): array
    {
        $scalar = fn (string $key): ?string => isset($application[$key]) && is_scalar($application[$key]) && trim((string) $application[$key]) !== ''
            ? trim((string) $application[$key])
            : null;

        $input = [
            'job_token'    => Str::lower((string) $scalar('job_token')),
            'ref'          => $scalar('ref') !== null ? Str::lower((string) $scalar('ref')) : null,
            'src'          => $scalar('src'),
            'name'         => $scalar('name'),
            'email'        => $scalar('email') !== null ? Str::lower((string) $scalar('email')) : null,
            'phone'        => $scalar('phone'),
            'answers'      => $application['answers'] ?? null,
            'submitted_at' => $scalar('submitted_at'),
            'consent'      => $application['consent'] ?? null,
            'cv'           => $application['cv'] ?? null,
        ];

        return Validator::make($input, [
            'job_token'    => ['required', 'regex:/^[0-9a-f]{32}$/'],
            'ref'          => ['nullable', 'string', 'max:64'],
            'src'          => ['nullable', 'string', 'max:100'],
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'max:255'],
            'phone'        => ['nullable', 'string', 'max:64'],
            'answers'      => ['nullable', 'array'],
            'submitted_at' => ['nullable', 'string', 'max:64'],
            'consent'      => ['nullable', 'array'],
            'cv'           => ['nullable', 'array'],
        ], [
            'job_token.required' => 'The application does not say which job it is for.',
            'job_token.regex'    => 'The application\'s job token is not a valid Aureus token.',
        ])->validate();
    }

    /**
     * Which ad the application came through: the ad of "?ref=" (same job
     * only), else an ad of the job on the "src" channel, else the website ad.
     *
     * @return array{0: JobPosting, 1: string}
     */
    protected function attribution(JobPosition $job, JobPosting $website, ?string $ref, ?string $src): array
    {
        $note = 'website';

        if ($ref !== null) {
            $refPosting = $this->links->resolveToken($ref);

            if ($refPosting && (int) $refPosting->job_id === (int) $job->id && (int) $refPosting->company_id === (int) $job->company_id) {
                return [$refPosting, 'ref'];
            }

            // Another job's or company's ad, or an unknown token: never attributed.
            $note = 'ref_ignored';
        }

        if ($src !== null) {
            $key = Str::slug($src, '_');
            // A board feed link carries ?src={board}; "generic" is the aggregator_feed channel's feed.
            $candidates = array_values(array_unique(array_filter([$key, "{$key}_jobs", "{$key}_feed", app(JobPostingChannelRegistry::class)->channelForFeedBoard($key)])));

            $bySource = JobPosting::query()
                ->where('job_id', $job->id)
                ->where('company_id', $job->company_id)
                ->whereIn('channel', $candidates)
                ->whereNull('dropped_at')
                ->orderBy('id')
                ->get()
                ->sortBy(fn (JobPosting $posting): int => (int) array_search($posting->channel, $candidates, true))
                ->first();

            if ($bySource) {
                return [$bySource, $note === 'ref_ignored' ? 'src (ref ignored)' : 'src'];
            }
        }

        return [$website, $note];
    }

    protected function sourceDetails(JobPosting $posting, JobPosting $website, ?string $src, string $attributionNote): string
    {
        $details = 'Company website';

        if ((int) $posting->id !== (int) $website->id) {
            $details .= ' via '.$posting->displayName();
        } elseif ($src !== null) {
            $details .= ' (src: '.Str::limit($src, 60, '').')';
        }

        return Str::limit($details, 255, '');
    }

    /**
     * The answers to this job's questions, keyed by question id; answers to
     * questions the job no longer has are left out and reported.
     *
     * @param  array<int, mixed>  $items
     * @return array{0: array<int, mixed>, 1: array<int, int>}
     */
    protected function answers(JobPosition $job, array $items): array
    {
        $questionIds = JobQuestion::query()->where('job_id', $job->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $answers = [];
        $unknown = [];

        foreach ($items as $item) {
            $questionId = is_array($item) ? filter_var($item['question_id'] ?? null, FILTER_VALIDATE_INT) : false;

            if (! $questionId) {
                continue;
            }

            if (! in_array($questionId, $questionIds, true)) {
                $unknown[] = $questionId;

                continue;
            }

            $answers[$questionId] = $item['answer'] ?? null;
        }

        return [$answers, $unknown];
    }

    /**
     * Download, check and store the CV privately. Null when none was sent.
     *
     * @param  array<string, mixed>|null  $meta  the application's "cv" block
     * @return array{path: string, type: string, size: int, filename: ?string}|null
     */
    protected function downloadCv(WordPressCareersClient $client, int $applicationId, ?array $meta): ?array
    {
        if ($meta === null || $meta === []) {
            return null;
        }

        $maxBytes = CandidateResource::CV_MAX_KB * 1024;

        if (is_numeric($meta['size'] ?? null) && (int) $meta['size'] > $maxBytes) {
            throw new RuntimeException('The CV is larger than the '.CandidateResource::CV_MAX_KB.' KB limit.');
        }

        $declared = strtolower(pathinfo((string) ($meta['filename'] ?? ''), PATHINFO_EXTENSION));

        if ($declared !== '' && ! in_array($declared, self::CV_TYPES, true)) {
            throw new RuntimeException("The CV must be a PDF, DOC or DOCX file (it is .{$declared}).");
        }

        $file = $client->downloadCv($applicationId, $maxBytes);

        if ($file['contents'] === '') {
            throw new RuntimeException('The CV file is empty.');
        }

        $type = static::detectCvType($file['contents']);

        if ($type === null) {
            throw new RuntimeException('The CV is not a PDF, DOC or DOCX file.');
        }

        $declared = $declared ?: strtolower(pathinfo((string) $file['filename'], PATHINFO_EXTENSION));

        if ($declared !== '' && $declared !== $type && ! (in_array($declared, ['doc', 'docx'], true) && in_array($type, ['doc', 'docx'], true))) {
            throw new RuntimeException("The CV's content ({$type}) does not match its file name (.{$declared}).");
        }

        $path = 'recruitment/cv/'.Str::uuid()->toString().'.'.$type;
        Storage::disk(CandidateResource::CV_DISK)->put($path, $file['contents']);

        $filename = basename(str_replace('\\', '/', (string) ($meta['filename'] ?? $file['filename'] ?? '')));

        return [
            'path'     => $path,
            'type'     => $type,
            'size'     => strlen($file['contents']),
            'filename' => $filename !== '' ? Str::limit($filename, 200, '') : null,
        ];
    }

    /** PDF, DOC (OLE2) or DOCX (a zip with word/ parts), judged by content, never by name. */
    public static function detectCvType(string $contents): ?string
    {
        if (str_contains(substr($contents, 0, 1024), '%PDF-')) {
            return 'pdf';
        }

        if (str_starts_with($contents, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return 'doc';
        }

        if (str_starts_with($contents, "PK\x03\x04") && str_contains($contents, 'word/')) {
            return 'docx';
        }

        return null;
    }

    protected function resolve(?IntakeFailure $failure, Applicant $applicant): void
    {
        if (! $failure?->isOpen()) {
            return;
        }

        // Its data now lives in the application; the copy is not kept.
        $failure->forceFill([
            'resolved_at'  => now(),
            'resolution'   => IntakeFailure::RESOLVED_IMPORTED,
            'applicant_id' => $applicant->id,
            'payload'      => null,
        ])->save();
    }

    /** @param array<string, mixed> $application */
    protected function recordFailure(WebsiteConnection $connection, ?IntakeFailure $failure, string $externalId, array $application, Throwable $e): void
    {
        $message = match (true) {
            $e instanceof ValidationException       => 'The website sent incomplete details: '.collect($e->errors())->flatten()->implode(' '),
            $e instanceof ModelNotFoundException    => 'The job is not an open job of this company.',
            $e instanceof RuntimeException          => $e->getMessage(),
            default                                 => 'Unexpected error ('.class_basename($e).'); see the application log.',
        };

        if (! $e instanceof RuntimeException && ! $e instanceof ValidationException && ! $e instanceof ModelNotFoundException) {
            report($e);
        }

        $attributes = [
            'connection_id'   => $connection->id,
            'payload'         => $application,
            'error'           => Str::limit($message, 1900, '…'),
            'last_attempt_at' => now(),
        ];

        try {
            if ($failure) {
                $failure->forceFill($attributes + [
                    'attempts'     => $failure->isOpen() ? $failure->attempts + 1 : 1,
                    'resolved_at'  => null,
                    'resolution'   => null,
                    'resolved_by'  => null,
                    'applicant_id' => null,
                ])->save();

                return;
            }

            $failure = IntakeFailure::query()->create($attributes + [
                'company_id'  => $connection->company_id,
                'source'      => self::SOURCE,
                'external_id' => $externalId,
                'attempts'    => 1,
            ]);

            $this->notifier->failureRecorded($failure);
        } catch (Throwable $recordError) {
            // Not losing the application matters more than the record: the website keeps it until acknowledged.
            report($recordError);
        }
    }

    /** After the surrounding commit (immediately when there is none). */
    protected function acknowledgeAfterCommit(WordPressCareersClient $client, int $applicationId, int $applicantId, WebsiteIntakeResult $result): void
    {
        DB::afterCommit(fn () => $this->acknowledge($client, $applicationId, $applicantId, $result));
    }

    protected function acknowledge(WordPressCareersClient $client, int $applicationId, ?int $applicantId, WebsiteIntakeResult $result): void
    {
        try {
            $client->ack($applicationId, $applicantId);
        } catch (Throwable $e) {
            // The website offers it again; the next pull finds it imported and acknowledges again.
            $result->ackFailures++;

            if (! $e instanceof WebsiteRequestException) {
                report($e);
            }
        }
    }

    protected function authorize(User $actor, int $companyId): void
    {
        if (! WebsiteConnectionService::canManage($actor, $companyId)) {
            throw new RuntimeException('You are not allowed to manage the careers website of this company.');
        }
    }
}
