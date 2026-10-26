<?php

namespace Webkul\Recruitment\Support;

/**
 * Plain-text job ad prepared for a recruiter to paste on a board or group.
 * Always plain text: render it escaped, never as HTML.
 */
final readonly class JobPostingText
{
    public function __construct(
        public string $title,
        public ?string $company,
        public ?string $location,
        public ?string $employmentType,
        public string $body,
        public string $applyLine,
        public string $fullText,
    ) {}
}
