<?php

namespace Webkul\Recruitment\Services\SourceAdapters;

use Illuminate\Support\Str;
use Webkul\Recruitment\Contracts\ApplicantSourceAdapter;

/**
 * Applications pulled from the company careers website (automation plan
 * §6 A4, WebsiteIntakeService). Accepts the website's own field names too
 * (name, email, phone) and lower-cases the email, so the same person is
 * matched to one candidate whatever case they typed.
 */
class CompanyWebsiteApplicantSourceAdapter implements ApplicantSourceAdapter
{
    public const KEY = 'company_website';

    public function key(): string
    {
        return self::KEY;
    }

    public function normalize(array $payload): array
    {
        $email = $payload['candidate_email'] ?? $payload['email'] ?? null;

        return [
            ...$payload,
            'candidate_name'  => isset($payload['candidate_name']) || isset($payload['name'])
                ? Str::limit(trim((string) ($payload['candidate_name'] ?? $payload['name'])), 255, '')
                : null,
            'candidate_email' => is_string($email) ? Str::lower(trim($email)) : $email,
            'candidate_phone' => $payload['candidate_phone'] ?? $payload['phone'] ?? null,
            'source_details'  => $payload['source_details'] ?? 'Company website',
        ];
    }
}
