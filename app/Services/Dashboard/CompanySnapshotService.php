<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * The CEO's one-line-per-company view on the home dashboard: headcount,
 * checked in today, open jobs and applications this month, for the
 * companies the user belongs to (CurrentCompany::membershipIds) and no
 * others. Five grouped queries in total, whatever the number of companies
 * or employees.
 */
class CompanySnapshotService
{
    /**
     * @return array<int, array{id: int, name: string, is_active: bool, headcount: int, present_today: int, present_percentage: ?int, open_jobs: int, applicants_this_month: int}>
     */
    public function forUser(User $user): array
    {
        $companyIds = CurrentCompany::membershipIds($user);

        if ($companyIds === []) {
            return [];
        }

        $timezone = app(MonthlyAttendanceReport::class)->timezone();
        $today = CarbonImmutable::now($timezone)->toDateString();
        $monthStart = CarbonImmutable::now($timezone)->startOfMonth()->toDateString();
        $activeCompanyId = CurrentCompany::id($user);

        $companies = Company::query()
            ->whereIn('id', $companyIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        $headcount = $this->countBy(Employee::query()
            ->whereIn('company_id', $companyIds)
            ->where('is_active', true)
            ->selectRaw('company_id, COUNT(*) as aggregate'));

        $present = $this->countBy(AttendanceRecord::query()
            ->whereIn('company_id', $companyIds)
            ->whereDate('attendance_date', $today)
            ->whereNotNull('check_in')
            ->selectRaw('company_id, COUNT(DISTINCT employee_id) as aggregate'));

        $openJobs = $this->countBy(EmployeeJobPosition::query()
            ->whereIn('company_id', $companyIds)
            ->where('is_active', true)
            ->where('posting_status', 'published')
            ->selectRaw('company_id, COUNT(*) as aggregate'));

        $applicants = $this->countBy(Applicant::query()
            ->whereIn('company_id', $companyIds)
            ->where('created_at', '>=', CarbonImmutable::parse($monthStart, $timezone)->utc())
            ->selectRaw('company_id, COUNT(*) as aggregate'));

        return $companies
            ->map(function (Company $company) use ($headcount, $present, $openJobs, $applicants, $activeCompanyId): array {
                $id = (int) $company->id;
                $staff = $headcount[$id] ?? 0;
                $in = $present[$id] ?? 0;

                return [
                    'id'                    => $id,
                    'name'                  => (string) $company->name,
                    'is_active'             => $id === $activeCompanyId,
                    'headcount'             => $staff,
                    'present_today'         => $in,
                    'present_percentage'    => $staff > 0 ? min(100, (int) round($in / $staff * 100)) : null,
                    'open_jobs'             => $openJobs[$id] ?? 0,
                    'applicants_this_month' => $applicants[$id] ?? 0,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, int> company id => count */
    protected function countBy(Builder $query): array
    {
        /** @var Collection<int, object> $rows */
        $rows = $query->groupBy('company_id')->toBase()->get();

        return $rows->mapWithKeys(fn (object $row): array => [(int) $row->company_id => (int) $row->aggregate])->all();
    }
}
