<?php

namespace Webkul\Employee\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequestType;

/**
 * Self-service request kinds that carry their own form fields in
 * EmployeeRequest.payload: Loan, Salary advance and HR letter. Each is a
 * plain (non-financial) EmployeeRequestType per company, routed through the
 * shared ApprovalEngine; nothing here posts to accounting or payroll.
 */
final class EmployeeRequestKinds
{
    public const LoanCode = 'loan_request';

    public const SalaryAdvanceCode = 'salary_advance';

    public const HrLetterCode = 'hr_letter';

    public const LoanCategory = 'loan';

    public const SalaryAdvanceCategory = 'salary_advance';

    public const HrLetterCategory = 'hr_letter';

    public const MinInstalments = 1;

    public const MaxInstalments = 24;

    /** Default cap of a salary advance, as a percentage of the monthly base salary. */
    public const DefaultAdvanceSalaryPercent = 50;

    /** Request-type configuration key holding the per-company advance cap (percent). */
    public const AdvanceSalaryPercentKey = 'max_salary_percent';

    public const SalaryCertificate = 'salary_certificate';

    /** @var array<string, string> */
    public const LetterTypes = [
        'experience_letter'       => 'Experience letter',
        self::SalaryCertificate   => 'Salary certificate',
        'employment_verification' => 'Employment verification',
        'noc'                     => 'NOC',
        'bank_letter'             => 'Bank letter',
        'other'                   => 'Other',
    ];

    /** @return array<int, string> */
    public static function categories(): array
    {
        return [self::LoanCategory, self::SalaryAdvanceCategory, self::HrLetterCategory];
    }

    public static function isSelfServiceCategory(?string $category): bool
    {
        return in_array($category, self::categories(), true);
    }

    public static function advanceSalaryPercent(?EmployeeRequestType $type): float
    {
        $configured = data_get($type?->configuration, self::AdvanceSalaryPercentKey);

        if (is_numeric($configured) && (float) $configured > 0 && (float) $configured <= 100) {
            return (float) $configured;
        }

        return (float) self::DefaultAdvanceSalaryPercent;
    }

    /** The largest advance allowed for the employee, or null when the employee has no salary on file. */
    public static function advanceCap(?Employee $employee, ?EmployeeRequestType $type): ?string
    {
        $salary = $employee?->base_salary;

        if ($salary === null || BigDecimal::of((string) $salary)->isLessThanOrEqualTo(0)) {
            return null;
        }

        return (string) BigDecimal::of((string) $salary)
            ->multipliedBy((string) self::advanceSalaryPercent($type))
            ->dividedBy(100, 4, RoundingMode::DOWN);
    }

    public static function letterTypeLabel(?string $letterType): string
    {
        return self::LetterTypes[$letterType] ?? 'Letter';
    }

    /**
     * Equal monthly instalments from the start month; the last instalment
     * absorbs the rounding difference so the schedule always sums to the amount.
     *
     * @return array<int, array{number: int, month: string, amount: string}>
     */
    public static function instalmentSchedule(string $amount, int $instalments, string $startMonth): array
    {
        $total = BigDecimal::of($amount);
        $each = $total->dividedBy($instalments, 2, RoundingMode::DOWN);
        $month = Carbon::createFromFormat('!Y-m', $startMonth);
        $schedule = [];

        for ($number = 1; $number <= $instalments; $number++) {
            $value = $number === $instalments
                ? $total->minus($each->multipliedBy($instalments - 1))
                : $each;

            $schedule[] = [
                'number' => $number,
                'month'  => $month->copy()->addMonthsNoOverflow($number - 1)->format('Y-m'),
                'amount' => (string) $value->toScale(2, RoundingMode::HALF_UP),
            ];
        }

        return $schedule;
    }

    /** A "Y-m" month that is this month or later. */
    public static function isValidUpcomingMonth(mixed $month): bool
    {
        return self::isWellFormedMonth($month) && $month >= now()->format('Y-m');
    }

    /**
     * This month and the next eleven, for the start / deduct month pickers.
     *
     * @return array<string, string>
     */
    public static function upcomingMonths(int $count = 12): array
    {
        $months = [];
        $month = now()->startOfMonth();

        for ($i = 0; $i < $count; $i++) {
            $months[$month->format('Y-m')] = $month->format('F Y');
            $month = $month->addMonthNoOverflow();
        }

        return $months;
    }

    public static function monthLabel(?string $month): ?string
    {
        if (! self::isWellFormedMonth($month)) {
            return $month;
        }

        return Carbon::createFromFormat('!Y-m', $month)->format('F Y');
    }

    private static function isWellFormedMonth(mixed $month): bool
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1;
    }
}
