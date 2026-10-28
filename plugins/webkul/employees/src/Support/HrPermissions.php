<?php

namespace Webkul\Employee\Support;

final class HrPermissions
{
    public const ViewAllRecords = 'hr_view_all_records';

    public const ViewSensitiveEmployeeData = 'hr_view_sensitive_employee_data';

    public const ManageSensitiveEmployeeData = 'hr_manage_sensitive_employee_data';

    public const ManageTeams = 'hr_manage_teams';

    public const ManageAttendance = 'hr_manage_attendance';

    public const ApproveTimesheets = 'hr_approve_timesheets';

    public const ApproveLeave = 'hr_approve_leave';

    public const ManagePerformance = 'hr_manage_performance';

    public const ManageEmployeeRequests = 'hr_manage_employee_requests';

    public const ProcessFinancialRequests = 'hr_process_financial_requests';

    public const ConvertCandidates = 'hr_convert_candidates';

    /** Add job ads (channels) to a job and confirm them as posted / taken down (Recruitment Stage 2.3). */
    public const ManageJobPostings = 'hr_manage_job_postings';

    /** Edit the screening question library and each job's screening questions. */
    public const ManageScreeningQuestions = 'hr_manage_screening_questions';

    /** Connect the company careers website and handle its failed application imports (Recruitment automation A3/A4). */
    public const ManageRecruitmentIntegrations = 'hr_manage_recruitment_integrations';

    public const ViewAnalytics = 'page_employees_hr_analytics';

    /**
     * AttendanceRecordResource::canViewAny() previously accepted ONLY
     * ManageAttendance -- there was no way to grant a role (e.g. HR
     * Auditor) read-only visibility without also handing it edit rights.
     * Fixed proactively here, same root cause and fix as
     * AccountingPermissions::ViewManualAdjustments found live during the
     * finance-roles work.
     */
    public const ViewAttendance = 'hr_view_attendance';

    /** Configure workplace coordinates/radius for browser GPS attendance. */
    public const ManageAttendanceGeofences = 'hr_manage_attendance_geofences';

    /** See raw latitude/longitude/IP/user-agent evidence behind an attendance verification. */
    public const ViewAttendanceLocationEvidence = 'hr_view_attendance_location_evidence';

    /** Approve/reject GPS attendance flagged for review. */
    public const ReviewAttendanceVerifications = 'hr_review_attendance_verifications';

    /** Same gap, same fix, for PerformanceCycleResource/PerformanceReviewResource (both gated on ManagePerformance alone). */
    public const ViewPerformance = 'hr_view_performance';

    /** Same gap, same fix, for EmployeeRequestTypeResource (gated on ManageEmployeeRequests alone). */
    public const ViewEmployeeRequestTypes = 'hr_view_employee_request_types';

    /**
     * Upload, replace, check and delete employee documents (and manage the
     * document types) of the employees in one's HR scope. Line managers do
     * not get it: their reports' documents stay private by default.
     */
    public const ManageEmployeeDocuments = 'hr_manage_employee_documents';

