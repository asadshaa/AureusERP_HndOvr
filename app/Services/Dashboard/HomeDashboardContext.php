<?php

namespace App\Services\Dashboard;

use App\Filament\Widgets\EmployeeDashboardOverviewWidget;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;
use Webkul\Employee\Filament\Pages\AttendanceReport;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Filament\Pages\TodayAttendance as TodayAttendancePage;
use Webkul\Employee\Filament\Resources\EmployeeDocumentResource;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeDocument;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Services\Attendance\TodayAttendance;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Filament\Clusters\Applications\Pages\RecruitmentReports;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\InterviewResource;
use Webkul\Recruitment\Filament\Clusters\Configurations\Pages\WebsiteIntake;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Interview;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Filament\Resources\ApprovalRequestResource;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\TimeOffResource;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Services\LeaveBalanceService;

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
        'hr_administrator',
        'hr administrator',
        'Hr_manager',
        'HR Administrator',
        'human resources',
        'human resources manager',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $resolved = [];

    /** @var array<string, ?string> page / resource URLs the user may open, per class, route and parameters */
    private array $urls = [];

    /** @var array<int, array<string, mixed>|null> GeofencedAttendanceService::todayState() per user id */
    private array $todayStates = [];

    /** @var array<string, mixed> answers several home widgets share, per user and company */
    private array $memo = [];

    /** Leave states that still wait for an approver (same as the action center). */
    public const LEAVE_WAITING_STATES = ['confirm', 'validate_one'];

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
        $this->urls = [];
        $this->todayStates = [];
        $this->memo = [];
    }

    /**
     * The action center's list (RoleActionCenterService), worked out once
     * per request: the Today box reads its counts.
     *
     * @return array{user: array{id: int, name: string, role_label: string}, pending_count: int, items: array<int, array<string, mixed>>}
     */
    public function pendingActions(): array
    {
        return $this->remember('pending_actions', function (): array {
            $user = $this->user();
            $companyId = $this->companyId();

            if (! $user || $companyId === null) {
                return ['user' => ['id' => 0, 'name' => '', 'role_label' => ''], 'pending_count' => 0, 'items' => []];
            }

            return app(RoleActionCenterService::class)->getPendingActionsFor($user, $companyId);
        });
    }

    /**
     * Today's attendance of the people the user may see (the Today's
     * attendance page's own service), or null when they may not see it.
     *
     * @return array<string, mixed>|null
     */
    public function todayAttendance(): ?array
    {
        return $this->remember('today_attendance', function (): ?array {
            $user = $this->user();
            $companyId = $this->companyId();

            if (! $user || $companyId === null) {
                return null;
            }

            try {
                return app(TodayAttendance::class)->forCompany($user, $companyId);
            } catch (AuthorizationException) {
                return null;
            }
        });
    }

    /**
     * Today's attendance of the whole active company, for admin roles that
     * see the roll call without the attendance permissions.
     *
     * @return array<string, mixed>|null
     */
    public function wholeCompanyAttendance(): ?array
    {
        return $this->remember('whole_company_attendance', function (): ?array {
            $companyId = $this->companyId();

            return $companyId === null ? null : app(TodayAttendance::class)->forWholeCompany($companyId);
        });
    }

    /** Applications received this week in the active company (the hiring card shows the same number). */
    public function newApplicantsThisWeek(): int
    {
        return $this->remember('new_applicants_week', function (): int {
            $companyId = $this->companyId();

            if ($companyId === null) {
                return 0;
            }

            return Applicant::query()
                ->where('company_id', $companyId)
                ->where('created_at', '>=', CarbonImmutable::now(Interview::displayTimezone())->startOfWeek()->utc())
                ->count();
        });
    }

    /**
     * The user's Annual / Casual / Sick leave this year in the active
     * company (EmployeeDashboardOverviewWidget's figures), or null without
     * an employee record.
     *
     * @return array<string, mixed>|null
     */
    public function leaveSummary(): ?array
    {
        return $this->remember('leave_summary', function (): ?array {
            $employee = $this->employee();

            return $employee
                ? EmployeeDashboardOverviewWidget::calculateCanonicalLeaves($employee, (int) $this->companyId(), LeaveBalanceService::today()->endOfYear())
                : null;
        });
    }

    /**
     * The "Today" box: a short list of what needs the user today, each with
     * a count and one link. A row is only listed when the user may open its
     * page, and every number is limited to the active company (and, for a
     * line manager, to their own team). "mine" is the user's own day,
     * "team" is the work they do for others. Each row names the action
     * center items it stands for ("replaces"), so the list below does not
     * repeat them.
     *
     * @return array{mine: array<int, array<string, mixed>>, team: array<int, array<string, mixed>>, team_label: string}
     */
    public function todayTasks(): array
    {
        return $this->remember('today_tasks', function (): array {
            $context = $this->resolve();
            $tasks = ['mine' => [], 'team' => [], 'team_label' => $context['is_hr'] || $context['is_ceo'] ? 'For the company' : 'For your team'];
            $user = $context['user'];
            $companyId = $context['company_id'];

            if (! $user || $companyId === null) {
                return $tasks;
            }

            $row = fn (string $key, string $label, ?string $url, string $icon, string $tone, string $action, ?int $count = null, ?string $hint = null, array $replaces = []): array => compact('key', 'label', 'url', 'icon', 'tone', 'action', 'count', 'hint', 'replaces');

            if ($context['is_employee']) {
                $tasks['mine'] = array_values(array_filter($this->myDayRows($user, $row)));
            }

            $tasks['team'] = array_values(array_filter($this->teamRows($context, $row)));

            return $tasks;
        });
    }

    /**
     * Ids of the action center items the Today box already shows.
     *
     * @return array<int, string>
     */
    public function todayReplacedActionIds(): array
    {
        $tasks = $this->todayTasks();

        return collect([...$tasks['mine'], ...$tasks['team']])->pluck('replaces')->flatten()->unique()->values()->all();
    }

    /**
     * @param  Closure(string, string, ?string, string, string, string, ?int, ?string, array<int, string>): array<string, mixed>  $row
     * @return array<int, array<string, mixed>|null>
     */
    protected function myDayRows(User $user, Closure $row): array
    {
        $rows = [];
        $attendanceUrl = $this->pageUrl(MyAttendance::class);
        $state = $attendanceUrl ? $this->todayState($user) : null;
        $checkInIds = ['emp_missing_checkin', 'emp_active_shift'];

        $rows[] = match (true) {
            $state === null                                                                         => null,
            ($state['state'] ?? null) === 'not_checked_in' && ($state['mode'] ?? 'none') !== 'none' => $row('check_in', 'You have not checked in yet', $attendanceUrl, 'heroicon-o-finger-print', 'warning', 'Check in', null, 'Tap to record your arrival', $checkInIds),
            ($state['state'] ?? null) === 'checked_in'                                              => $row('check_in', 'Checked in at '.$state['check_in'], $attendanceUrl, 'heroicon-o-clock', 'success', 'Check out', null, ! empty($state['is_shift_complete']) ? 'Your shift is done' : 'On shift for '.($state['elapsed_formatted'] ?? ''), $checkInIds),
            ($state['state'] ?? null) === 'checked_out'                                             => $row('check_in', 'Checked out for today', $attendanceUrl, 'heroicon-o-check-circle', 'success', 'My attendance', null, 'In '.$state['check_in'].', out '.$state['check_out'], $checkInIds),
            default                                                                                 => null,
        };

        $leave = $this->leaveSummary();
        $leaveUrl = $this->resourceUrl(MyTimeOffResource::class, 'create') ?? $this->resourceUrl(MyTimeOffResource::class);

        if ($leave !== null && $leaveUrl !== null) {
            $left = (float) $leave['total_left'];
            $hint = collect($leave['cards'])->map(fn (array $card): string => str_replace(' Leave', '', $card['name']).' '.$this->days((float) $card['left']))->join(' · ');
            $rows[] = $row('leave_balance', $this->days($left).' of leave left', $leaveUrl, 'heroicon-o-sun', 'info', 'Request leave', null, $hint);
        }

        $myRequests = $this->pendingActionCount('emp_my_requests_pending');
        $requestsUrl = $this->resourceUrl(EmployeeRequestResource::class);

        if ($myRequests > 0 && $requestsUrl !== null) {
            $rows[] = $row('my_requests', 'Your requests waiting for a decision', $requestsUrl, 'heroicon-o-inbox-arrow-up', 'gray', 'See my requests', $myRequests, null, ['emp_my_requests_pending']);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  Closure(string, string, ?string, string, string, string, ?int, ?string, array<int, string>): array<string, mixed>  $row
     * @return array<int, array<string, mixed>|null>
     */
    protected function teamRows(array $context, Closure $row): array
    {
        $rows = [];
        $user = $context['user'];
        $companyId = (int) $context['company_id'];
        $companyWide = $context['is_hr'] || $context['is_ceo'];
        $teamIds = $context['team_ids'];

        if (! $companyWide && ! $context['is_line_manager'] && ! $context['is_recruiter']) {
            return [];
        }

        $approvalsUrl = $this->resourceUrl(ApprovalRequestResource::class);

        if ($approvalsUrl !== null) {
            $approvals = app(ApprovalEngine::class)->actionableRequestsFor($user, $companyId)->count();

            if ($approvals > 0) {
                $rows[] = $row('approvals', 'Approvals waiting for you', $approvalsUrl, 'heroicon-o-check-badge', 'warning', 'Review', $approvals, 'Leave, requests and claims to approve', ['manager_approvals_pending', 'admin_approvals_pending']);
            }
        }

        $todayUrl = $this->pageUrl(TodayAttendancePage::class);

        if ($todayUrl !== null && ($companyWide || $context['is_line_manager'])) {
            $report = $this->todayAttendance() ?? ($companyWide ? $this->wholeCompanyAttendance() : null);

            if ($report !== null && empty($report['off_day'])) {
                if ($companyWide) {
                    $absent = (int) ($report['counts']['absent'] ?? 0);
                    $late = (int) ($report['counts']['late'] ?? 0);
                } else {
                    $rowsOfTeam = collect($report['rows'] ?? [])->only($teamIds);
                    $absent = $rowsOfTeam->where('status', TodayAttendance::ABSENT)->count();
                    $late = $rowsOfTeam->where('status', TodayAttendance::LATE)->count();
                }

                $statusUrl = fn (string $status): string => TodayAttendancePage::getUrl(['filters' => ['status' => ['values' => [$status]]]]);

                if ($absent > 0) {
                    $rows[] = $row('not_checked_in', 'Not checked in yet', $statusUrl(TodayAttendance::ABSENT), 'heroicon-o-user-minus', 'danger', 'See who', $absent, 'Not on leave, no check-in today');
                }

                if ($late > 0) {
                    $rows[] = $row('late', 'Late today', $statusUrl(TodayAttendance::LATE), 'heroicon-o-clock', 'warning', 'See who', $late);
                }
            }
        }

        $leaveUrl = $this->resourceUrl(TimeOffResource::class);

        if ($leaveUrl !== null && ($companyWide || $teamIds !== [])) {
            $leaves = $this->remember('leaves_waiting', fn (): int => Leave::query()
                ->where('company_id', $companyId)
                ->whereIn('state', self::LEAVE_WAITING_STATES)
                ->when(! $companyWide, fn ($query) => $query->whereIn('employee_id', $teamIds))
                ->count());

            if ($leaves > 0) {
                $rows[] = $row('leave_requests', 'Leave requests waiting', $leaveUrl, 'heroicon-o-calendar-days', 'warning', 'Review', $leaves, null, ['hr_leaves_pending']);
            }
        }

        if ($companyWide && $this->resourceUrl(EmployeeDocumentResource::class) !== null) {
            $documents = $this->remember('documents_to_verify', fn (): int => EmployeeDocument::query()
                ->where('company_id', $companyId)
                ->whereNull('replaced_by_id')
                ->where('status', EmployeeDocument::STATUS_PENDING)
                ->count());

            if ($documents > 0) {
                $rows[] = $row('documents', 'Documents to check', EmployeeDocumentResource::getUrl('index', ['filters' => ['pending' => ['isActive' => true]]]), 'heroicon-o-document-check', 'info', 'Check', $documents, 'Uploaded by employees');
            }
        }

        if ($context['is_recruiter']) {
            $applicantsUrl = $this->resourceUrl(ApplicantResource::class);
            $applicants = $applicantsUrl ? $this->newApplicantsThisWeek() : 0;

            if ($applicants > 0) {
                $rows[] = $row('applicants', 'New applicants this week', $applicantsUrl, 'heroicon-o-document-text', 'primary', 'See applicants', $applicants, 'Sorted by CV score');
            }
        }

        return $rows;
    }

    /** The count of one action center item (0 when it is not listed). */
    protected function pendingActionCount(string $id): int
    {
        $item = collect($this->pendingActions()['items'])->firstWhere('id', $id);

        return (int) ($item['count'] ?? 0);
    }

    protected function days(float $days): string
    {
        $formatted = rtrim(rtrim(number_format($days, 1), '0'), '.');

        return $formatted.' '.($formatted === '1' ? 'day' : 'days');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    protected function remember(string $name, Closure $compute): mixed
    {
        $key = $name.':'.(Auth::id() ?? 0).':'.($this->companyId() ?? 0);

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $compute();
        }

        return $this->memo[$key];
    }

    /**
     * The user's check-in state today, worked out once per request: the
     * action center and My day both show it.
     *
     * @return array<string, mixed>|null
     */
    public function todayState(User $user): ?array
    {
        $key = (int) $user->getKey();

        if (! array_key_exists($key, $this->todayStates)) {
            $this->todayStates[$key] = app(GeofencedAttendanceService::class)->todayState($user);
        }

        return $this->todayStates[$key];
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
        $key = Auth::id().'|'.$page.'|'.serialize($parameters);

        if (array_key_exists($key, $this->urls)) {
            return $this->urls[$key];
        }

        try {
            return $this->urls[$key] = $page::canAccess() ? $page::getUrl($parameters) : null;
        } catch (Throwable) {
            return $this->urls[$key] = null;
        }
    }

    /** @param  class-string  $resource */
    public function resourceUrl(string $resource, string $name = 'index', array $parameters = []): ?string
    {
        $key = Auth::id().'|'.$resource.'|'.$name.'|'.serialize($parameters);

        if (array_key_exists($key, $this->urls)) {
            return $this->urls[$key];
        }

        try {
            $allowed = $name === 'create' ? $resource::canCreate() : $resource::canViewAny();

            return $this->urls[$key] = $allowed ? $resource::getUrl($name, $parameters) : null;
        } catch (Throwable) {
            return $this->urls[$key] = null;
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
