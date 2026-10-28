<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;
use Webkul\Employee\Filament\Pages\AttendanceReport;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Filament\Pages\TodayAttendance as TodayAttendancePage;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Filament\Clusters\Applications\Pages\RecruitmentReports;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\InterviewResource;
use Webkul\Recruitment\Filament\Clusters\Configurations\Pages\WebsiteIntake;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Filament\Resources\ApprovalRequestResource;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource;

/**
 * Who is looking at the home dashboard, worked out once per request and
 * shared by every home widget (bound as a scoped service): the active
 * company, the user's Employee record in it, and which roles apply. Roles
 * add up: an HR manager who is also an employee sees both sets of cards.
 *
 * - CEO / admin: group owner (security_manage_company_memberships) or the
 *   Admin / Super Admin role.
 * - HR: hr_view_all_records or one of the HR roles HrHierarchyService
 *   treats as "sees the whole company".
 * - Recruiter: may list applicants (view_any_recruitment_applicant).
 * - Line manager: not HR, and HrHierarchyService gives them at least one
 *   other employee (direct / indirect reports, managed department or team).
 * - Employee: has an Employee record in the active company.
 */
class HomeDashboardContext
{
    /** Roles that see the whole company (same list as HrHierarchyService). */
    public const HR_ROLES = [
        'hr',
        'hr_manager',
        'hr manager',
        'hr_ops_manager',
        'hr ops manager',
        'hr operations manager',
        'hr_administrator',
        'hr administrator',
        'Hr_manager',
        'HR Administrator',
        'human resources',
        'human resources manager',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $resolved = [];

    public function __construct(
        protected HrHierarchyService $hierarchy,
    ) {}

    /**
     * @return array{user: ?User, company_id: ?int, company_name: string, membership_count: int, employee: ?Employee, team_ids: array<int, int>, is_ceo: bool, is_hr: bool, is_recruiter: bool, is_line_manager: bool, is_employee: bool}
     */
    public function resolve(?User $user = null): array
    {
        $user ??= CurrentCompany::user();
        $companyId = $user ? CurrentCompany::id($user) : null;
        $key = ($user?->getKey() ?? 0).':'.($companyId ?? 0);

        return $this->resolved[$key] ??= $this->build($user, $companyId);
    }

    public function user(): ?User
    {
        return $this->resolve()['user'];
    }

    public function companyId(): ?int
    {
        return $this->resolve()['company_id'];
    }

    public function employee(): ?Employee
    {
        return $this->resolve()['employee'];
    }

    /** @return array<int, int> employees the line manager sees, excluding themselves */
    public function teamIds(): array
    {
        return $this->resolve()['team_ids'];
    }

    public function isCeo(): bool
    {
        return $this->resolve()['is_ceo'];
    }

    public function isHr(): bool
    {
        return $this->resolve()['is_hr'];
    }

    public function isRecruiter(): bool
    {
        return $this->resolve()['is_recruiter'];
    }

    public function isLineManager(): bool
    {
        return $this->resolve()['is_line_manager'];
    }

    public function isEmployee(): bool
    {
        return $this->resolve()['is_employee'];
    }

    /** Forget what was worked out (tests that switch users or companies mid-request). */
    public function flush(): void
    {
        $this->resolved = [];
    }

    /** @return array<int, string> e.g. ['HR Manager', 'Recruiter'] */
    public function roleLabels(): array
    {
        $context = $this->resolve();
        $labels = [];

        if ($context['is_ceo']) {
            $labels[] = CurrentCompany::isGroupOwner($context['user']) ? 'CEO' : 'Administrator';
        }

        if ($context['is_hr']) {
            $labels[] = 'HR Manager';
        }

        if ($context['is_recruiter']) {
            $labels[] = 'Recruiter';
        }

        if ($context['is_line_manager']) {
            $labels[] = 'Line Manager';
        }

        if ($labels === [] && $context['is_employee']) {
            $labels[] = 'Employee';
        }

        return $labels;
    }

    /** The local time of the company day (attendance timezone). */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now(app(MonthlyAttendanceReport::class)->timezone());
    }

