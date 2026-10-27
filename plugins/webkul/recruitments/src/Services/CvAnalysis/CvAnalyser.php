<?php

namespace Webkul\Recruitment\Services\CvAnalysis;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Recruitment\Enums\JobLocationType;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Services\JobPublicProfile;

/**
 * Free CV analyser (Recruitment automation plan, phase A5a). Scores an
 * application 0-100 against its job:
 *  - keywords (weight 60): the job's skills and CV keywords found in the CV;
 *  - experience (30): years found in the CV against the job's minimum;
 *  - location (10): an on-site or hybrid job and a CV that asks for remote
 *    work only (the score is then capped and the application flagged).
 * Criteria the job does not set are left out of the score. The result is
 * stored on the application (screening_score plus applicant_properties
 * .cv_analysis with the reasons). It never rejects or moves an applicant.
 */
class CvAnalyser
{
    public const VERSION = 1;

    public function __construct(
        protected CvTextExtractor $extractor,
        protected JobPublicProfile $jobProfile,
    ) {}

    /**
     * Analyse and store. Returns the analysis, or null when the application
     * has no job or the job belongs to another company.
     *
     * @return array<string, mixed>|null
     */
    public function analyse(Applicant $applicant): ?array
    {
        $applicant->loadMissing(['candidate', 'job.skills', 'job.address', 'job.company']);
        $job = $applicant->job;
        $candidate = $applicant->candidate;

        if (! $job || ! $candidate || (int) $job->company_id !== (int) $applicant->company_id) {
            return null;
        }

        $text = $this->cvText($candidate);
        $analysis = $this->score($text, $job) + [
            'version'     => self::VERSION,
            'analysed_at' => now()->toIso8601String(),
            'text_source' => $candidate->cv_text_source,
            'text_chars'  => mb_strlen($text),
        ];

        $properties = (array) ($applicant->applicant_properties ?? []);
        $properties['cv_analysis'] = $analysis;

        $applicant->forceFill([
            'screening_score'      => $analysis['score'],
            'applicant_properties' => $properties,
        ])->saveQuietly();

        return $analysis;
    }

    /**
     * The scoring itself, deterministic for a given CV text and job.
     *
     * @return array{status: string, score: ?int, matched: array<int, string>, missing: array<int, string>, experience_years: ?float, required_years: ?int, flags: array<int, string>, reasons: array<int, string>}
     */
    public function score(string $text, JobPosition $job): array
    {
        $result = [
            'status' => 'analysed', 'score' => null, 'matched' => [], 'missing' => [],
            'experience_years' => null, 'required_years' => $job->min_experience_years ?: null,
            'flags' => [], 'reasons' => [],
        ];

        if (trim($text) === '') {
            $result['status'] = 'no_text';
            $result['reasons'][] = 'No readable text in the CV (missing, scanned image or unsupported file). Review it by hand.';

            return $result;
        }

        $haystack = $this->normalise($text);
        $weights = (array) config('recruitment.cv_analysis.weights', ['keywords' => 60, 'experience' => 30, 'location' => 10]);
        $earned = 0.0;
        $possible = 0.0;

        $terms = $this->terms($job);

        if ($terms !== []) {
            foreach ($terms as $term) {
                if ($this->contains($haystack, $term)) {
                    $result['matched'][] = $term;
                } else {
                    $result['missing'][] = $term;
                }
            }

            $possible += $weights['keywords'];
            $earned += $weights['keywords'] * count($result['matched']) / count($terms);
            $result['reasons'][] = sprintf('Keywords: %d of %d found%s.', count($result['matched']), count($terms),
                $result['missing'] ? ' (missing: '.implode(', ', $result['missing']).')' : '');
        }

        $years = $this->experienceYears($haystack);
        $result['experience_years'] = $years;

        if ($result['required_years']) {
            $possible += $weights['experience'];

            if ($years === null) {
                $earned += $weights['experience'] / 2;
                $result['reasons'][] = "Experience: could not find years of experience in the CV (job asks for {$result['required_years']}).";
            } else {
                $earned += $weights['experience'] * min($years / $result['required_years'], 1);
                $result['reasons'][] = sprintf('Experience: about %s years found, job asks for %d.', $this->years($years), $result['required_years']);
            }
        } elseif ($years !== null) {
            $result['reasons'][] = sprintf('Experience: about %s years found.', $this->years($years));
        }

        $locationType = $job->location_type;

        if ($locationType) {
            $possible += $weights['location'];
            $remotePhrase = $locationType->needsPresence() ? $this->remoteOnlyPhrase($haystack) : null;
            $city = $this->jobProfile->location($job)['city'];

            if ($remotePhrase) {
                $result['flags'][] = 'remote_only';
                $result['reasons'][] = "Location: the job is {$locationType->getLabel()} but the CV says \"{$remotePhrase}\".";
            } else {
                $earned += $weights['location'];
                $result['reasons'][] = $locationType === JobLocationType::REMOTE
                    ? 'Location: remote job.'
                    : "Location: no remote-only request in the CV ({$locationType->getLabel()} job).";
            }

            if ($city && $locationType->needsPresence() && $this->contains($haystack, $city)) {
                $result['reasons'][] = "Location: the CV mentions {$city}.";
            }
        }

        if ($possible <= 0) {
            $result['status'] = 'no_criteria';
            $result['reasons'][] = 'The job has no CV keywords, skills, minimum experience or work location to score against.';

            return $result;
        }

        $score = (int) round($earned / $possible * 100);

        if (in_array('remote_only', $result['flags'], true)) {
            $score = min($score, (int) config('recruitment.cv_analysis.remote_only_cap', 40));
        }

        $result['score'] = $score;

        return $result;
    }

