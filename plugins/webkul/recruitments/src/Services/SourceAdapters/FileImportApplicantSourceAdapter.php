<?php

namespace Webkul\Recruitment\Services\SourceAdapters;

use Webkul\Recruitment\Contracts\ApplicantSourceAdapter;

/** Applications imported from a CSV/Excel export (LinkedIn, Rozee.pk, Indeed...). */
class FileImportApplicantSourceAdapter implements ApplicantSourceAdapter
{
    public function key(): string
    {
        return 'import';
    }

    public function normalize(array $payload): array
    {
        $payload['source_details'] ??= 'File import';

        return $payload;
    }
}
