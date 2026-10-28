<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Enums\JobLocationType;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;

/**
 * The public face of a job (Recruitment automation plan, phase A2): the payload
 * pushed to the careers website (API contract v1, plan §7), the Google
 * JobPosting structured data, and what is still missing before it can go out.
 * Internal fields (headcount, recruiter, notes) never leave Aureus; a salary
 * the recruiter has not made visible is never sent.
 */
class JobPublicProfile
{
    /** Google employmentType values, matched on the employment type's name or code. */
    private const EMPLOYMENT_TYPES = [
        'FULL_TIME'  => ['full-time', 'full time', 'permanent', 'employee', 'statutory'],
        'PART_TIME'  => ['part-time', 'part time'],
        'CONTRACTOR' => ['contractor', 'contract', 'interim', 'freelance'],
        'TEMPORARY'  => ['temporary', 'seasonal'],
        'INTERN'     => ['intern', 'student', 'apprentice', 'thesis', 'trainee'],
        'VOLUNTEER'  => ['volunteer'],
    ];

    /** Tags kept in public descriptions; everything else (scripts, styles, attributes) is removed. */
    private const ALLOWED_TAGS = '<p><br><ul><ol><li><strong><b><em><i><u><h2><h3><h4><blockquote>';

    /**
     * Saving hook on jobs: a slug unique within the company (kept once set, so
     * published links never break) and a salary range that makes sense.
     */
    public function prepareForSave(JobPosition $job): void
    {
        if ($job->salary_min !== null && $job->salary_max !== null && (float) $job->salary_min > (float) $job->salary_max) {
            throw new InvalidArgumentException('The minimum salary cannot be higher than the maximum salary.');
        }

        $requested = Str::slug((string) ($job->slug ?: $this->title($job)));

        if ($requested === '') {
            return;
        }

        if ($job->exists && $job->getOriginal('slug') === $requested && ! $job->isDirty('company_id')) {
            $job->slug = $requested;

            return;
        }

        $job->slug = $this->uniqueSlug($job, $requested);
    }

    public function title(JobPosition $job): string
    {
        return trim((string) ($job->public_title ?: $job->name));
    }

    /**
     * The careers website payload (plan §7, "PUT /wp-json/aureus/v1/jobs/{token}").
     *
     * @return array<string, mixed>
     */
    public function payload(JobPosition $job): array
    {
        $job->loadMissing(['company', 'address.country', 'employmentType', 'salaryCurrency', 'company.country']);
        $location = $this->location($job);

        return [
            'company'           => $job->company?->name,
            'status'            => $job->isPubliclyVisible() ? 'published' : 'unlisted',
            'title'             => $this->title($job),
            'slug'              => $job->slug,
            'location'          => [
                'city'    => $location['city'],
                'country' => $location['country'],
                'type'    => $job->location_type?->value,
            ],
            'employment_type'   => $this->employmentType($job),
            'salary'            => $this->salary($job),
            'description_html'  => $this->cleanHtml($job->description),
            'requirements_html' => $this->cleanHtml($job->requirements),
            'experience_years'  => $job->min_experience_years,
            'date_posted'       => $job->published_at ? Carbon::parse($job->published_at)->toDateString() : null,
            'valid_through'     => $this->validThrough($job)?->toDateString(),
            'questions'         => app(ScreeningService::class)->publicQuestionsFor($job),
            'boards'            => $this->feedBoards($job),
        ];
    }

    /**
     * The website feeds (/careers/feed/{board}.xml) the job belongs in: its
     * feed-board ads (Indeed, Jooble, talent.com...) that are still on the job
     * and not closed. The careers site lists the job only in these feeds.
     *
     * @return array<int, string>
     */
    public function feedBoards(JobPosition $job): array
    {
        $registry = app(JobPostingChannelRegistry::class);
        $channels = array_map(fn ($channel): string => (string) $channel->feedBoard, $registry->feedBoardChannels());

        if ($channels === [] || ! $job->getKey()) {
            return [];
        }

        $active = JobPosting::query()
            ->where('job_id', $job->getKey())
            ->where('company_id', $job->company_id)
            ->whereIn('channel', array_keys($channels))
            ->whereNull('dropped_at')
            ->where('status', '!=', ChannelPostingStatus::CLOSED->value)
            ->pluck('channel')
            ->all();

        return array_values(array_unique(array_map(fn (string $channel): string => $channels[$channel], array_intersect(array_keys($channels), $active))));
    }