    /**
     * The CV text, extracted once per file and kept on the candidate.
     */
    public function cvText(Candidate $candidate): string
    {
        $path = (string) $candidate->resume_path;
        $disk = Storage::disk(CandidateResource::CV_DISK);

        if ($path === '' || ! $disk->exists($path)) {
            if ($candidate->cv_text !== null) {
                $candidate->forceFill(['cv_text' => null, 'cv_text_source' => null, 'cv_text_fingerprint' => null, 'cv_text_extracted_at' => null])->saveQuietly();
            }

            return '';
        }

        $fingerprint = hash('sha256', $path.'|'.$disk->size($path).'|'.$disk->lastModified($path));

        if ($candidate->cv_text_fingerprint === $fingerprint && $candidate->cv_text !== null) {
            return (string) $candidate->cv_text;
        }

        ['text' => $text, 'source' => $source] = $this->extractor->extract($disk->path($path), $path);

        $candidate->forceFill([
            'cv_text'              => $text,
            'cv_text_source'       => $source,
            'cv_text_fingerprint'  => $fingerprint,
            'cv_text_extracted_at' => now(),
        ])->saveQuietly();

        return $text;
    }

    /** @return array<int, string> the job's skills and CV keywords, de-duplicated */
    public function terms(JobPosition $job): array
    {
        return collect($job->skills->pluck('name'))
            ->merge((array) ($job->screening_keywords ?? []))
            ->map(fn ($term): string => trim((string) $term))
            ->filter()
            ->unique(fn (string $term): string => Str::lower($term))
            ->values()
            ->all();
    }

    /**
     * Years of experience: the larger of a stated "N years" and the total of
     * the date ranges in the CV (overlaps counted once).
     */
    public function experienceYears(string $haystack): ?float
    {
        $stated = null;

        if (preg_match_all('/(\d{1,2}(?:\.\d)?)\s*\+?\s*(?:years?|yrs?)\b/u', $haystack, $matches)) {
            $values = array_filter(array_map('floatval', $matches[1]), fn (float $value): bool => $value > 0 && $value <= 45);
            $stated = $values ? max($values) : null;
        }

        $currentYear = (int) now()->year;
        $intervals = [];

        if (preg_match_all('/\b((?:19|20)\d{2})\s*(?:-|–|—|to|till|until)\s*((?:19|20)\d{2}|present|current|now|date|today|till date)\b/u', $haystack, $ranges, PREG_SET_ORDER)) {
            foreach ($ranges as [, $from, $to]) {
                $start = (int) $from;
                $end = ctype_digit($to) ? (int) $to : $currentYear;

                if ($start <= $end && $end <= $currentYear + 1 && $currentYear - $start <= 50) {
                    $intervals[] = [$start, $end];
                }
            }
        }

        $fromRanges = $intervals ? $this->mergedYears($intervals) : null;

        if ($stated === null && $fromRanges === null) {
            return null;
        }

        return round(max((float) $stated, (float) $fromRanges), 1);
    }

    public function remoteOnlyPhrase(string $haystack): ?string
    {
        foreach ((array) config('recruitment.cv_analysis.remote_only_phrases', []) as $phrase) {
            if ($this->contains($haystack, (string) $phrase)) {
                return (string) $phrase;
            }
        }

        return null;
    }

    public function contains(string $haystack, string $term): bool
    {
        $needle = $this->normalise($term);

        if ($needle === '') {
            return false;
        }

        $pattern = '/(?<![\p{L}\p{N}])'.str_replace('\ ', '\s+', preg_quote($needle, '/')).'(?:s|es)?(?![\p{L}\p{N}])/u';

        return (bool) preg_match($pattern, $haystack);
    }

    protected function normalise(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $text = str_replace(['–', '—'], '-', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @param  array<int, array{0: int, 1: int}>  $intervals */
    protected function mergedYears(array $intervals): float
    {
        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $total = 0;
        [$start, $end] = array_shift($intervals);

        foreach ($intervals as [$from, $to]) {
            if ($from <= $end) {
                $end = max($end, $to);

                continue;
            }

            $total += $end - $start;
            [$start, $end] = [$from, $to];
        }

        return (float) ($total + $end - $start);
    }

    protected function years(float $years): string
    {
        return rtrim(rtrim(number_format($years, 1), '0'), '.');
    }

    /** When the stored analysis was made. */
    public static function analysedAt(Applicant $applicant): ?Carbon
    {
        $at = $applicant->applicant_properties['cv_analysis']['analysed_at'] ?? null;

        return $at ? Carbon::parse($at) : null;
    }
}
