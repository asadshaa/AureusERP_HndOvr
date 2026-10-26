<?php

namespace Webkul\Recruitment\Support;

/** Outcome of one bulk applicant import: what happened to each data row. */
final class ApplicantImportResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $alreadyApplied = 0;

    /** @var array<int, string> spreadsheet row number => reason */
    public array $skipped = [];

    /** @var array<int, string> spreadsheet row number => reason */
    public array $failed = [];

    public int $flagged = 0;

    public function total(): int
    {
        return $this->created + $this->updated + $this->alreadyApplied + count($this->skipped) + count($this->failed);
    }

    public function summary(): string
    {
        return sprintf(
            '%d new, %d updated, %d already applied, %d skipped, %d failed (%d flagged by screening).',
            $this->created,
            $this->updated,
            $this->alreadyApplied,
            count($this->skipped),
            count($this->failed),
            $this->flagged,
        );
    }

    /** Row-by-row problems, for the result notification. */
    public function problemLines(int $limit = 50): array
    {
        $lines = [];

        foreach ($this->failed as $row => $reason) {
            $lines[$row] = "Row {$row}: {$reason}";
        }

        foreach ($this->skipped as $row => $reason) {
            $lines[$row] = "Row {$row} skipped: {$reason}";
        }

        ksort($lines);

        return array_slice(array_values($lines), 0, $limit);
    }
}
