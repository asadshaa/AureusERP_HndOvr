<?php

namespace Webkul\Employee\Services;

use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * Birthdays and work anniversaries of this week (Monday to Sunday, Karachi
 * time) for one company, for the home dashboard (HR Parity 2.6).
 *
 * Privacy: a birthday shows only the day and month, never the year or an
 * age, and never for an employee who switched show_birthday off. The list
 * is worked out once per company per day and cached (the cache keeps no
 * birth years either); saving an employee forgets today's list.
 */
class CelebrationService
{
    public const Birthday = 'birthday';

    public const Anniversary = 'anniversary';

    /**
     * @return array<int, array{kind: string, employee_id: int, user_id: ?int, name: string, department: ?string, date: string, day_label: string, is_today: bool, years: ?int, label: string}>
     */
    public function thisWeek(int $companyId, ?CarbonImmutable $today = null): array
    {
        $today ??= ChecklistKinds::today();

        return Cache::remember(static::cacheKey($companyId, $today), $today->endOfDay(), fn (): array => $this->build($companyId, $today));
    }

    public static function cacheKey(int $companyId, ?CarbonImmutable $today = null): string
    {
        return 'hr:celebrations:'.$companyId.':'.($today ?? ChecklistKinds::today())->toDateString();
    }

    public static function forget(int $companyId): void
    {
        Cache::forget(static::cacheKey($companyId));
    }

    /** The employee's own birthday privacy switch (the employee, from their own record). */
    public function setOwnBirthdayVisibility(User $user, bool $show): Employee
    {
        $companyId = CurrentCompany::id($user);
        $employee = $companyId === null ? null : Employee::query()->where('company_id', $companyId)->where('user_id', $user->getKey())->first();

        if (! $employee instanceof Employee) {
            throw new AuthorizationException('You have no employee record in this company.');
        }

        $employee->forceFill(['show_birthday' => $show])->save();
        static::forget((int) $employee->company_id);

        return $employee;
    }

    /**
     * "Send wishes": one in-app notification to the person celebrating this
     * week, from a colleague of the same company, once a day per pair.
     * Returns false when there is nobody to wish or it was already sent.
     */
    public function sendWishes(User $sender, int $employeeId, string $kind): bool
    {
        $companyId = CurrentCompany::id($sender);

        if ($companyId === null) {
            return false;
        }

        $row = collect($this->thisWeek($companyId))
            ->first(fn (array $row): bool => $row['employee_id'] === $employeeId && $row['kind'] === $kind);

        if ($row === null || $row['user_id'] === null || $row['user_id'] === (int) $sender->getKey()) {
            return false;
        }

        $recipient = User::query()->whereKey($row['user_id'])->where('is_active', true)->first();

        if (! $recipient instanceof User) {
            return false;
        }

        $today = ChecklistKinds::today();

        if (! Cache::add('hr:celebration-wish:'.$sender->getKey().':'.$employeeId.':'.$kind.':'.$today->toDateString(), true, $today->endOfDay())) {
            return false;
        }

        $senderName = Str::of((string) $sender->name)->trim()->value() ?: 'A colleague';

        $recipient->notifyNow(Notification::make()
            ->title($kind === self::Birthday
                ? $senderName.' wishes you a happy birthday'
                : $senderName.' congratulates you on '.$row['label'])
            ->body($kind === self::Birthday ? 'Have a great day!' : 'Thank you for your work with us.')
            ->icon($kind === self::Birthday ? 'heroicon-o-cake' : 'heroicon-o-trophy')
            ->color('success')
            ->viewData(['celebration_wish' => $kind.':'.$employeeId.':'.$today->toDateString()])
            ->toDatabase());

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    protected function build(int $companyId, CarbonImmutable $today): array
    {
        $start = $today->startOfWeek(CarbonImmutable::MONDAY);
        $days = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $day = $start->addDays($offset);
            $days[$day->format('m-d')] = $day;
        }

        // 29 February birthdays and anniversaries fall on the 28th in other years.
        if (isset($days['02-28']) && ! $days['02-28']->isLeapYear()) {
            $days['02-29'] = $days['02-28'];
        }

        $monthDays = array_keys($days);
        $placeholders = implode(',', array_fill(0, count($monthDays), '?'));

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('leaving_date')->orWhereDate('leaving_date', '>=', $today->toDateString()))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $birthdays) => $birthdays
                    ->where('show_birthday', true)
                    ->whereNotNull('birthday')
                    ->where('birthday', '!=', '')
                    ->whereRaw("DATE_FORMAT(birthday, '%m-%d') IN ({$placeholders})", $monthDays))
                ->orWhere(fn (Builder $anniversaries) => $anniversaries
                    ->whereNotNull('joining_date')
                    ->whereDate('joining_date', '<', $start->toDateString())
                    ->whereRaw("DATE_FORMAT(joining_date, '%m-%d') IN ({$placeholders})", $monthDays)))
            ->select(['id', 'name', 'user_id', 'birthday', 'show_birthday', 'joining_date'])
            ->addSelect(['department_name' => Department::query()->select('name')->whereColumn('id', 'employees_employees.department_id')->limit(1)])
            ->orderBy('name')
            ->get();

        $companyName = null;
        $rows = [];

        foreach ($employees as $employee) {
            $birthday = $this->monthDay($employee->birthday);

            if ($employee->show_birthday && $birthday !== null && isset($days[$birthday])) {
                $rows[] = $this->row(self::Birthday, $employee, $days[$birthday], $today, null, 'Birthday');
            }

            $joined = $employee->joining_date;

            if ($joined !== null && isset($days[$joined->format('m-d')])) {
                $date = $days[$joined->format('m-d')];
                $years = $date->year - (int) $joined->format('Y');

                if ($years >= 1) {
                    $companyName ??= (string) Company::query()->whereKey($companyId)->value('name');
                    $rows[] = $this->row(self::Anniversary, $employee, $date, $today, $years, $years.' '.Str::plural('year', $years).' at '.$companyName);
                }
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['date'], $a['kind'], $a['name']] <=> [$b['date'], $b['kind'], $b['name']]);

        return $rows;
    }

    /** @return array<string, mixed> */
    protected function row(string $kind, Employee $employee, CarbonImmutable $date, CarbonImmutable $today, ?int $years, string $label): array
    {
        return [
            'kind'        => $kind,
            'employee_id' => (int) $employee->getKey(),
            'user_id'     => $employee->user_id !== null ? (int) $employee->user_id : null,
            'name'        => (string) $employee->name,
            'department'  => $employee->getAttribute('department_name'),
            'date'        => $date->toDateString(),
            'day_label'   => $date->isSameDay($today) ? 'Today' : $date->format('D j M'),
            'is_today'    => $date->isSameDay($today),
            'years'       => $years,
            'label'       => $label,
        ];
    }

    /** "05-12" from a stored birthday ("1990-05-12"), or null. */
    protected function monthDay(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
