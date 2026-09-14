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

    /** Same gap, same fix, for PerformanceCycleResource/PerformanceReviewResource (both gated on ManagePerformance alone). */
    public const ViewPerformance = 'hr_view_performance';

    /** Same gap, same fix, for EmployeeRequestTypeResource (gated on ManageEmployeeRequests alone). */
    public const ViewEmployeeRequestTypes = 'hr_view_employee_request_types';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
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
            self::ViewAnalytics,
            self::ViewAttendance,
            self::ViewPerformance,
            self::ViewEmployeeRequestTypes,
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
            'view_any_employee_attendance::record', 'view_employee_attendance::record',
            'view_any_employee_performance::cycle', 'view_employee_performance::cycle', 'create_employee_performance::cycle', 'update_employee_performance::cycle',
            'view_any_employee_performance::review', 'view_employee_performance::review', 'create_employee_performance::review', 'update_employee_performance::review',
            'page_employee_hr_analytics',
            'view_any_time_off_time::off', 'view_time_off_time::off', 'update_time_off_time::off',
            'view_any_time_off_leave::type', 'view_time_off_leave::type', 'create_time_off_leave::type', 'update_time_off_leave::type',
            'view_any_time_off_allocation', 'view_time_off_allocation',
            'view_any_time_off_accrual::plan', 'view_time_off_accrual::plan', 'create_time_off_accrual::plan', 'update_time_off_accrual::plan',
            'view_any_time_off_mandatory::day', 'view_time_off_mandatory::day', 'create_time_off_mandatory::day', 'update_time_off_mandatory::day',
            'view_any_time_off_public::holiday', 'view_time_off_public::holiday', 'create_time_off_public::holiday', 'update_time_off_public::holiday',
            'view_any_time_off_activity::type', 'view_time_off_activity::type', 'create_time_off_activity::type', 'update_time_off_activity::type',
            'view_any_recruitment_applicant', 'view_recruitment_applicant', 'create_recruitment_applicant', 'update_recruitment_applicant',
            'view_any_recruitment_applicant::category', 'view_recruitment_applicant::category',
            'view_any_recruitment_candidate', 'view_recruitment_candidate', 'create_recruitment_candidate', 'update_recruitment_candidate',
            'view_any_recruitment_stage', 'view_recruitment_stage', 'create_recruitment_stage', 'update_recruitment_stage',
            'view_any_recruitment_degree', 'view_recruitment_degree', 'create_recruitment_degree', 'update_recruitment_degree',
            'view_any_recruitment_refuse::reason', 'view_recruitment_refuse::reason', 'create_recruitment_refuse::reason', 'update_recruitment_refuse::reason',
            'view_any_recruitment_activity::plan', 'view_recruitment_activity::plan',
            'view_any_recruitment_activity::type', 'view_recruitment_activity::type',
            'view_any_recruitment_u::t::m::medium', 'view_recruitment_u::t::m::medium',
            'view_any_recruitment_u::t::m::source', 'view_recruitment_u::t::m::source',
        ];
    }

    /** @return array<int, string> */
    public static function manager(): array
    {
        return [
            self::ManageAttendance,
            self::ApproveTimesheets,
            self::ApproveLeave,
            self::ManagePerformance,
            self::ManageEmployeeRequests,
            self::ViewAnalytics,
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
            self::ApproveLeave,
            self::ApproveTimesheets,
            self::ManagePerformance,
            self::ManageAttendance,
            self::ManageEmployeeRequests,
            self::ViewAnalytics,
            'view_any_employee_employee', 'view_employee_employee', 'create_employee_employee', 'update_employee_employee',
            'view_any_employee_department', 'view_employee_department',
            'view_any_employee_job::position', 'view_employee_job::position',
            'view_any_time_off_time::off', 'view_time_off_time::off', 'update_time_off_time::off',
            'view_any_time_off_allocation', 'view_time_off_allocation', 'create_time_off_allocation', 'update_time_off_allocation',
            'view_any_employee_employee::request', 'view_employee_employee::request',
            'page_employee_hr_analytics',
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
            'view_any_recruitment_applicant', 'view_recruitment_applicant', 'create_recruitment_applicant', 'update_recruitment_applicant',
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
            self::ViewAnalytics,
            self::ViewAttendance,
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
            'view_any_recruitment_candidate', 'view_recruitment_candidate',
            'view_any_recruitment_job::position', 'view_recruitment_job::position',
            'view_any_recruitment_stage', 'view_recruitment_stage',
            'page_employee_hr_analytics',
        ];
    }
}