    /** Read-only access to the employee documents of the employees in one's HR scope (auditors). */
    public const ViewEmployeeDocuments = 'hr_view_employee_documents';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            self::ManageEmployeeDocuments,
            self::ViewEmployeeDocuments,
            self::ViewAllRecords,
            self::ViewSensitiveEmployeeData,
            self::ManageSensitiveEmployeeData,
            self::ManageTeams,
            self::ManageAttendance,
            self::ApproveTimesheets,
            self::ApproveLeave,
            self::ManagePerformance,
            self::ManageEmployeeRequests,
            self::ProcessFinancialRequests,
            self::ConvertCandidates,
            self::ManageJobPostings,
            self::ManageScreeningQuestions,
            self::ManageRecruitmentIntegrations,
            self::ViewAnalytics,
            self::ViewAttendance,
            self::ViewPerformance,
            self::ViewEmployeeRequestTypes,
            self::ManageAttendanceGeofences,
            self::ViewAttendanceLocationEvidence,
            self::ReviewAttendanceVerifications,
            // Added for the HR-roles work: Administrator/Manager/Officer/
            // Recruiter/Hiring-Manager/Auditor all need slices of these
            // config-CRUD resources, and the registrar can only grant a
            // permission name present in this master list.
            'view_any_employee_department', 'view_employee_department', 'create_employee_department', 'update_employee_department', 'delete_employee_department',
            'view_any_recruitment_department', 'view_recruitment_department', 'create_recruitment_department', 'update_recruitment_department',
            'view_any_employee_job::position', 'view_employee_job::position', 'create_employee_job::position', 'update_employee_job::position', 'delete_employee_job::position',
            'view_any_recruitment_job::position', 'view_recruitment_job::position', 'create_recruitment_job::position', 'update_recruitment_job::position',
            'view_any_recruitment_job::by::position', 'view_recruitment_job::by::position',
            'view_any_employee_employment::type', 'view_employee_employment::type', 'create_employee_employment::type', 'update_employee_employment::type',
            'view_any_recruitment_employment::type', 'view_recruitment_employment::type', 'create_recruitment_employment::type', 'update_recruitment_employment::type',
            'view_any_employee_work::location', 'view_employee_work::location', 'create_employee_work::location', 'update_employee_work::location',
            'view_any_employee_employee::category', 'view_employee_employee::category', 'create_employee_employee::category', 'update_employee_employee::category',
            'view_any_employee_skill::type', 'view_employee_skill::type', 'create_employee_skill::type', 'update_employee_skill::type',
            'view_any_recruitment_skill::type', 'view_recruitment_skill::type', 'create_recruitment_skill::type', 'update_recruitment_skill::type',
            'view_any_employee_departure::reason', 'view_employee_departure::reason', 'create_employee_departure::reason', 'update_employee_departure::reason',
            'view_any_employee_employee', 'view_employee_employee', 'create_employee_employee', 'update_employee_employee', 'delete_employee_employee',
            'view_any_employee_employee::skill', 'view_employee_employee::skill', 'create_employee_employee::skill', 'update_employee_employee::skill',
            'view_any_employee_activity::plan', 'view_employee_activity::plan', 'create_employee_activity::plan', 'update_employee_activity::plan',
            'view_any_employee_employee::request', 'view_employee_employee::request',
            'view_any_employee_employee::request::type', 'view_employee_employee::request::type',
            // The shared Approval Queue (Webkul\Support\Filament\Resources\
            // ApprovalRequestResource) is where every ApprovalStep's actual
            // approve/reject buttons live. Any role this file routes an
            // ApprovalStep to (HR Review, Sensitive Data Review, ...) needs
            // this to even load that page -- canAct() being true is not
            // enough on its own. Confirmed missing live: routing "HR
            // Review"/"Sensitive Data Review" to hr_manager/
            // sensitive_data_custodian worked at the service layer (proven
            // by Pest, which calls ApprovalEngine directly) but 403'd in
            // the real browser, because neither role held this. The real
            // Finance roles (controller/vp_finance) already had it.
            'view_any_support_approval::request', 'view_support_approval::request',
            'view_any_support_calendar', 'view_support_calendar', 'create_support_calendar', 'update_support_calendar', 'delete_support_calendar',
            'view_any_employee_attendance::record', 'view_employee_attendance::record',
            'view_any_employee_performance::cycle', 'view_employee_performance::cycle', 'create_employee_performance::cycle', 'update_employee_performance::cycle',
            'view_any_employee_performance::review', 'view_employee_performance::review', 'create_employee_performance::review', 'update_employee_performance::review',
            'page_employee_hr_analytics',
            'page_recruitment_recruitments',
            'view_any_time_off_time::off', 'view_time_off_time::off', 'update_time_off_time::off',
            // Company leave overview (calendar page + dashboard widgets). The widgets
            // and page also require the Time Off management permission in code
            // (Webkul\TimeOff\Services\LeaveOversight), so employees never see them.
            'page_time_off_overview', 'widget_time_off_overview_calendar_widget', 'widget_time_off_leave_type_widget',
            // Personal leave widgets: only ever show the viewer's own leave and balance.
            'widget_time_off_calendar_widget', 'widget_time_off_my_time_off_widget',
            'view_any_time_off_leave::type', 'view_time_off_leave::type', 'create_time_off_leave::type', 'update_time_off_leave::type',
            'view_any_time_off_allocation', 'view_time_off_allocation', 'create_time_off_allocation', 'update_time_off_allocation',
            'view_any_time_off_accrual::plan', 'view_time_off_accrual::plan', 'create_time_off_accrual::plan', 'update_time_off_accrual::plan',
            'view_any_time_off_mandatory::day', 'view_time_off_mandatory::day', 'create_time_off_mandatory::day', 'update_time_off_mandatory::day',
            'view_any_time_off_public::holiday', 'view_time_off_public::holiday', 'create_time_off_public::holiday', 'update_time_off_public::holiday',
            'view_any_time_off_activity::type', 'view_time_off_activity::type', 'create_time_off_activity::type', 'update_time_off_activity::type',
            'view_any_recruitment_applicant', 'view_recruitment_applicant', 'create_recruitment_applicant', 'update_recruitment_applicant',
            // Interviews list (Recruitment Stage 2.3): see every interview of the company, not only your own.
            'view_any_recruitment_interview', 'view_recruitment_interview',
            'view_any_recruitment_applicant::category', 'view_recruitment_applicant::category',
            'view_any_recruitment_candidate', 'view_recruitment_candidate', 'create_recruitment_candidate', 'update_recruitment_candidate',
            'view_any_recruitment_stage', 'view_recruitment_stage', 'create_recruitment_stage', 'update_recruitment_stage',
            'view_any_recruitment_degree', 'view_recruitment_degree', 'create_recruitment_degree', 'update_recruitment_degree',
            'view_any_recruitment_refuse::reason', 'view_recruitment_refuse::reason', 'create_recruitment_refuse::reason', 'update_recruitment_refuse::reason',
            'view_any_recruitment_activity::plan', 'view_recruitment_activity::plan',
            'view_any_recruitment_activity::type', 'view_recruitment_activity::type',
            'view_any_recruitment_u::t::m::medium', 'view_recruitment_u::t::m::medium',
            'view_any_recruitment_u::t::m::source', 'view_recruitment_u::t::m::source',
            'view_any_timesheet_timesheet', 'view_timesheet_timesheet', 'create_timesheet_timesheet', 'update_timesheet_timesheet', 'delete_timesheet_timesheet',
        ];
    }

    /**
     * Confirmed live, not assumed: a plain line manager (an employee who is
     * someone's Employee.parent_id, holding no special role at all) can be
     * correctly resolved as the approver for a hierarchy_route:
     * 'requester_manager' step -- ApprovalEngine::canAct() returns true for
     * them -- but without a permission granting access to the shared
     * Approval Queue page (Webkul\Support\Filament\Resources\
     * ApprovalRequestResource), they 403 trying to load the one screen
     * where that step's actual Approve/Reject buttons exist. This bundle is
     * HrPermissionRegistrar::synchronize()'s already-existing mechanism for
     * exactly this tier (auto-granted to any role literally named
     * "manager"/"department_manager"/"team_manager") -- it just never
     * included queue access.
     *
     * @return array<int, string>
     */
    public static function manager(): array
    {
        return [
            self::ManageAttendance,
            self::ApproveTimesheets,
            self::ApproveLeave,
            self::ManagePerformance,
            self::ManageEmployeeRequests,
            self::ViewAnalytics,
            // Line managers review their own reports' flagged attempts, but do
            // NOT get ViewAttendanceLocationEvidence (raw coordinates/IP).
            self::ReviewAttendanceVerifications,
            'view_any_support_approval::request', 'view_support_approval::request',
            // Their OWN leave on the dashboard (self-scoped widgets).
            'widget_time_off_calendar_widget', 'widget_time_off_my_time_off_widget',
            // ApproveLeave ('hr_approve_leave') only governs submitting a
            // leave request on someone else's behalf -- it does NOT gate
            // the Time Off -> Management screen itself, which Filament
            // authorizes against these resource-level permissions. Without
            // them a line manager has no way to even see, let alone act
            // on, their own reports' leave requests -- confirmed live: the
            // Manager role had zero Time Off permissions of any kind.
            'view_any_time_off_time::off', 'view_time_off_time::off', 'update_time_off_time::off',
            'view_any_time_off_allocation', 'view_time_off_allocation',
        ];
    }

    /**
     * HR Administrator -- technical HR system setup (departments, job
     * positions, employment types, work locations, request-type
     * categories). Deliberately NOT an approval role: no ApproveLeave/
     * ApproveTimesheets, no ViewAllRecords, no sensitive-data access --
     * mirrors ERP Administrator's "technical, not a decision-maker"
     * restriction from the finance-roles spec.
     *
     * @return array<int, string>
     */
    public static function hrAdministrator(): array
    {
        return [
            self::ManageEmployeeRequests,
            self::ManageTeams,
            self::ManageAttendanceGeofences,
            self::ManageJobPostings,
            self::ManageScreeningQuestions,
            self::ManageRecruitmentIntegrations,
            'view_any_employee_department', 'view_employee_department', 'create_employee_department', 'update_employee_department', 'delete_employee_department',
            'view_any_recruitment_department', 'view_recruitment_department', 'create_recruitment_department', 'update_recruitment_department',
            'view_any_employee_job::position', 'view_employee_job::position', 'create_employee_job::position', 'update_employee_job::position', 'delete_employee_job::position',
            'view_any_recruitment_job::position', 'view_recruitment_job::position', 'create_recruitment_job::position', 'update_recruitment_job::position',
            'view_any_recruitment_job::by::position', 'view_recruitment_job::by::position',
            'view_any_employee_employment::type', 'view_employee_employment::type', 'create_employee_employment::type', 'update_employee_employment::type',
            'view_any_recruitment_employment::type', 'view_recruitment_employment::type', 'create_recruitment_employment::type', 'update_recruitment_employment::type',
            'view_any_employee_work::location', 'view_employee_work::location', 'create_employee_work::location', 'update_employee_work::location',
            'view_any_employee_employee::category', 'view_employee_employee::category', 'create_employee_employee::category', 'update_employee_employee::category',
            'view_any_employee_skill::type', 'view_employee_skill::type', 'create_employee_skill::type', 'update_employee_skill::type',
            'view_any_recruitment_skill::type', 'view_recruitment_skill::type', 'create_recruitment_skill::type', 'update_recruitment_skill::type',
            'view_any_employee_departure::reason', 'view_employee_departure::reason', 'create_employee_departure::reason', 'update_employee_departure::reason',
            'view_any_employee_employee::request::type', 'view_employee_employee::request::type',
            'view_any_time_off_leave::type', 'view_time_off_leave::type', 'create_time_off_leave::type', 'update_time_off_leave::type',
            'view_any_time_off_accrual::plan', 'view_time_off_accrual::plan', 'create_time_off_accrual::plan', 'update_time_off_accrual::plan',
            'view_any_time_off_mandatory::day', 'view_time_off_mandatory::day', 'create_time_off_mandatory::day', 'update_time_off_mandatory::day',
            'view_any_time_off_public::holiday', 'view_time_off_public::holiday', 'create_time_off_public::holiday', 'update_time_off_public::holiday',
            'view_any_time_off_activity::type', 'view_time_off_activity::type', 'create_time_off_activity::type', 'update_time_off_activity::type',
            'view_any_recruitment_degree', 'view_recruitment_degree', 'create_recruitment_degree', 'update_recruitment_degree',
            'view_any_recruitment_refuse::reason', 'view_recruitment_refuse::reason', 'create_recruitment_refuse::reason', 'update_recruitment_refuse::reason',
            'view_any_recruitment_applicant::category', 'view_recruitment_applicant::category',
            'view_any_recruitment_u::t::m::medium', 'view_recruitment_u::t::m::medium',
            'view_any_recruitment_u::t::m::source', 'view_recruitment_u::t::m::source',
            'view_any_support_calendar', 'view_support_calendar', 'create_support_calendar', 'update_support_calendar', 'delete_support_calendar',
        ];
    }

    /**
     * HR Manager -- the senior operational HR role. ViewAllRecords is
     * what actually matters here: without it, HrHierarchyService scopes
     * every HR screen down to "your own reporting tree only" (see
     * HrHierarchyService::visibleEmployeeIds()), which is correct for a
     * regular employee but wrong for someone whose job is managing HR
     * company-wide.
     *
     * @return array<int, string>
     */
    public static function hrManager(): array
    {
        return [
            self::ViewAllRecords,
            self::ManageEmployeeDocuments,
            self::ApproveLeave,
            self::ApproveTimesheets,
            self::ManagePerformance,
            self::ManageAttendance,
            self::ManageEmployeeRequests,
            self::ViewAnalytics,
            self::ManageAttendanceGeofences,
            self::ViewAttendanceLocationEvidence,
            self::ReviewAttendanceVerifications,
            'view_any_employee_employee', 'view_employee_employee', 'create_employee_employee', 'update_employee_employee',
            'view_any_employee_department', 'view_employee_department',
            'view_any_employee_job::position', 'view_employee_job::position',
            'view_any_time_off_time::off', 'view_time_off_time::off', 'update_time_off_time::off',
            'view_any_time_off_allocation', 'view_time_off_allocation', 'create_time_off_allocation', 'update_time_off_allocation',
            'page_time_off_overview', 'widget_time_off_overview_calendar_widget', 'widget_time_off_leave_type_widget',
            // Personal leave widgets: only ever show the viewer's own leave and balance.
            'widget_time_off_calendar_widget', 'widget_time_off_my_time_off_widget',
            'view_any_employee_employee::request', 'view_employee_employee::request',
            'page_employee_hr_analytics',
            'view_any_support_approval::request', 'view_support_approval::request',
            'view_any_support_calendar', 'view_support_calendar', 'create_support_calendar', 'update_support_calendar', 'delete_support_calendar',
        ];
    }

    /**
     * HR Officer -- daily HR data entry. No approvals (ApproveLeave/
     * ApproveTimesheets), no sensitive-data access, no ViewAllRecords --
     * scoped to their own hierarchy like any regular employee, just with
     * broader employee-record CRUD on top.
     *
     * @return array<int, string>
     */
    public static function hrOfficer(): array
    {
        return [
            self::ManageEmployeeDocuments,
            'view_any_employee_employee', 'view_employee_employee', 'create_employee_employee', 'update_employee_employee',
            'view_any_employee_employee::skill', 'view_employee_employee::skill', 'create_employee_employee::skill', 'update_employee_employee::skill',
            'view_any_employee_department', 'view_employee_department',
            'view_any_employee_job::position', 'view_employee_job::position',
            'view_any_time_off_time::off', 'view_time_off_time::off',
            'view_any_time_off_allocation', 'view_time_off_allocation',
            'view_any_employee_employee::request', 'view_employee_employee::request',
        ];
    }

    /**
     * Sensitive-Data Custodian -- the narrow, deliberately separate
     * permission for salary/medical/disciplinary fields on the Employee
     * form. Meant to be assigned ALONGSIDE another role (HR Manager/
     * Officer), not instead of one -- Spatie supports multiple roles per
     * user.
     *
     * @return array<int, string>
     */
    public static function sensitiveDataCustodian(): array
    {
        return [
            self::ViewSensitiveEmployeeData,
            self::ManageSensitiveEmployeeData,
            'view_any_employee_employee', 'view_employee_employee',
            'view_any_support_approval::request', 'view_support_approval::request',
        ];
    }

    /**
     * Recruiter -- creates job postings and enters candidates/
     * applicants. Deliberately excludes ConvertCandidates: turning a
     * candidate into a real employee is Hiring Manager's call, not the
     * recruiter's own.
     *
     * @return array<int, string>
     */
    public static function recruiter(): array
    {
        return [
            self::ManageJobPostings,
            self::ManageScreeningQuestions,
            'view_any_recruitment_applicant', 'view_recruitment_applicant', 'create_recruitment_applicant', 'update_recruitment_applicant',
            'view_any_recruitment_interview', 'view_recruitment_interview',
            'view_any_recruitment_applicant::category', 'view_recruitment_applicant::category',
            'view_any_recruitment_candidate', 'view_recruitment_candidate', 'create_recruitment_candidate', 'update_recruitment_candidate',
            'view_any_recruitment_job::position', 'view_recruitment_job::position',
            'view_any_recruitment_job::by::position', 'view_recruitment_job::by::position',
            'view_any_recruitment_stage', 'view_recruitment_stage', 'update_recruitment_stage',
            'view_any_recruitment_degree', 'view_recruitment_degree',
            'view_any_recruitment_refuse::reason', 'view_recruitment_refuse::reason',
            'view_any_recruitment_activity::plan', 'view_recruitment_activity::plan',
        ];
    }

    /**
     * Hiring Manager -- everything Recruiter has, plus the one
     * permission that actually matters: ConvertCandidates.
     *
     * @return array<int, string>
     */
    public static function hiringManager(): array
    {
        return array_values(array_unique(array_merge(self::recruiter(), [
            self::ConvertCandidates,
        ])));
    }

    /**
     * HR Auditor -- read-heavy/write-light across the whole HR surface,
     * including sensitive data (for compliance review) and ViewAllRecords
     * (an auditor scoped to only their own reporting tree couldn't audit
     * anything). Explicitly no create/update/delete/approve/convert
     * permission anywhere.
     *
     * @return array<int, string>
     */
    public static function hrAuditor(): array
    {
        return [
            self::ViewAllRecords,
            self::ViewSensitiveEmployeeData,
            self::ViewEmployeeDocuments,
            self::ViewAnalytics,
            self::ViewAttendance,
            self::ViewAttendanceLocationEvidence,
            self::ViewPerformance,
            self::ViewEmployeeRequestTypes,
            'view_any_employee_employee', 'view_employee_employee',
            'view_any_employee_employee::skill', 'view_employee_employee::skill',
            'view_any_employee_department', 'view_employee_department',
            'view_any_employee_job::position', 'view_employee_job::position',
            'view_any_employee_employment::type', 'view_employee_employment::type',
            'view_any_employee_work::location', 'view_employee_work::location',
            'view_any_employee_employee::category', 'view_employee_employee::category',
            'view_any_employee_skill::type', 'view_employee_skill::type',
            'view_any_employee_departure::reason', 'view_employee_departure::reason',
            'view_any_employee_attendance::record', 'view_employee_attendance::record',
            'view_any_employee_performance::cycle', 'view_employee_performance::cycle',
            'view_any_employee_performance::review', 'view_employee_performance::review',
            'view_any_employee_employee::request', 'view_employee_employee::request',
            'view_any_employee_employee::request::type', 'view_employee_employee::request::type',
            'view_any_time_off_time::off', 'view_time_off_time::off',
            'view_any_time_off_leave::type', 'view_time_off_leave::type',
            'view_any_time_off_allocation', 'view_time_off_allocation',
            'view_any_recruitment_applicant', 'view_recruitment_applicant',
            'view_any_recruitment_interview', 'view_recruitment_interview',
            'view_any_recruitment_candidate', 'view_recruitment_candidate',
            'view_any_recruitment_job::position', 'view_recruitment_job::position',
            'view_any_recruitment_stage', 'view_recruitment_stage',
            'page_employee_hr_analytics',
        ];
    }
}
