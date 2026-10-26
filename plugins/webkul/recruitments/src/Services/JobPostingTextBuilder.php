<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Str;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Support\JobPostingText;

/**
 * Builds the plain-text ad a recruiter pastes on a board or group, from the
 * job's description and requirements (RichEditor HTML). Social and messaging
 * channels get a short version. The apply line never contains a link that is
 * not live yet (e.g. localhost).
 */
class JobPostingTextBuilder
{
    public function __construct(protected JobPostingLinks $links) {}

    public function build(JobPosting $posting): JobPostingText
    {
        $posting->loadMissing(['job.company', 'job.address', 'job.employmentType']);
        $job = $posting->job;

        $title = (string) $job->name;
        $company = $job->company?->name;
        $location = $this->location($job->address);
        $employmentType = $job->employmentType?->name;
        $description = $this->htmlToText((string) $job->description);
        $requirements = $this->htmlToText((string) $job->requirements);
        $applyLine = $this->applyLine($posting);

        $header = implode(' · ', array_filter([$company, $location, $employmentType]));

        if ($posting->definition()->shortPost) {
            $bullets = collect(preg_split('/\n+/', $requirements) ?: [])
                ->map(fn (string $line): string => trim($line))
                ->filter()
                ->take(5)
                ->map(fn (string $line): string => Str::startsWith($line, '• ') ? $line : '• '.$line)
                ->implode("\n");

            $body = $bullets;
            $fullText = implode("\n\n", array_filter(["We're hiring: {$title}", $header, $bullets, $applyLine]));
        } else {
            $body = implode("\n\n", array_filter([
                $description,
                $requirements !== '' ? "Requirements:\n{$requirements}" : null,
            ]));
            $fullText = implode("\n\n", array_filter([$title, $header, $body, $applyLine]));
        }

        return new JobPostingText(
            title: $title,
            company: $company,
            location: $location,
            employmentType: $employmentType,
            body: $body,
            applyLine: $applyLine,
            fullText: $fullText,
        );
    }

    public function applyLine(JobPosting $posting): string
    {
        if ($this->links->linksAreLive()) {
            return 'Apply here: '.$this->links->applyUrl($posting);
        }

        $email = config('recruitment.careers.apply_email');
        if (filled($email)) {
            return "To apply, email your CV to {$email} with the subject \"{$posting->job->name}\".";
        }

        return 'Apply through this post.';
    }

    public function htmlToText(string $html): string
    {
        $text = preg_replace(['/<\s*li[^>]*>/i', '/<\s*(br|\/p|\/li|\/h[1-6]|\/div)\s*\/?>/i'], ['• ', "\n"], $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    protected function location(mixed $address): ?string
    {
        if (! $address) {
            return null;
        }

        $parts = array_filter([
            $address->city ?? null,
            $address->state?->name ?? null,
            $address->country?->name ?? null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
