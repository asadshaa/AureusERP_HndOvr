<?php

namespace Webkul\Employee\Support;

use Webkul\Employee\Models\EmployeeRequestType;

/**
 * Expense heads-up: a notice from an employee to HR that an expense WILL be
 * made on a given date. It carries an estimate only (never a payable amount),
 * goes line manager -> HR, and posts nothing to Accounting. When the money is
 * spent the employee turns it into a normal claim (ExpenseHeadsUpService).
 */
final class ExpenseHeadsUp
{
    public const Code = 'expense_heads_up';

    public const Category = 'expense_heads_up';

    public const ApprovalRequestType = 'expense_heads_up';

    /** Request-type configuration key: how far above the estimate a claim may be before Finance sees a flag (percent). */
    public const OverEstimatePercentKey = 'over_estimate_percent';

    public const DefaultOverEstimatePercent = 10;

    public const GenericNatures = 'Travel, Accommodation, Daily Allowance, Fuel, Food, Equipment, Software and tools, Other';

    public static function isHeadsUp(?EmployeeRequestType $type): bool
    {
        return $type?->category === self::Category;
    }

    public static function overEstimatePercent(?EmployeeRequestType $type): float
    {
        $configured = data_get($type?->configuration, self::OverEstimatePercentKey);

        return is_numeric($configured) && (float) $configured >= 0 ? (float) $configured : (float) self::DefaultOverEstimatePercent;
    }
}
