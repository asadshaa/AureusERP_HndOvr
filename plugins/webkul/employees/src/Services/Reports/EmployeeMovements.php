<?php

namespace Webkul\Employee\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Throwable;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;

/**
 * When each employee of a company joined and left, for the joiners &
 * leavers and headcount reports. One query, archived employees included.
 *
 *  - joined: the date of joining; without one, the day the record was
 *    created ("record created").
 *  - left:   the leaving date, else the departure date; an archived employee
 *    without either left the day it was archived ("archived"), an inactive
 *    one the day it was last changed ("deactivated"). The leaving date is
 *    the last day on the books: from the next day the person no longer
 *    counts. A leaving date before the joining date counts as the joining
 *    date.
 *
 * Dates are Y-m-d strings in the HR time zone, so they compare as strings.
 */
class EmployeeMovements
{
    public const SOURCE_RECORDED = 'recorded';

    public const SOURCE_CREATED = 'record created';

    public const SOURCE_ARCHIVED = 'archived';

    public const SOURCE_DEACTIVATED = 'deactivated';

    public function __construct(
        protected HrReportScope $scope,
    ) {}

    /**
     * @return Collection<int, array{id: int, code: ?string, name: string, department_id: ?int, department: string, job_title: ?string, joined: string, joined_source: string, left: ?string, left_source: ?string, reason: ?string, description: ?string}>
     *
     * @throws AuthorizationException
     */
    public function people(User $user, int $companyId, ?int $departmentId = null): Collection
    {
        $query = $this->scope->employees(Employee::withTrashed(), $user, $companyId, $departmentId)
            ->leftJoin('employees_departments as departments', 'departments.id', '=', 'employees_employees.department_id')
            ->leftJoin('employees_job_positions as jobs', 'jobs.id', '=', 'employees_employees.job_id')
            ->leftJoin('employees_departure_reasons as reasons', 'reasons.id', '=', 'employees_employees.departure_reason_id')
            ->orderBy('employees_employees.name')
            ->orderBy('employees_employees.id');

        return $query->toBase()
            ->get([
                'employees_employees.id',
                'employees_employees.employee_number',
                'employees_employees.name',
                'employees_employees.department_id',
                'departments.name as department_name',
                'departments.complete_name as department_complete_name',
                'employees_employees.job_title',
                'jobs.name as job_name',
                'employees_employees.joining_date',
                'employees_employees.leaving_date',
                'employees_employees.departure_date',
                'employees_employees.is_active',
                'employees_employees.created_at',
                'employees_employees.updated_at',
                'employees_employees.deleted_at',
                'reasons.name as reason',
                'employees_employees.departure_description',
            ])
            ->map(fn (object $row): array => $this->person($row))
            ->values();
    }

    /**
     * On the books at the close of $date (Y-m-d).
     *
     * @param  array{joined: string, left: ?string}  $person
     */
    public static function onBooks(array $person, string $date): bool
    {
        return $person['joined'] <= $date && ($person['left'] === null || $person['left'] > $date);
    }

    /** @return array<string, mixed> */
    protected function person(object $row): array
    {
        [$joined, $joinedSource] = $row->joining_date
            ? [$this->date($row->joining_date), self::SOURCE_RECORDED]
            : [$this->timestampDate($row->created_at), self::SOURCE_CREATED];

        $joined ??= HrReportScope::now()->toDateString();

        $recordedLeft = $this->date($row->leaving_date) ?? $this->date($row->departure_date);

        [$left, $leftSource] = match (true) {
            $recordedLeft !== null    => [$recordedLeft, self::SOURCE_RECORDED],
            $row->deleted_at !== null => [$this->timestampDate($row->deleted_at), self::SOURCE_ARCHIVED],
            ! $row->is_active         => [$this->timestampDate($row->updated_at), self::SOURCE_DEACTIVATED],
            default                   => [null, null],
        };

        if ($left !== null && $left < $joined) {
            $left = $joined;
        }

        return [
            'id'            => (int) $row->id,
            'code'          => $row->employee_number !== null ? (string) $row->employee_number : null,
            'name'          => (string) $row->name,
            'department_id' => $row->department_id !== null ? (int) $row->department_id : null,
            'department'    => (string) ($row->department_complete_name ?: ($row->department_name ?: 'No department')),
            'job_title'     => filled($row->job_title) ? (string) $row->job_title : ($row->job_name !== null ? (string) $row->job_name : null),
            'joined'        => $joined,
            'joined_source' => $joinedSource,
            'left'          => $left,
            'left_source'   => $left !== null ? $leftSource : null,
            'reason'        => $left !== null && filled($row->reason) ? (string) $row->reason : null,
            'description'   => $left !== null && filled($row->departure_description) ? (string) $row->departure_description : null,
        ];
    }

    /** A stored date (Y-m-d or a free-text departure date), null when unreadable. */
    protected function date(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:$|[ T])/', (string) $value) === 1) {
            return substr((string) $value, 0, 10);
        }

        try {
            return CarbonImmutable::parse((string) $value, HrReportScope::timezone())->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** A UTC timestamp column as a day in the HR time zone. */
    protected function timestampDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, (string) config('app.timezone', 'UTC'))->setTimezone(HrReportScope::timezone())->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