    public function greeting(): string
    {
        $hour = (int) $this->now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default    => 'Good evening',
        };
    }

    public function firstName(): string
    {
        $context = $this->resolve();
        $name = trim((string) ($context['employee']?->name ?: $context['user']?->name));

        return $name === '' ? 'there' : (string) strtok($name, ' ');
    }

    /**
     * Big buttons for the roles that apply, each shown only when the user
     * may open its page (the page's own canAccess / canViewAny).
     *
     * @return array<int, array{label: string, icon: string, url: string, color: string, hint: ?string}>
     */
    public function quickActions(): array
    {
        $context = $this->resolve();

        if (! $context['user'] || $context['company_id'] === null) {
            return [];
        }

        $actions = new Collection;
        $add = function (string $label, string $icon, ?string $url, string $color = 'gray', ?string $hint = null) use ($actions): void {
            if ($url !== null && ! $actions->contains('url', $url)) {
                $actions->push(['label' => $label, 'icon' => $icon, 'url' => $url, 'color' => $color, 'hint' => $hint]);
            }
        };

        if ($context['is_employee']) {
            $add('Check in / out', 'heroicon-o-finger-print', $this->pageUrl(MyAttendance::class), 'primary', 'My attendance');
            $add('Request time off', 'heroicon-o-sun', $this->resourceUrl(MyTimeOffResource::class, 'create'), 'gray', 'Annual, casual, sick');
        }

        if ($context['is_line_manager']) {
            $add('Team today', 'heroicon-o-user-group', $this->pageUrl(TodayAttendancePage::class), 'primary', 'Who is in, late, absent');
            $add('Team requests', 'heroicon-o-inbox-arrow-down', $this->resourceUrl(EmployeeRequestResource::class), 'gray', 'Approve or reject');
        }

        if ($context['is_hr']) {
            $add("Today's attendance", 'heroicon-o-user-group', $this->pageUrl(TodayAttendancePage::class), 'primary', 'Live roll call');
            $add('Attendance report', 'heroicon-o-table-cells', $this->pageUrl(AttendanceReport::class), 'gray', 'Monthly, for payroll');
            $add('Employees', 'heroicon-o-identification', $this->resourceUrl(EmployeeResource::class), 'gray', 'Directory and records');
            $add('Job openings', 'heroicon-o-megaphone', $this->resourceUrl(JobPositionResource::class), 'gray', 'Jobs and job ads');
        }

        if ($context['is_recruiter']) {
            $add('Applicants', 'heroicon-o-document-text', $this->resourceUrl(ApplicantResource::class), $context['is_hr'] ? 'gray' : 'primary', 'Sorted by CV score');
            $add('Interviews', 'heroicon-o-calendar-days', $this->resourceUrl(InterviewResource::class), 'gray', 'Schedule and feedback');
            $add('Website intake', 'heroicon-o-globe-alt', $this->pageUrl(WebsiteIntake::class), 'gray', 'Careers site applications');
        }

        if ($context['is_ceo']) {
            $add('Approvals', 'heroicon-o-shield-check', $this->resourceUrl(ApprovalRequestResource::class), 'gray', 'Waiting for sign-off');
            $add('Recruitment reports', 'heroicon-o-chart-bar', $this->pageUrl(RecruitmentReports::class), 'gray', 'Sources, time to hire');
        }

        return $actions->take(6)->values()->all();
    }

    /** @param  class-string  $page */
    public function pageUrl(string $page, array $parameters = []): ?string
    {
        try {
            return $page::canAccess() ? $page::getUrl($parameters) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  class-string  $resource */
    public function resourceUrl(string $resource, string $name = 'index', array $parameters = []): ?string
    {
        try {
            $allowed = $name === 'create' ? $resource::canCreate() : $resource::canViewAny();

            return $allowed ? $resource::getUrl($name, $parameters) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{user: ?User, company_id: ?int, company_name: string, membership_count: int, employee: ?Employee, team_ids: array<int, int>, is_ceo: bool, is_hr: bool, is_recruiter: bool, is_line_manager: bool, is_employee: bool}
     */
    protected function build(?User $user, ?int $companyId): array
    {
        $empty = [
            'user'             => $user,
            'company_id'       => $companyId,
            'company_name'     => '',
            'membership_count' => 0,
            'employee'         => null,
            'team_ids'         => [],
            'is_ceo'           => false,
            'is_hr'            => false,
            'is_recruiter'     => false,
            'is_line_manager'  => false,
            'is_employee'      => false,
        ];

        if (! $user || $companyId === null) {
            return $empty;
        }

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->first();

        $isCeo = CurrentCompany::isGroupOwner($user) || $user->hasRole(['Admin', 'Super Admin']);
        $isHr = $user->can(HrPermissions::ViewAllRecords) || $user->hasRole(self::HR_ROLES);

        $teamIds = [];
        if ($employee && ! $isHr && ! $isCeo) {
            try {
                $teamIds = $this->hierarchy->visibleEmployeeIds($user, $companyId)
                    ->map(fn ($id): int => (int) $id)
                    ->reject(fn (int $id): bool => $id === (int) $employee->id)
                    ->values()
                    ->all();
            } catch (RuntimeException) {
                $teamIds = [];
            }
        }

        return [
            'user'             => $user,
            'company_id'       => $companyId,
            'company_name'     => (string) Company::query()->whereKey($companyId)->value('name'),
            'membership_count' => count(CurrentCompany::membershipIds($user)),
            'employee'         => $employee,
            'team_ids'         => $teamIds,
            'is_ceo'           => $isCeo,
            'is_hr'            => $isHr,
            'is_recruiter'     => $user->can('view_any_recruitment_applicant'),
            'is_line_manager'  => $teamIds !== [],
            'is_employee'      => $employee !== null,
        ];
    }
}
