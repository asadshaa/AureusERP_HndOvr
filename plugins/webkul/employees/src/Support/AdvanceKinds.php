<?php

namespace Webkul\Employee\Support;

use Illuminate\Support\Carbon;
use Webkul\Employee\Models\EmployeeRequestType;

/**
 * Business advance: money an employee asks for BEFORE spending it on company
 * work, and what happens to it afterwards.
 *
 *   Business Advance     a financial request type. On final approval it posts
 *                        a vendor bill to the employee (like a claim) whose
 *                        debit is the "Employee Advances" asset account, so
 *                        Finance pays it from the normal Register Payment.
 *   Advance Settlement   the employee reports what was really spent, with
 *                        receipts. On final approval it moves that amount from
 *                        Employee Advances to the expense account. Money that
 *                        was not spent stays on the advance until Finance
 *                        records it as handed back.
 */
final class AdvanceKinds
{
    public const AdvanceCode = 'business_advance';

    public const AdvanceCategory = 'business_advance';

    public const SettlementCode = 'advance_settlement';

    public const SettlementCategory = 'advance_settlement';

    public const AdvancesAccountCode = '128100';

    public const AdvancesAccountName = 'Employee Advances';

    /** Request-type configuration key: days after the date the money is needed by which it must be settled. */
    public const SettleWithinDaysKey = 'settle_within_days';

    /** Request-type configuration key: the most one advance may be for (empty = no limit). */
    public const MaxAmountKey = 'max_amount';

    /** Request-type configuration key: "0" lets an employee ask again while an earlier advance is overdue. */
    public const BlockWhenOverdueKey = 'block_when_overdue';

    public const DefaultSettleWithinDays = 14;

    public const MaxSettleWithinDays = 90;

    public const EntrySettlement = 'settlement';

    public const EntryCashReturned = 'cash_returned';

    /** accounting_source_type of the journal entry posted for a settlement / for cash handed back. */
    public const SettlementSourceType = 'advance_settlement';

    public const CashReturnSourceType = 'advance_cash_return';

    /** Same values as Webkul\Accounting\Enums\CashFlowCategory (the accounting plugin is not required here). */
    public const NonCash = 'Non-cash';

    public const OperatingReceipts = 'Operating - Receipts';

    public static function isAdvance(?EmployeeRequestType $type): bool
    {
        return $type?->category === self::AdvanceCategory;
    }

    public static function isSettlement(?EmployeeRequestType $type): bool
    {
        return $type?->category === self::SettlementCategory;
    }

    public static function settleWithinDays(?EmployeeRequestType $type): int
    {
        $configured = data_get($type?->configuration, self::SettleWithinDaysKey);

        if (is_numeric($configured) && (int) $configured >= 1 && (int) $configured <= self::MaxSettleWithinDays) {
            return (int) $configured;
        }

        return self::DefaultSettleWithinDays;
    }

    /** Whether a new advance is refused while an earlier one is overdue (on unless the type says "0"). */
    public static function blocksWhenOverdue(?EmployeeRequestType $type): bool
    {
        $configured = data_get($type?->configuration, self::BlockWhenOverdueKey);

        return ! in_array(strtolower(trim((string) $configured)), ['0', 'false', 'no', 'off'], true);
    }

    /** The last day to settle: the date the money is needed by, plus the type's settle window. */
    public static function settleBy(string $neededBy, ?EmployeeRequestType $type): string
    {
        return Carbon::parse($neededBy)->addDays(self::settleWithinDays($type))->toDateString();
    }

    public static function timezone(): string
    {
        return (string) config('attendance.monthly_report.timezone', 'Asia/Karachi');
    }

    public static function today(): string
    {
        return Carbon::now(self::timezone())->toDateString();
    }
}
