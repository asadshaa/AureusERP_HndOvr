<?php

namespace Webkul\Accounting\Data\Coa;

/**
 * One parsed data row from a Chart-of-Accounts sheet: its raw classification
 * path, code/title, and the four debit/credit balance pairs (as decimals).
 */
final class CoaRow
{
    /**
     * @param  array<int, string>  $classifications  non-empty classification segments, in order
     */
    public function __construct(
        public readonly int $sheetRow,       // 1-based row number for messages
        public readonly string $nature,
        public readonly array $classifications,
        public readonly string $code,
        public readonly string $title,
        public readonly float $openingDebit,
        public readonly float $openingCredit,
        public readonly float $movementDebit,
        public readonly float $movementCredit,
        public readonly float $adjustmentDebit,
        public readonly float $adjustmentCredit,
        public readonly float $closingDebit,
        public readonly float $closingCredit,
        public readonly array $classificationValues = [],
        public readonly array $rawRow = [],
        public readonly array $rawRowByHeader = [],
        public readonly array $sourceHeaders = [],
    ) {}

    /**
     * The full path used to build the group hierarchy: Nature first, then each
     * classification segment. Consecutive duplicate segments are collapsed so
     * "Cost > Cost" does not create two nested identical nodes.
     *
     * @return array<int, string>
     */
    public function groupPath(): array
    {
        $path = [];
        $previous = null;

        foreach ([$this->nature, ...$this->classifications] as $segment) {
            $segment = trim((string) $segment);

            if ($segment === '' || $segment === $previous) {
                continue;
            }

            $path[] = $segment;
            $previous = $segment;
        }

        return $path;
    }

    /**
     * The code as compared for duplicates and re-import matching: trimmed,
     * case-insensitive, and with a spreadsheet's numeric ".0" suffix dropped
     * (A100 = a100, 1011 = 1011.0).
     */
    public static function normalizeCode(?string $code): string
    {
        $code = mb_strtoupper(trim((string) $code));

        if (preg_match('/^(\d+)\.0+$/', $code, $matches) === 1) {
            return $matches[1];
        }

        return $code;
    }

    public function classificationPathLabel(): string
    {
        return implode(' > ', [$this->nature, ...$this->classifications]);
    }

    public function hasAnyBalance(): bool
    {
        return abs($this->openingDebit) + abs($this->openingCredit)
            + abs($this->movementDebit) + abs($this->movementCredit)
            + abs($this->adjustmentDebit) + abs($this->adjustmentCredit) > 0.0;
    }
}
