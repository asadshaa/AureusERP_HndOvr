<?php

namespace Database\Seeders;

use Filament\Actions\Action;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Webkul\Security\Models\User;

class DummyNotificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $notifications = [
            // =========================================================================
            // RAZA AFZAL (ID: 1 - CEO / Executive Approver / System Admin)
            // =========================================================================
            [
                'user_email' => 'raza.afzal@truckitin.com',
                'title'      => 'Level 3 - CEO Approval awaiting your decision',
                'body'       => 'Claim #CLM-2026-0042 (Overseas Tech Summit & Logistics Client Visit - $3,450.00) submitted by Haider Navid has completed Line Manager & Dept Head approvals and requires your executive sign-off.',
                'status'     => 'warning',
                'icon'       => 'heroicon-o-exclamation-triangle',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_raza_ceo_approval_clm_0042',
                'action_url' => '/admin/claims/claims',
                'action_lbl' => 'Review Claim',
            ],
            [
                'user_email' => 'raza.afzal@truckitin.com',
                'title'      => 'CAPEX Request awaiting final executive review',
                'body'       => 'Capex Request #CPX-2026-0012 (Data Center Server Migration & Hardware Upgrade - $18,700.00) submitted by Infrastructure Team requires executive sign-off.',
                'status'     => 'warning',
                'icon'       => 'heroicon-o-shield-check',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_raza_capex_0012',
                'action_url' => '/admin/employee-requests/requests',
                'action_lbl' => 'View Request',
            ],
            [
                'user_email' => 'raza.afzal@truckitin.com',
                'title'      => 'H2 2026 Performance Cycle Progress Update',
                'body'       => 'H2 2026 Performance Cycle is currently active across all departments. 82% of employee self-evaluations submitted.',
                'status'     => 'info',
                'icon'       => 'heroicon-o-chart-bar',
                'icon_color' => 'info',
                'key'        => 'dummy_seed_raza_perf_cycle_update',
                'action_url' => '/admin/performance/cycles',
                'action_lbl' => 'View Cycle Analytics',
            ],
            [
                'user_email' => 'raza.afzal@truckitin.com',
                'title'      => 'Monthly Financial Close Summary',
                'body'       => 'Operating bank accounts successfully reconciled. General Ledger closing pack is ready for management review.',
                'status'     => 'success',
                'icon'       => 'heroicon-o-check-circle',
                'icon_color' => 'success',
                'key'        => 'dummy_seed_raza_financial_close',
                'action_url' => '/admin/accounting/reports',
                'action_lbl' => 'View Reports',
            ],

            // =========================================================================
            // DEMO ACCOUNTANT (ID: 2 - Accounting & Finance Reviewer)
            // =========================================================================
            [
                'user_email' => 'demo.accountant@aureus.test',
                'title'      => 'Approved reimbursement ready for Accounting review',
                'body'       => "Reimbursement 'Client Dinner & Partner Meeting' (CLM-2026-0039) for USD 420.00 has completed multi-step approval and is ready for Accounting review.",
                'status'     => 'success',
                'icon'       => 'heroicon-o-banknotes',
                'icon_color' => 'success',
                'key'        => 'dummy_seed_acct_clm_0039',
                'action_url' => '/admin/accounting/bills',
                'action_lbl' => 'View Vendor Bills',
            ],
            [
                'user_email' => 'demo.accountant@aureus.test',
                'title'      => 'Approved reimbursement ready for Accounting review',
                'body'       => "Reimbursement 'Annual Cloud DevOps Subscriptions' (CLM-2026-0036) for USD 2,890.00 has completed multi-step approval and is ready for Accounting review.",
                'status'     => 'success',
                'icon'       => 'heroicon-o-banknotes',
                'icon_color' => 'success',
                'key'        => 'dummy_seed_acct_clm_0036',
                'action_url' => '/admin/accounting/bills',
                'action_lbl' => 'View Vendor Bills',
            ],
            [
                'user_email' => 'demo.accountant@aureus.test',
                'title'      => 'Bank Statement Import: Unmapped Transactions Detected',
                'body'       => 'Bank statement import (Operating Account - 09/2026) imported 28 transactions. 2 transactions require manual GL account mapping.',
                'status'     => 'warning',
                'icon'       => 'heroicon-o-scale',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_acct_bank_import_unmapped',
                'action_url' => '/admin/accounting/bank-statements',
                'action_lbl' => 'Review Mapping',
            ],
            [
                'user_email' => 'demo.accountant@aureus.test',
                'title'      => 'Monthly Withholding Tax Statement Due',
                'body'       => 'Vendor withholding tax deduction summary for the previous month has been generated and awaits submission.',
                'status'     => 'info',
                'icon'       => 'heroicon-o-document-text',
                'icon_color' => 'info',
                'key'        => 'dummy_seed_acct_wht_report',
                'action_url' => '/admin/accounting/reports',
                'action_lbl' => 'Open Report',
            ],

            // =========================================================================
            // DEMO APPROVER (ID: 3 - Finance / Workflow Approver)
            // =========================================================================
            [
                'user_email' => 'demo.approver@aureus.test',
                'title'      => 'Finance Review awaiting your approval',
                'body'       => 'Claim #CLM-2026-0043 ($1,250.00 - Client Hospitality) has completed Step 1 (Line Manager) and requires Finance Review sign-off.',
                'status'     => 'warning',
                'icon'       => 'heroicon-o-clock',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_demo_approver_clm_0043',
                'action_url' => '/admin/claims/claims',
                'action_lbl' => 'Review Decision',
            ],
            [
                'user_email' => 'demo.approver@aureus.test',
                'title'      => 'Purchase Requisition awaiting review',
                'body'       => 'Requisition #PR-2026-0089 (Office Ergonomic Furniture - $2,100.00) requires stage 2 finance approval.',
                'status'     => 'warning',
                'icon'       => 'heroicon-o-document-check',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_demo_approver_pr_0089',
                'action_url' => '/admin/employee-requests/requests',
                'action_lbl' => 'Review Requisition',
            ],

            // =========================================================================
            // ZAINAB MALIK (ID: 41 - HR Manager)
            // =========================================================================
            [
                'user_email' => 'zainab.malik@truckitin.com',
                'title'      => 'Request awaiting your review: Leave Application [Routed to HR: no line manager assigned]',
                'body'       => 'A request (Annual Leave - 5 days) submitted by Intern Fatima Noor has been routed to HR for review because no direct line manager is assigned in the organizational hierarchy.',
                'status'     => 'warning',
                'icon'       => 'heroicon-o-user-group',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_zainab_orphan_leave_fatima',
                'action_url' => '/admin/time-off/management/time-offs',
                'action_lbl' => 'Review Leave',
            ],
            [
                'user_email' => 'zainab.malik@truckitin.com',
                'title'      => 'Self-review submitted: Hamza Tariq',
                'body'       => "Hamza Tariq has submitted their self-review for cycle 'H2 2026 Performance Review'. Line manager appraisal is in progress.",
                'status'     => 'info',
                'icon'       => 'heroicon-o-sparkles',
                'icon_color' => 'info',
                'key'        => 'dummy_seed_zainab_self_review_hamza',
                'action_url' => '/admin/performance/appraisals',
                'action_lbl' => 'View Appraisal',
            ],
            [
                'user_email' => 'zainab.malik@truckitin.com',
                'title'      => 'Probation Review Due: 3 Team Members',
                'body'       => 'Probation evaluations for Sarah Jenkins, Fazale Khaliq, and Khubaib Najeeb are due within the next 10 business days.',
                'status'     => 'warning',
                'icon'       => 'heroicon-o-academic-cap',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_zainab_probation_due',
                'action_url' => '/admin/employee/employees',
                'action_lbl' => 'View Profiles',
            ],
            [
                'user_email' => 'zainab.malik@truckitin.com',
                'title'      => 'Attendance Verification Alert',
                'body'       => '4 employees have missing biometric check-outs from Friday Oct 16. Automated HR reminder has been dispatched.',
                'status'     => 'info',
                'icon'       => 'heroicon-o-clock',
                'icon_color' => 'info',
                'key'        => 'dummy_seed_zainab_attendance_alert',
                'action_url' => '/admin/attendance/attendances',
                'action_lbl' => 'Inspect Attendance',
            ],

            // =========================================================================
            // BILAL AHMED (ID: 43 - Line Manager / Engineering Lead)
            // =========================================================================
            [
                'user_email' => 'bilal.ahmed@truckitin.com',
                'title'      => 'Leave request awaiting your approval',
                'body'       => 'Hamza Tariq has requested 3 days of Casual Leave (Oct 28 - Oct 30) for a family commitment. Please review and approve.',
                'status'     => 'warning',
                'icon'       => 'heroicon-o-calendar',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_bilal_leave_hamza',
                'action_url' => '/admin/time-off/management/time-offs',
                'action_lbl' => 'Review Request',
            ],
            [
                'user_email' => 'bilal.ahmed@truckitin.com',
                'title'      => 'Self-review submitted: Hamza Tariq',
                'body'       => "Hamza Tariq has submitted their self-review for cycle 'H2 2026 Performance Review'. Please complete the manager evaluation and goal ratings.",
                'status'     => 'warning',
                'icon'       => 'heroicon-o-clipboard-document-check',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_bilal_perf_eval_hamza',
                'action_url' => '/admin/performance/appraisals',
                'action_lbl' => 'Complete Evaluation',
            ],
            [
                'user_email' => 'bilal.ahmed@truckitin.com',
                'title'      => 'Expense Claim awaiting Step 1 (Line Manager) approval',
                'body'       => 'Hamza Tariq submitted Expense Claim #CLM-2026-0044 (Team Sprint Retrospective Dinner - $165.00). Receipts attached for verification.',
                'status'     => 'warning',
                'icon'       => 'heroicon-o-receipt-percent',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_bilal_claim_hamza_0044',
                'action_url' => '/admin/claims/claims',
                'action_lbl' => 'Review Expense',
            ],
            [
                'user_email' => 'bilal.ahmed@truckitin.com',
                'title'      => 'Team Transfer Request Initiated',
                'body'       => 'Transfer request for Junior Backend Developer joining your Engineering squad starting Nov 1 has been initiated.',
                'status'     => 'info',
                'icon'       => 'heroicon-o-arrow-right-circle',
                'icon_color' => 'info',
                'key'        => 'dummy_seed_bilal_team_transfer',
                'action_url' => '/admin/employee/employees',
                'action_lbl' => 'View Team',
            ],

            // =========================================================================
            // HAMZA TARIQ (ID: 44 - Software Engineer / Employee)
            // =========================================================================
            [
                'user_email' => 'hamza.tariq@truckitin.com',
                'title'      => 'Request approved',
                'body'       => 'Your request (Annual Leave #REQ-LV-0081 - 4 days) has been fully approved by Bilal Ahmed and HR.',
                'status'     => 'success',
                'icon'       => 'heroicon-o-check-circle',
                'icon_color' => 'success',
                'key'        => 'dummy_seed_hamza_leave_approved',
                'action_url' => '/admin/time-off/my-time-offs',
                'action_lbl' => 'View Time Off',
            ],
            [
                'user_email' => 'hamza.tariq@truckitin.com',
                'title'      => 'Performance review cycle is now open',
                'body'       => "You have been included in active performance review cycle 'H2 2026 Performance Review'. Please complete your self-review before Oct 31.",
                'status'     => 'info',
                'icon'       => 'heroicon-o-sparkles',
                'icon_color' => 'info',
                'key'        => 'dummy_seed_hamza_perf_cycle_open',
                'action_url' => '/admin/performance/appraisals',
                'action_lbl' => 'Fill Self-Review',
            ],
            [
                'user_email' => 'hamza.tariq@truckitin.com',
                'title'      => 'Reimbursement processed by Accounting',
                'body'       => 'Your expense claim #CLM-2026-0031 ($85.00 - Office Peripheral Accessories) has been approved by Accounting and scheduled for payout.',
                'status'     => 'success',
                'icon'       => 'heroicon-o-banknotes',
                'icon_color' => 'success',
                'key'        => 'dummy_seed_hamza_claim_paid',
                'action_url' => '/admin/claims/claims',
                'action_lbl' => 'View Claim',
            ],
            [
                'user_email' => 'hamza.tariq@truckitin.com',
                'title'      => 'Request awaiting review: Equipment Upgrade',
                'body'       => 'Your workstation monitor upgrade request (#REQ-EQ-0019) has advanced to Department Head Review (Haider Navid).',
                'status'     => 'info',
                'icon'       => 'heroicon-o-arrow-path',
                'icon_color' => 'info',
                'key'        => 'dummy_seed_hamza_monitor_upgrade_advanced',
                'action_url' => '/admin/employee-requests/requests',
                'action_lbl' => 'Track Status',
            ],

            // =========================================================================
            // HAIDER NAVID (ID: 47 - Department Head / Tech Lead)
            // =========================================================================
            [
                'user_email' => 'haider.navid@truckitin.com',
                'title'      => 'Step 2 - Department Head Review awaiting your approval',
                'body'       => "A request (Cloud Infrastructure Server Reservation - $750.00) completed preceding stage 'Line Manager Review' (approved by Bilal Ahmed) and requires your decision.",
                'status'     => 'warning',
                'icon'       => 'heroicon-o-exclamation-triangle',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_haider_step2_cloud',
                'action_url' => '/admin/employee-requests/requests',
                'action_lbl' => 'Review Request',
            ],
            [
                'user_email' => 'haider.navid@truckitin.com',
                'title'      => 'Team Leave Schedule: Week of Oct 27',
                'body'       => '2 members of the Engineering unit will be on approved leave next week. Sprint capacity has been adjusted.',
                'status'     => 'info',
                'icon'       => 'heroicon-o-calendar-days',
                'icon_color' => 'info',
                'key'        => 'dummy_seed_haider_team_leave_schedule',
                'action_url' => '/admin/time-off/management/time-offs',
                'action_lbl' => 'View Schedule',
            ],

            // =========================================================================
            // MEHWISH (ID: 45 - HR Specialist / Approver)
            // =========================================================================
            [
                'user_email' => 'mehwish@truckitin.com',
                'title'      => 'Employee Profile Update awaiting HR verification',
                'body'       => 'Emergency contact and CNIC document verification pending for newly onboarded engineer Fazale Khaliq.',
                'status'     => 'warning',
                'icon'       => 'heroicon-o-identification',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_mehwish_doc_verification',
                'action_url' => '/admin/employee/employees',
                'action_lbl' => 'Verify Profile',
            ],
            [
                'user_email' => 'mehwish@truckitin.com',
                'title'      => 'New Employee Onboarding: Equipment Checklist',
                'body'       => 'Laptop provisioning and email credentials setup complete for 2 new hires joining next Monday.',
                'status'     => 'success',
                'icon'       => 'heroicon-o-check-badge',
                'icon_color' => 'success',
                'key'        => 'dummy_seed_mehwish_onboarding_check',
                'action_url' => '/admin/employee/employees',
                'action_lbl' => 'View Checklist',
            ],

            // =========================================================================
            // KHURRAM (ID: 46 - Director / Level 4 Approver)
            // =========================================================================
            [
                'user_email' => 'khurram@truckitin.com',
                'title'      => 'Step 4 - Board / Executive Review awaiting your approval',
                'body'       => "A request (Annual Enterprise Software Licensing - $24,500.00) completed preceding stage 'Finance Review' and requires your executive decision.",
                'status'     => 'warning',
                'icon'       => 'heroicon-o-shield-check',
                'icon_color' => 'warning',
                'key'        => 'dummy_seed_khurram_exec_review',
                'action_url' => '/admin/employee-requests/requests',
                'action_lbl' => 'Review Request',
            ],
        ];

        $inserted = 0;
        $skipped = 0;

        foreach ($notifications as $item) {
            $user = User::query()->where('email', $item['user_email'])->first();

            if (! $user) {
                continue;
            }

            // Check idempotency by key in JSON data
            $alreadyExists = DB::table('notifications')
                ->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $user->getKey())
                ->where('data', 'like', '%'.$item['key'].'%')
                ->exists();

            if ($alreadyExists) {
                $skipped++;

                continue;
            }

            $notification = FilamentNotification::make()
                ->title($item['title'])
                ->body($item['body'])
                ->icon($item['icon'])
                ->iconColor($item['icon_color'])
                ->status($item['status'])
                ->viewData([
                    'idempotency_key' => $item['key'],
                    'dispatched_at'   => now()->toIso8601String(),
                ]);

            if (! empty($item['action_url']) && ! empty($item['action_lbl'])) {
                $notification->actions([
                    Action::make('view')
                        ->label($item['action_lbl'])
                        ->url($item['action_url'])
                        ->button()
                        ->markAsRead(),
                ]);
            }

            $user->notifyNow($notification->toDatabase());
            DatabaseNotificationsSent::dispatch($user);
            $inserted++;
        }

        $this->command?->info("DummyNotificationSeeder: Seeded {$inserted} new notifications (Skipped {$skipped} existing).");
    }
}
