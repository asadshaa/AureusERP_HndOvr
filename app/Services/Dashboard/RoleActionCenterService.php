<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Enums\BankReviewStatus;
use Webkul\Accounting\Models\BankTransactionMapping;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\Checklists\EmployeeChecklistService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\Timesheet\Models\Timesheet;

class RoleActionCenterService
{
    /**
     * Get tailored pending actions for the given user.
     *
     * @return array{
     *     user: array{id: int, name: string, role_label: string},
     *     pending_count: int,
     *     items: array<int, array<string, mixed>>
     * }
     */
    public function getPendingActionsFor(User $user, int $companyId): array
    {
        $items = [];
        $today = now()->toDateString();

        $isAdmin = $user->hasRole(['Admin', 'Super Admin']) || (int) $user->id === 1;
        $isHr = $user->hasRole(['Hr_manager', 'HR Administrator'])
            || $user->can(HrPermissions::ManageAttendance)
            || $user->can('hr_manage_employee_requests');

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->first();

        $subordinateIds = $employee
            ? Employee::query()->where('company_id', $companyId)->where('parent_id', $employee->id)->pluck('id')->toArray()
            : [];
        $isManager = count($subordinateIds) > 0 || $user->hasRole('Manager');

        // Determine user's primary role label for display
        $roleLabel = 'Employee';
        if ($isAdmin) {
            $roleLabel = 'Administrator';
        } elseif ($isHr) {
            $roleLabel = 'HR Manager';
        } elseif ($isManager) {
            $roleLabel = 'Line Manager';
        }

        // =========================================================================
        // 1. HR MANAGER PENDING ACTIONS
        // =========================================================================
        if ($isHr || $isAdmin) {
            // Flagged Attendance punches awaiting HR review
            $flaggedPunches = AttendanceRecord::query()
                ->where('company_id', $companyId)
                ->whereDate('attendance_date', $today)
                ->where('verification_status', 'needs_review')
                ->count();

            if ($flaggedPunches > 0) {
                $items[] = [
                    'id'           => 'hr_attendance_review',
                    'title'        => "{$flaggedPunches} Attendance Flag(s) Awaiting Review",
                    'urgency'      => 'warning',
                    'icon'         => 'heroicon-o-clock',
                    'description'  => 'Employee punches flagged for geofence distance or low accuracy need HR review.',
                    'action_label' => 'Review Attendance →',
                    'url'          => route('filament.admin.resources.attendance-records.index'),
                ];
            }

            // Leaves awaiting HR approval
            $pendingLeaves = Leave::query()
                ->where('company_id', $companyId)
                ->whereIn('state', ['confirm', 'validate_one'])
                ->count();

            if ($pendingLeaves > 0) {
                $items[] = [
                    'id'           => 'hr_leaves_pending',
                    'title'        => "{$pendingLeaves} Leave Request(s) Awaiting Approval",
                    'urgency'      => 'warning',
                    'icon'         => 'heroicon-o-calendar-days',
                    'description'  => 'Time-off applications submitted by employees awaiting final HR sign-off.',
                    'action_label' => 'Review Leaves →',
                    'url'          => route('filament.admin.time-off.management.resources.time-offs.index'),
                ];
            }

            // Leave Allocations awaiting HR
            $pendingAllocations = LeaveAllocation::query()
                ->where('company_id', $companyId)
                ->whereIn('state', ['confirm', 'validate_one'])
                ->count();

            if ($pendingAllocations > 0) {
                $items[] = [
                    'id'           => 'hr_allocations_pending',
                    'title'        => "{$pendingAllocations} Leave Allocation(s) Pending",
                    'urgency'      => 'info',
                    'icon'         => 'heroicon-o-folder-plus',
                    'description'  => 'Employee annual or sick leave balance allocation requests awaiting review.',
                    'action_label' => 'Review Allocations →',
                    'url'          => route('filament.admin.time-off.management.resources.allocations.index'),
                ];
            }

            // Employee requests / claims pending HR
            $pendingEmpRequests = EmployeeRequest::query()
                ->where('company_id', $companyId)
                ->where('status', 'pending_approval')
                ->count();

            if ($pendingEmpRequests > 0) {
                $items[] = [
                    'id'           => 'hr_emp_requests_pending',
                    'title'        => "{$pendingEmpRequests} Employee Request(s) Pending",
                    'urgency'      => 'warning',
                    'icon'         => 'heroicon-o-inbox-arrow-down',
                    'description'  => 'Employee requests (time changes, expense claims) waiting for approval.',
                    'action_label' => 'View Requests →',
                    'url'          => route('filament.admin.resources.employee-requests.index'),
                ];
            }
        }

        // =========================================================================
        // 2. LINE MANAGER PENDING ACTIONS
        // =========================================================================
        if ($isManager && ! $isAdmin) {
            // ApprovalRequests where this manager can act
            $pendingForMe = app(ApprovalEngine::class)->actionableRequestsFor($user, (int) $companyId)->count();

            if ($pendingForMe > 0) {
                $items[] = [
                    'id'           => 'manager_approvals_pending',
                    'title'        => "{$pendingForMe} Direct Report Request(s) Awaiting Your Approval",
                    'urgency'      => 'warning',
                    'icon'         => 'heroicon-o-shield-check',
                    'description'  => 'Time change or claim requests submitted by your team members require your sign-off.',
                    'action_label' => 'Review Team Requests →',
                    'url'          => route('filament.admin.resources.employee-requests.index'),
                ];
            }

            // Subordinate Timesheets submitted
            if (count($subordinateIds) > 0) {
                $subordinateUserIds = Employee::query()->whereIn('id', $subordinateIds)->pluck('user_id')->filter()->toArray();
                $pendingTimesheets = Timesheet::query()
                    ->where('company_id', $companyId)
                    ->where('workflow_status', 'submitted')
                    ->whereIn('user_id', $subordinateUserIds)
                    ->count();

                if ($pendingTimesheets > 0) {
                    $items[] = [
                        'id'           => 'manager_timesheets_pending',
                        'title'        => "{$pendingTimesheets} Timesheet(s) Awaiting Your Review",
                        'urgency'      => 'warning',
                        'icon'         => 'heroicon-o-table-cells',
                        'description'  => 'Timesheet work logs submitted by your team members awaiting manager approval.',
                        'action_label' => 'Review Timesheets →',
                        'url'          => route('filament.admin.resources.employee-requests.index'),
                    ];
                }
            }
        }

        // =========================================================================
        // 3. ADMIN / SUPER ADMIN PENDING ACTIONS (Raza Afzal)
        // =========================================================================
        if ($isAdmin) {
            // Multi-step approvals
            $allPendingApprovals = ApprovalRequest::query()
                ->where('company_id', $companyId)
                ->where('status', 'pending')
                ->count();

            if ($allPendingApprovals > 0) {
                $items[] = [
                    'id'           => 'admin_approvals_pending',
                    'title'        => "{$allPendingApprovals} Company Approval Request(s) Active",
                    'urgency'      => 'warning',
                    'icon'         => 'heroicon-o-shield-check',
                    'description'  => 'Purchase orders, expense claims, and workflow requests awaiting step sign-off.',
                    'action_label' => 'Approval Center →',
                    'url'          => route('filament.admin.resources.approval-requests.index'),
                ];
            }

            // Draft Invoices / Bills awaiting validation & posting
            $draftInvoices = Move::query()
                ->where('company_id', $companyId)
                ->where('state', 'draft')
                ->whereIn('move_type', ['out_invoice', 'in_invoice'])
                ->count();

            if ($draftInvoices > 0) {
                $items[] = [
                    'id'           => 'admin_draft_invoices',
                    'title'        => "{$draftInvoices} Unposted Invoice(s) / Bill(s)",
                    'urgency'      => 'info',
                    'icon'         => 'heroicon-o-document-text',
                    'description'  => 'Draft invoices or vendor bills created but not yet posted to the General Ledger.',
                    'action_label' => 'View Invoices →',
                    'url'          => route('filament.admin.resources.invoices.index'),
                ];
            }

            // Unmapped bank transactions
            $unmappedBank = BankTransactionMapping::query()
                ->where('company_id', $companyId)
                ->whereIn('review_status', [
                    BankReviewStatus::Unmapped,
                    BankReviewStatus::Suggested,
                    BankReviewStatus::NeedsReview,
                ])
                ->count();

            if ($unmappedBank > 0) {
                $items[] = [
                    'id'           => 'admin_unmapped_bank',
                    'title'        => "{$unmappedBank} Unmapped Bank Transaction(s)",
                    'urgency'      => 'warning',
                    'icon'         => 'heroicon-o-arrows-right-left',
                    'description'  => 'Imported bank statement lines pending General Ledger offset account assignment.',
                    'action_label' => 'Map Transactions →',
                    'url'          => route('filament.admin.accounting.accounting.resources.bank-transaction-mappings.index'),
                ];
            }

            // Failed background jobs
            try {
                $failedJobs = DB::table('failed_jobs')->count();
                if ($failedJobs > 0) {
                    $items[] = [
                        'id'           => 'admin_failed_jobs',
                        'title'        => "{$failedJobs} Failed Background Queue Job(s)",
                        'urgency'      => 'danger',
                        'icon'         => 'heroicon-o-cpu-chip',
                        'description'  => 'System queue tasks failed execution and require operational inspection.',
                        'action_label' => 'System Dashboard →',
                        'url'          => url('/admin'),
                    ];
                }
            } catch (Throwable) {
                // ignore
            }
        }

        // =========================================================================
        // 4. REGULAR EMPLOYEE ACTIONS (Self-Service)
        // =========================================================================
        if ($employee) {
            // Check if employee hasn't checked in yet today or is active on shift
            $todayState = app(GeofencedAttendanceService::class)->todayState($user);
            if (($todayState['state'] ?? 'none') === 'not_checked_in' && ($todayState['mode'] ?? 'none') !== 'none') {
                $items[] = [
                    'id'           => 'emp_missing_checkin',
                    'title'        => 'You Have Not Checked In Today',
                    'urgency'      => 'info',
                    'icon'         => 'heroicon-o-map-pin',
                    'description'  => 'Your work shift for today has not been recorded yet. Tap below to punch in.',
                    'action_label' => 'Check In Now →',
                    'url'          => route('filament.admin.pages.my-attendance'),
                ];
            } elseif (($todayState['state'] ?? 'none') === 'checked_in') {
                $isShiftComplete = ! empty($todayState['is_shift_complete']);
                $items[] = [
                    'id'           => 'emp_active_shift',
                    'title'        => $isShiftComplete ? 'Shift Target Complete — Ready to Check Out' : 'You Are Checked In (On Shift)',
                    'urgency'      => $isShiftComplete ? 'warning' : 'info',
                    'icon'         => 'heroicon-o-clock',
                    'description'  => "Active on shift since {$todayState['check_in']} ({$todayState['elapsed_formatted']}). Tap below when ready to clock out.",
                    'action_label' => 'Check Out →',
                    'url'          => route('filament.admin.pages.my-attendance'),
                ];
            }

            // My pending employee requests
            $myPendingRequests = EmployeeRequest::query()
                ->where('company_id', $companyId)
                ->where('requested_by', $user->id)
                ->where('status', 'pending_approval')
                ->count();

            if ($myPendingRequests > 0) {
                $items[] = [
                    'id'           => 'emp_my_requests_pending',
                    'title'        => "Your {$myPendingRequests} Request(s) Are Under Review",
                    'urgency'      => 'info',
                    'icon'         => 'heroicon-o-clock',
                    'description'  => 'Your submitted time change or claim requests are currently waiting for manager approval.',
                    'action_label' => 'Track Status →',
                    'url'          => route('filament.admin.resources.employee-requests.index'),
                ];
            }
        }

        // =========================================================================
        // 5. ONBOARDING / EXIT CHECKLIST TASKS (assigned to me, or HR's queue)
        // =========================================================================
        $checklistTasks = app(EmployeeChecklistService::class)->openTaskCounts($user, $companyId);

        if ($checklistTasks['open'] > 0) {
            $items[] = [
                'id'           => 'checklist_tasks_open',
                'title'        => "{$checklistTasks['open']} Onboarding / Exit Task(s) For You",
                'urgency'      => $checklistTasks['overdue'] > 0 ? 'danger' : 'info',
                'icon'         => 'heroicon-o-clipboard-document-check',
                'description'  => $checklistTasks['overdue'] > 0
                    ? "{$checklistTasks['overdue']} overdue. New joiner and leaver checklist tasks waiting for you."
                    : 'New joiner and leaver checklist tasks waiting for you.',
                'action_label' => 'My Tasks →',
                'url'          => route('filament.admin.pages.my-tasks'),
            ];
        }

        return [
            'user' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'role_label' => $roleLabel,
            ],
            'pending_count' => count($items),
            'items'         => $items,
        ];
    }
}
