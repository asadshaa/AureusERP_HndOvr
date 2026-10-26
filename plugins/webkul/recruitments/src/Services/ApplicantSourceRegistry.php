<?php

namespace Webkul\Recruitment\Services;

use InvalidArgumentException;
use Webkul\Recruitment\Contracts\ApplicantSourceAdapter;
use Webkul\Recruitment\Services\SourceAdapters\FileImportApplicantSourceAdapter;
use Webkul\Recruitment\Services\SourceAdapters\GenericApiApplicantSourceAdapter;
use Webkul\Recruitment\Services\SourceAdapters\ManualApplicantSourceAdapter;

class ApplicantSourceRegistry
{
    /** @var array<string, ApplicantSourceAdapter> */
    private array $adapters = [];

    public function __construct()
    {
        $this->register(new ManualApplicantSourceAdapter);
        $this->register(new GenericApiApplicantSourceAdapter);
        $this->register(new FileImportApplicantSourceAdapter);
    }

    public function register(ApplicantSourceAdapter $adapter): void
    {
        $this->adapters[$adapter->key()] = $adapter;
    }

    public function resolve(string $key): ApplicantSourceAdapter
    {
        return $this->adapters[$key]
            ?? throw new InvalidArgumentException("Unknown applicant source adapter [{$key}].");
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->adapters);
    }
}