    /**
     * schema.org JobPosting for Google for Jobs, ready for a
     * <script type="application/ld+json"> tag on the job page.
     *
     * @return array<string, mixed>
     */
    public function structuredData(JobPosition $job, ?string $jobUrl = null): array
    {
        $payload = $this->payload($job);
        $location = $payload['location'];

        $data = [
            '@context'           => 'https://schema.org/',
            '@type'              => 'JobPosting',
            'title'              => $payload['title'],
            'description'        => trim($payload['description_html'].$payload['requirements_html']),
            'identifier'         => ['@type' => 'PropertyValue', 'name' => $payload['company'], 'value' => (string) $job->id],
            'datePosted'         => $payload['date_posted'],
            'validThrough'       => $payload['valid_through'],
            'employmentType'     => $payload['employment_type'],
            'hiringOrganization' => array_filter([
                '@type'  => 'Organization',
                'name'   => $payload['company'],
                'sameAs' => $job->company?->website,
            ]),
        ];

        if ($job->location_type === JobLocationType::REMOTE) {
            $data['jobLocationType'] = 'TELECOMMUTE';
            $data['applicantLocationRequirements'] = array_filter(['@type' => 'Country', 'name' => $location['country']]);
        } else {
            $data['jobLocation'] = [
                '@type'   => 'Place',
                'address' => array_filter([
                    '@type'           => 'PostalAddress',
                    'addressLocality' => $location['city'],
                    'addressCountry'  => $location['country'],
                ]),
            ];
        }

        if ($payload['salary']['visible']) {
            $data['baseSalary'] = [
                '@type'    => 'MonetaryAmount',
                'currency' => $payload['salary']['currency'],
                'value'    => array_filter([
                    '@type'    => 'QuantitativeValue',
                    'minValue' => $payload['salary']['min'],
                    'maxValue' => $payload['salary']['max'],
                    'unitText' => $payload['salary']['period'],
                ], fn ($value): bool => $value !== null),
            ];
        }

        if ($jobUrl) {
            $data['url'] = $jobUrl;
        }

        if ($payload['experience_years']) {
            $data['experienceRequirements'] = [
                '@type'              => 'OccupationalExperienceRequirements',
                'monthsOfExperience' => $payload['experience_years'] * 12,
            ];
        }

        return array_filter($data, fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * What the job still needs before the careers website and Google accept it.
     * Advisory: it never blocks publishing.
     *
     * @return array<int, string>
     */
    public function missingForWebsite(JobPosition $job): array
    {
        $missing = [];

        if (blank(strip_tags((string) $job->description))) {
            $missing[] = 'A job description.';
        }

        if (! $job->location_type) {
            $missing[] = 'Where the work is done (on-site, hybrid or remote).';
        } elseif ($job->location_type->needsPresence() && blank($this->location($job)['city'])) {
            $missing[] = 'A work address with a city (on-site and hybrid jobs).';
        }

        $validThrough = $this->validThrough($job);

        if (! $validThrough) {
            $missing[] = 'An "apply before" date (Google requires it).';
        } elseif ($validThrough->isPast()) {
            $missing[] = 'The "apply before" date has passed.';
        }

        if ($job->salary_visible && $job->salary_min === null && $job->salary_max === null) {
            $missing[] = 'A salary amount (the salary is set to be shown).';
        }

        return $missing;
    }

    /** @return array{city: ?string, country: ?string} */
    public function location(JobPosition $job): array
    {
        $address = $job->address;
        $company = $job->company;

        return [
            'city'    => $address?->city ?: $company?->city,
            'country' => $address?->country?->code ?: $company?->country?->code,
        ];
    }

    public function employmentType(JobPosition $job): ?string
    {
        $type = $job->employmentType;

        if (! $type) {
            return null;
        }

        $haystack = Str::lower($type->name.' '.$type->code);

        foreach (self::EMPLOYMENT_TYPES as $google => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $google;
                }
            }
        }

        return 'OTHER';
    }

    /** @return array{min: ?float, max: ?float, currency: ?string, period: string, visible: bool} */
    public function salary(JobPosition $job): array
    {
        $visible = (bool) $job->salary_visible && ($job->salary_min !== null || $job->salary_max !== null);

        return [
            'min'      => $visible && $job->salary_min !== null ? (float) $job->salary_min : null,
            'max'      => $visible && $job->salary_max !== null ? (float) $job->salary_max : null,
            'currency' => $visible ? ($job->salaryCurrency?->code ?? $job->company?->currency?->code) : null,
            'period'   => $job->salary_period ?: 'MONTH',
            'visible'  => $visible,
        ];
    }

    public function validThrough(JobPosition $job): ?Carbon
    {
        $date = $job->valid_through ?? $job->date_to;

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    public function cleanHtml(?string $html): string
    {
        $clean = strip_tags((string) $html, self::ALLOWED_TAGS);

        return trim((string) preg_replace('/<(\w+)\s[^>]*>/', '<$1>', $clean));
    }

    private function uniqueSlug(JobPosition $job, string $base): string
    {
        $base = Str::limit($base, 180, '');
        $slug = $base;
        $suffix = 2;

        while (JobPosition::withTrashed()
            ->where('company_id', $job->company_id)
            ->where('slug', $slug)
            ->when($job->exists, fn ($query) => $query->whereKeyNot($job->getKey()))
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
