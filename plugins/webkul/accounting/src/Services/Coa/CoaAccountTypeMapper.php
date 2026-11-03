<?php

namespace Webkul\Accounting\Services\Coa;

use Webkul\Account\Enums\AccountType;
use Webkul\Accounting\Data\Coa\CoaRow;

/**
 * Suggests an AccountType for a leaf account from its Nature + classification
 * path, following the agreed default mapping. Suggestions are only defaults:
 * the import UI lets the user override any account's type before confirming
 * (the import stores those overrides), so nothing here is silently forced.
 *
 * A suggestion never crosses the row's Nature: a P&L row only gets income or
 * expense types (and within P&L, a row under Expenses/Revenue only that side),
 * a B/S row only asset, liability or equity types. Unmatched B/S rows fall
 * back to their section (Reserves -> equity). Matching uses the
 * classification path first and the title only as a fallback, so a title such
 * as "Bad debts - trade debts written off" cannot turn an expense into a
 * receivable.
 */
class CoaAccountTypeMapper
{
    /**
     * Control-account titles that decide the type on their own (when the
     * row's Nature allows it).
     *
     * @return array<int, array{needle: string, type: AccountType}>
     */
    protected function titleRules(): array
    {
        return [
            ['needle' => 'trade payable', 'type' => AccountType::LIABILITY_PAYABLE],
            ['needle' => 'accounts payable', 'type' => AccountType::LIABILITY_PAYABLE],
            ['needle' => 'account payable', 'type' => AccountType::LIABILITY_PAYABLE],
            ['needle' => 'trade creditor', 'type' => AccountType::LIABILITY_PAYABLE],
            ['needle' => 'trade receivable', 'type' => AccountType::ASSET_RECEIVABLE],
            ['needle' => 'accounts receivable', 'type' => AccountType::ASSET_RECEIVABLE],
            ['needle' => 'account receivable', 'type' => AccountType::ASSET_RECEIVABLE],
            ['needle' => 'trade debtor', 'type' => AccountType::ASSET_RECEIVABLE],
        ];
    }

    /**
     * @return array<int, array{needle: string, type: AccountType}> ordered, most specific first
     */
    protected function rules(): array
    {
        return [
            // Assets — specific before generic.
            ['needle' => 'cash & bank', 'type' => AccountType::ASSET_CASH],
            ['needle' => 'cash at bank', 'type' => AccountType::ASSET_CASH],
            ['needle' => 'trade debt', 'type' => AccountType::ASSET_RECEIVABLE],
            ['needle' => 'receivable', 'type' => AccountType::ASSET_RECEIVABLE],
            ['needle' => 'accumulated depreciation', 'type' => AccountType::ASSET_NON_CURRENT],
            ['needle' => 'property and equipment', 'type' => AccountType::ASSET_FIXED],
            ['needle' => 'intangible', 'type' => AccountType::ASSET_FIXED],
            ['needle' => 'fixed asset', 'type' => AccountType::ASSET_FIXED],
            ['needle' => 'prepay', 'type' => AccountType::ASSET_PREPAYMENTS],
            ['needle' => 'advance tax', 'type' => AccountType::ASSET_CURRENT],
            ['needle' => 'provision for taxation', 'type' => AccountType::LIABILITY_CURRENT],

            // Liabilities.
            ['needle' => 'tax payable', 'type' => AccountType::LIABILITY_CURRENT],
            ['needle' => 'trade payable', 'type' => AccountType::LIABILITY_PAYABLE],
            ['needle' => 'accounts payable', 'type' => AccountType::LIABILITY_PAYABLE],
            ['needle' => 'payable', 'type' => AccountType::LIABILITY_CURRENT],
            ['needle' => 'accrued', 'type' => AccountType::LIABILITY_CURRENT],
            ['needle' => 'current liabilit', 'type' => AccountType::LIABILITY_CURRENT],
            ['needle' => 'liabilit', 'type' => AccountType::LIABILITY_CURRENT],

            // Equity.
            ['needle' => 'accumulated losses', 'type' => AccountType::EQUITY_UNAFFECTED],
            ['needle' => 'retained earning', 'type' => AccountType::EQUITY_UNAFFECTED],
            ['needle' => 'share premium', 'type' => AccountType::EQUITY],
            ['needle' => 'share capital', 'type' => AccountType::EQUITY],
            ['needle' => 'advance against issuance', 'type' => AccountType::EQUITY],

            // Income.
            ['needle' => 'other income', 'type' => AccountType::INCOME_OTHER],
            ['needle' => 'revenue', 'type' => AccountType::INCOME],

            // Expenses.
            ['needle' => 'depreciation', 'type' => AccountType::EXPENSE_DEPRECIATION],
            ['needle' => 'amortization', 'type' => AccountType::EXPENSE_DEPRECIATION],
            ['needle' => 'fleet subsidy', 'type' => AccountType::EXPENSE_DIRECT_COST],
            ['needle' => 'carrier cost', 'type' => AccountType::EXPENSE_DIRECT_COST],
            ['needle' => 'direct expense', 'type' => AccountType::EXPENSE_DIRECT_COST],
            ['needle' => 'finance cost', 'type' => AccountType::EXPENSE],
        ];
    }

