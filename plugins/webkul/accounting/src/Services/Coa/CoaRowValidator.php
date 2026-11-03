<?php

namespace Webkul\Accounting\Services\Coa;

use Webkul\Account\Enums\AccountType;
use Webkul\Accounting\Data\Coa\CoaRow;
use Webkul\Accounting\Data\Coa\CoaWarning;

/**
 * Validates parsed CoA rows and flags suspicious data WITHOUT changing it.
 *
 * Hard errors block the import (duplicate code within the file, empty code or
 * title). Everything else is a warning the user acknowledges: misspelled
 * classifications, non-numeric codes, duplicate titles, and nature/section
 * mismatches (input tax under liabilities, provision under assets, carrier
 * costs under revenue, P&L rows classified under Assets).
 */
class CoaRowValidator
{
    /**
     * @var array<int, string> substrings flagged as likely misspellings
     */
    protected array $misspellings = [
        'libailities',
        'intanbgible',
        'guarrantee',
    ];

    public function __construct(
        protected CoaAccountTypeMapper $typeMapper = new CoaAccountTypeMapper,
    ) {}

    /**
     * $typeOverrides (account code => AccountType value, as chosen in the
     * import preview) are checked against each row's Nature: an override
     * that contradicts it (an expense type on a B/S row, an asset type on a
     * P&L row) or is not a valid type is a blocking error.
     *
     * @param  array<int, CoaRow>  $rows
     * @param  array<string, string>  $typeOverrides
     * @return array<int, CoaWarning>
     */
    public function validate(array $rows, array $typeOverrides = []): array
    {
        $warnings = [];

        $codeCounts = [];
        $titleCounts = [];
        foreach ($rows as $row) {
            $codeCounts[CoaRow::normalizeCode($row->code)] = ($codeCounts[CoaRow::normalizeCode($row->code)] ?? 0) + 1;
            $titleCounts[mb_strtolower($row->title)] = ($titleCounts[mb_strtolower($row->title)] ?? 0) + 1;
        }

        foreach ($rows as $row) {
            $path = mb_strtolower($row->classificationPathLabel());
            $title = mb_strtolower($row->title);
            $class1 = mb_strtolower($row->classifications[0] ?? '');
            $nature = mb_strtolower($row->nature);

            if ($row->code === '') {
                $warnings[] = CoaWarning::error('empty_code', "Row {$row->sheetRow}: missing account code.", $row->sheetRow);
            }
            if ($row->title === '') {
                $warnings[] = CoaWarning::error('empty_title', "Row {$row->sheetRow}: missing account title.", $row->sheetRow);
            }

            if ($row->code !== '' && $codeCounts[CoaRow::normalizeCode($row->code)] > 1) {
                $warnings[] = CoaWarning::error(
                    'duplicate_code',
                    "Duplicate account code '{$row->code}' appears {$codeCounts[CoaRow::normalizeCode($row->code)]} times in the file (codes are compared ignoring case and a trailing \".0\").",
                    $row->sheetRow, $row->code,
                );
            }

            if ($row->code !== '' && ! preg_match('/^\d+$/', $row->code)) {
                $warnings[] = CoaWarning::warning(
                    'non_numeric_code',
                    "Account code '{$row->code}' is not numeric (e.g. \"New code\") — review before import.",
                    $row->sheetRow, $row->code,
                );
            }

            foreach ($this->misspellings as $needle) {
                if (str_contains($path, $needle)) {
                    $warnings[] = CoaWarning::warning(
                        'possible_misspelling',
                        "Row {$row->sheetRow} ('{$row->title}'): possible misspelling in classification (\"{$needle}\").",
                        $row->sheetRow, $row->code,
                    );
                    break;
                }
            }

            if ($titleCounts[$title] > 1) {
                $warnings[] = CoaWarning::warning(
                    'duplicate_title',
                    "Title '{$row->title}' appears {$titleCounts[$title]} times — confirm these are distinct accounts.",
                    $row->sheetRow, $row->code,
                );
            }

            if ($row->code !== '' && array_key_exists($row->code, $typeOverrides)) {
                $override = AccountType::tryFrom((string) $typeOverrides[$row->code]);

                if ($override === null) {
                    $warnings[] = CoaWarning::error(
                        'invalid_type_override',
                        "Row {$row->sheetRow} ('{$row->title}'): '{$typeOverrides[$row->code]}' is not a valid account type.",
                        $row->sheetRow, $row->code,
                    );
                } elseif (! $this->typeMapper->natureAllows($row, $override)) {
                    $warnings[] = CoaWarning::error(
                        'nature_type_mismatch',
                        "Row {$row->sheetRow} ('{$row->title}'): account type '{$override->value}' contradicts Nature '{$row->nature}'.",
                        $row->sheetRow, $row->code,
                    );
                }
            }

            if ($nature === 'p&l' && str_contains($class1, 'asset')) {
                $warnings[] = CoaWarning::warning(
                    'nature_section_mismatch',
                    "Row {$row->sheetRow} ('{$row->title}'): Nature is P&L but classified under Assets.",
                    $row->sheetRow, $row->code,
                );
            }

            if (str_contains($title, 'input sales tax') && str_contains($path, 'liab')) {
                $warnings[] = CoaWarning::warning(
                    'input_tax_under_liability',
                    "Row {$row->sheetRow}: 'Input sales tax' is classified under liabilities (input tax is usually an asset).",
                    $row->sheetRow, $row->code,
                );
            }

            if (str_contains($title, 'provision for taxation') && str_contains($class1, 'asset')) {
                $warnings[] = CoaWarning::warning(
                    'provision_under_asset',
                    "Row {$row->sheetRow}: 'Provision for Taxation' is classified under assets (a provision is usually a liability).",
                    $row->sheetRow, $row->code,
                );
            }

            if (str_contains($title, 'trucker') && str_contains($class1, 'revenue')) {
                $warnings[] = CoaWarning::warning(
                    'cost_as_revenue',
                    "Row {$row->sheetRow} ('{$row->title}'): a carrier cost is classified under Revenue.",
                    $row->sheetRow, $row->code,
                );
            }
        }

        return $warnings;
    }
}
