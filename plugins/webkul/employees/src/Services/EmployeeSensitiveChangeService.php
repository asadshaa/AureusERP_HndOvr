<?php

namespace Webkul\Employee\Services;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;

class EmployeeSensitiveChangeService
{
    private const FIELDS = [
        'identification_id',
        'cnic',
        'ntn',
        'passport_id',
        'ssnid',
        'sinid',
        'bank_account_id',
        'salary_grade',
        'base_salary',
        'salary_currency_id',
    ];

    public function __construct(
        protected ApprovalEngine $approvals,
        protected HrHierarchyService $hierarchy,
    ) {}

    /**
     * The Filament action that calls this is already gated on
     * hr_manage_sensitive_employee_data (->visible()), but that only hides
     * a button -- it doesn't stop this method being called directly (a
     * future API route, an Artisan command, or simply a different UI entry
     * point that forgets the check). Enforcing it here too closes that gap,
     * and mirrors EmployeeRequestService::assertRequestIntegrity()'s own
     * "HR-privileged OR within HR hierarchy scope" rule -- proven by an
     * existing test: HrPlatformTest's "enforces company team and manager
     * hierarchy and audits approved sensitive employee changes" already has
     * a plain manager, not an HR-privileged user, submitting a change for
     * their own direct report, and that has to keep working. This also
     * means an employee can submit a change for themselves (self is always
     * within one's own HrHierarchyService::visibleEmployeeIds() scope, the
     * same "self and reports" concept every other HR self-service screen in
     * this app already uses) -- it still can't take effect without a
     * sensitive_data_custodian's separate approval, so this isn't a way to
     * bypass review. A requester with neither the permission nor HR-scope
     * visibility of this employee at all -- some unrelated user, or someone
     * outside this company -- is refused.
     */
    public function submit(Employee $employee, User $requester, array $changes, ?string $reason = null): ApprovalRequest
    {
        if (! $requester->can('hr_manage_sensitive_employee_data')) {
            $this->hierarchy->assertCanManage($requester, $employee);
        }

        $changes = array_intersect_key($changes, array_flip(self::FIELDS));
        if ($changes === []) {
            throw new RuntimeException('No supported sensitive employee changes were supplied.');
        }

        $fieldNames = collect(array_keys($changes))->map(fn ($k) => str_replace('_', ' ', $k))->implode(', ');
        $summary = "Update {$fieldNames}";

        $approval = $this->approvals->submit(
            $employee,
            $requester,
            'employee_sensitive_change',
            isset($changes['base_salary']) ? (string) $changes['base_salary'] : null,
            [
                'company_id'      => (int) $employee->company_id,
                'employee_id'     => (int) $employee->id,
                'department_id'   => $employee->department_id,
                'summary'         => $summary,
                'reason'          => $reason,
                'previous_values' => $employee->only(array_keys($changes)),
                'new_values'      => $changes,
            ],
        );

        $this->notifyApprovers($employee, $requester, $summary, $reason);

        return $approval;
    }

    private function notifyApprovers(Employee $employee, User $requester, string $summary, ?string $reason = null): void
    {
        try {
            $companyId = (int) $employee->company_id;

            $recipients = User::query()
                ->where('default_company_id', $companyId)
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $u): bool => (
                    $u->hasRole([
                        'hr', 'Hr', 'hr_manager', 'Hr_manager', 'hr manager',
                        'hr_ops_manager', 'hr ops manager', 'hr operations manager',
                        'hr_administrator', 'hr administrator', 'human resources', 'human resources manager',
                        'sensitive_data_custodian', 'Sensitive_data_custodian',
                    ])
                    || $u->can('hr_manage_sensitive_employee_data')
                    || $u->can('hr_view_all_records')
                ) && (int) $u->id !== (int) $requester->id);

            if ($recipients->isEmpty()) {
                return;
            }

            $who = $employee->name.((int) $employee->user_id !== (int) $requester->id ? " (submitted by {$requester->name})" : '');
            $reasonNote = $reason ? " | Reason: {$reason}" : '';

            $notification = FilamentNotification::make()
                ->warning()
                ->icon('heroicon-o-shield-check')
                ->title("Sensitive Data Update Request: {$employee->name}")
                ->body("{$who} requested to update sensitive information ({$summary}){$reasonNote}. Review in Approval Requests.")
                ->actions([
                    Action::make('view')
                        ->label('Review in Queue')
                        ->url(url('/admin/settings/approval-requests')),
                ]);

            foreach ($recipients as $recipient) {
                $recipient->notifyNow($notification->toDatabase());
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function applyApproved(ApprovalRequest $request): Employee
    {
        if ($request->request_type !== 'employee_sensitive_change' || $request->status !== 'approved') {
            throw new RuntimeException('Only approved employee sensitive-change requests can be applied.');
        }

        $employee = Employee::query()
            ->whereKey($request->subject_id)
            ->where('company_id', $request->company_id)
            ->firstOrFail();
        $changes = array_intersect_key((array) data_get($request->context, 'new_values', []), array_flip(self::FIELDS));
        $employee->update($changes);

        return $employee->fresh();
    }
}