    public function suggest(CoaRow $row): AccountType
    {
        $allowed = $this->allowedTypes($row);
        $path = mb_strtolower($row->classificationPathLabel());
        $title = mb_strtolower($row->title);

        $candidates = [
            [$title, $this->titleRules()],
            [$path, $this->rules()],
            [$title, $this->rules()],
        ];

        foreach ($candidates as [$haystack, $rules]) {
            foreach ($rules as $rule) {
                if (str_contains($haystack, $rule['needle']) && in_array($rule['type'], $allowed, true)) {
                    return $rule['type'];
                }
            }
        }

        // Fall back to the Nature bucket so every row still gets a sane type.
        return $this->fallbackByNature($row);
    }

    /**
     * The account types the row's Nature (and, where it is clear, its
     * top-level section) allows. A suggestion or an override outside this
     * list contradicts the source sheet.
     *
     * @return array<int, AccountType>
     */
    public function allowedTypes(CoaRow $row): array
    {
        $byNature = $this->natureTypes($row);

        // Within B/S the sheet's own section is often wrong (a provision
        // filed under assets): the Nature is the constraint there and the
        // validator warns about the section. Within P&L the section decides
        // income versus expense.
        $bySection = $this->nature($row) === 'bs' ? null : $this->sectionTypes($row);

        if ($bySection === null) {
            return $byNature;
        }

        $both = array_values(array_filter($bySection, fn (AccountType $type): bool => in_array($type, $byNature, true)));

        return $both === [] ? $byNature : $both;
    }

    /**
     * Whether the type is consistent with the row's Nature (P&L rows take
     * income/expense types, B/S rows asset/liability/equity types).
     */
    public function natureAllows(CoaRow $row, AccountType $type): bool
    {
        return in_array($type, $this->natureTypes($row), true);
    }

    /**
     * A coarse type for group nodes and unmatched leaves, from the top-level
     * classification / nature. Group nodes are non-postable so their type is
     * only cosmetic; leaf fallbacks are the safest bucket per section.
     */
    public function fallbackByNature(CoaRow $row): AccountType
    {
        $section = $this->section($row);
        $nature = $this->nature($row);

        $type = match ($section) {
            'asset'     => AccountType::ASSET_CURRENT,
            'liability' => AccountType::LIABILITY_CURRENT,
            'equity'    => AccountType::EQUITY,
            'income'    => str_contains(mb_strtolower($row->classifications[0] ?? ''), 'revenue') ? AccountType::INCOME : AccountType::INCOME_OTHER,
            'expense'   => AccountType::EXPENSE,
            default     => null,
        };

        if ($type !== null && ($nature === null || $this->natureAllows($row, $type))) {
            return $type;
        }

        return $nature === 'pl' ? AccountType::EXPENSE : AccountType::ASSET_CURRENT;
    }

    /** 'pl', 'bs' or null when the Nature column says neither. */
    protected function nature(CoaRow $row): ?string
    {
        $nature = str_replace([' ', '.'], '', mb_strtolower($row->nature));

        return match (true) {
            in_array($nature, ['p&l', 'pl', 'p/l', 'profit&loss', 'profitandloss', 'incomestatement'], true) => 'pl',
            in_array($nature, ['b/s', 'bs', 'balancesheet'], true)                                           => 'bs',
            default                                                                                          => null,
        };
    }

    /** The top-level section named by the first classification, if recognisable. */
    protected function section(CoaRow $row): ?string
    {
        $class1 = mb_strtolower($row->classifications[0] ?? '');

        return match (true) {
            $class1 === ''                                                                 => null,
            str_contains($class1, 'liab') || str_contains($class1, 'libail')               => 'liability',
            str_contains($class1, 'equity') || str_contains($class1, 'reserve')
                || str_contains($class1, 'capital')                                        => 'equity',
            str_contains($class1, 'asset')                                                 => 'asset',
            str_contains($class1, 'revenue') || str_contains($class1, 'income')
                || str_contains($class1, 'sales')                                          => 'income',
            str_contains($class1, 'expense') || str_contains($class1, 'cost')              => 'expense',
            default                                                                        => null,
        };
    }

    /** @return array<int, AccountType> */
    protected function natureTypes(CoaRow $row): array
    {
        $types = $this->sectionTypeMap();

        return match ($this->nature($row)) {
            'pl'    => [...$types['income'], ...$types['expense']],
            'bs'    => [...$types['asset'], ...$types['liability'], ...$types['equity']],
            default => AccountType::cases(),
        };
    }

    /** @return array<int, AccountType>|null */
    protected function sectionTypes(CoaRow $row): ?array
    {
        $section = $this->section($row);

        return $section === null ? null : $this->sectionTypeMap()[$section];
    }

    /** @return array<string, array<int, AccountType>> */
    protected function sectionTypeMap(): array
    {
        return [
            'asset'     => [AccountType::ASSET_RECEIVABLE, AccountType::ASSET_CASH, AccountType::ASSET_CURRENT, AccountType::ASSET_NON_CURRENT, AccountType::ASSET_PREPAYMENTS, AccountType::ASSET_FIXED],
            'liability' => [AccountType::LIABILITY_PAYABLE, AccountType::LIABILITY_CREDIT_CARD, AccountType::LIABILITY_CURRENT, AccountType::LIABILITY_NON_CURRENT],
            'equity'    => [AccountType::EQUITY, AccountType::EQUITY_UNAFFECTED],
            'income'    => [AccountType::INCOME, AccountType::INCOME_OTHER],
            'expense'   => [AccountType::EXPENSE, AccountType::EXPENSE_DEPRECIATION, AccountType::EXPENSE_DIRECT_COST],
        ];
    }
}
