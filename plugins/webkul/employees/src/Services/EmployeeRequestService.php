<?php

namespace Webkul\Employee\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Enums\ConversionStatus;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;

class EmployeeRequestService
{
    public function __construct(
        protected ApprovalEngine $approvals,
        protected HrHierarchyService $hierarchy,
    ) {}

    public function submit(EmployeeRequest $request, User $requester): ApprovalRequest
    {
        $request->loadMissing(['employee', 'requestType', 'company']);
        $this->assertRequestIntegrity($request, $requester);

        if ($request->requestType->requires_amount && BigDecimal::of((string) ($request->amount ?? 0))->isLessThanOrEqualTo(0)) {
            throw new RuntimeException('This employee request type requires a positive amount.');
        }
        if ($request->requestType->requires_document && empty($request->attachments)) {
            throw new RuntimeException('This employee request type requires a supporting document.');
        }
        $this->assertClaimTaxConsistency($request);

        $approval = $this->approvals->submit(
            $request,
            $requester,
            $request->requestType->approval_request_type,
            $request->amount !== null ? (string) $request->amount : null,
            [
                'company_id'      => (int) $request->company_id,
                'employee_id'     => (int) $request->employee_id,
                'department_id'   => $request->employee->department_id,
                'team_id'         => $request->employee->team_id,
                'request_type_id' => (int) $request->request_type_id,
                'request_code'    => $request->requestType->code,
                'category'        => $request->requestType->category,
            ],
        );
        $request->update([
            'approval_request_id' => $approval->id,
            'reference'           => $request->reference ?: 'HR-'.$request->company_id.'-'.$request->id,
            'status'              => 'pending_approval',
            'submitted_at'        => now(),
            'rejection_reason'    => null,
            'rejected_at'         => null,
        ]);

        return $approval;
    }

    /**
     * TIME CHANGE REQUEST: Employee -> Time Change Request -> Line Manager
     * Approval -> Approved/Rejected. Creates and immediately submits an
     * EmployeeRequest of the "attendance_time_change" type against the
     * existing ApprovalEngine (routed to the requester's line manager via
     * the same hierarchy_route mechanism every other HR workflow in this
     * app uses) -- no separate approval system. The original attendance
     * values and the requested values are captured in the request's
     * payload at submission time and never mutated afterward, so the
     * original request is preserved regardless of the eventual decision;
     * approval history (who decided what, and when) is preserved via the
     * existing ApprovalRequest/ApprovalDecision chain, untouched here.
     *
     * @param  array{check_in?: ?string, check_out?: ?string}  $requestedChanges
     */
    public function requestAttendanceTimeChange(
        AttendanceRecord $record,
        User $requester,
        array $requestedChanges,
        ?string $reason = null,
    ): EmployeeRequest {
        $record->loadMissing('employee');
        if (! $record->employee) {
            throw new RuntimeException('The attendance record has no linked employee.');
        }
        if ((int) $record->employee->user_id !== (int) $requester->id) {
            $this->hierarchy->assertCanManage($requester, $record->employee);
        }

        // Must also drop null/blank VALUES, not just check the key is one of
        // the two allowed names -- Carbon::parse(null) silently resolves to
        // "now" rather than throwing or staying null, so a caller (e.g. a
        // Filament action that always submits both keys, blank or not) that
        // leaves one field untouched must not have that null smuggled
        // through as if it were a real requested value.
        $requestedChanges = array_filter(
            $requestedChanges,
            fn ($value, $key): bool => in_array($key, ['check_in', 'check_out'], true) && filled($value),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($requestedChanges === []) {
            throw new RuntimeException('A time change request must propose at least a new check-in or check-out time.');
        }

        $requestType = EmployeeRequestType::query()
            ->where('company_id', $record->company_id)
            ->where('code', 'attendance_time_change')
            ->where('is_active', true)
            ->first();
        if (! $requestType) {
            throw new RuntimeException('No active "Attendance Time Change" request type is configured for this company.');
        }

        $original = [
            'check_in'  => $record->check_in?->toDateTimeString(),
            'check_out' => $record->check_out?->toDateTimeString(),
        ];
        $requested = [
            'check_in'  => array_key_exists('check_in', $requestedChanges) ? Carbon::parse($requestedChanges['check_in'])->toDateTimeString() : $original['check_in'],
            'check_out' => array_key_exists('check_out', $requestedChanges) ? Carbon::parse($requestedChanges['check_out'])->toDateTimeString() : $original['check_out'],
        ];

        return DB::transaction(function () use ($record, $requester, $requestType, $original, $requested, $reason): EmployeeRequest {
            $request = EmployeeRequest::query()->create([
                'company_id'      => $record->company_id,
                'employee_id'     => $record->employee_id,
                'request_type_id' => $requestType->id,
                'requested_by'    => $requester->id,
                'title'           => 'Attendance time change for '.$record->attendance_date?->toDateString(),
                'description'     => $reason,
                'status'          => 'draft',
                'payload'         => [
                    'kind'                 => 'attendance_time_change',
                    'attendance_record_id' => $record->id,
                    'original'             => $original,
                    'requested'            => $requested,
                ],
            ]);

            $this->submit($request, $requester);

            return $request->fresh(['approvalRequest', 'requestType']);
        });
    }

    public function approve(EmployeeRequest $request, User $actor, ?string $reason = null): EmployeeRequest
    {
        $approval = $request->approvalRequest ?? throw new RuntimeException('The employee request has not been submitted.');
        $this->approvals->approve($approval, $actor, $reason, ['status' => $request->status], ['status' => 'approved']);

        return $this->synchronize($request);
    }

    public function reject(EmployeeRequest $request, User $actor, string $reason): EmployeeRequest
    {
        $approval = $request->approvalRequest ?? throw new RuntimeException('The employee request has not been submitted.');
        $this->approvals->reject($approval, $actor, $reason, ['status' => $request->status], ['status' => 'rejected']);

        return $this->synchronize($request);
    }

    public function synchronize(EmployeeRequest $request): EmployeeRequest
    {
        $request->load(['approvalRequest.decisions', 'requestType', 'company']);
        $approval = $request->approvalRequest;
        if (! $approval) {
            return $request;
        }
        if ($approval->status === 'rejected') {
            $request->update([
                'status'           => 'rejected',
                'rejected_at'      => $approval->completed_at ?? now(),
                'rejection_reason' => $approval->decisions->last()?->reason,
            ]);

            return $request->fresh();
        }
        if ($approval->status !== 'approved') {
            return $request;
        }

        $request->update(['status' => 'approved', 'approved_at' => $approval->completed_at ?? now()]);
        if ($request->requestType->is_financial) {
            $this->createAccountingDraft($request->fresh(['requestType', 'company']));
        }
        if ($request->requestType->category === 'attendance_correction') {
            $this->applyAttendanceTimeChange($request->fresh(['approvalRequest.decisions']));
        }

        return $request->fresh(['approvalRequest', 'accountingMove.lines']);
    }

    /**
     * Applies an APPROVED attendance_time_change request's requested values
     * to the referenced AttendanceRecord. The request's own payload (the
     * original and requested values captured at submission time) is never
     * modified here -- only the AttendanceRecord is updated, and only on
     * approval; a rejected request never reaches this method at all, so the
     * attendance record is left untouched for that path by construction.
     * Updating check_in/check_out re-triggers AttendanceRecord's own
     * saving() hook, which recalculates worked_hours/late_minutes/
     * early_departure_minutes the same way it does for any other edit --
     * no duplicate calculation logic needed here.
     */
    private function applyAttendanceTimeChange(EmployeeRequest $request): void
    {
        $payload = (array) $request->payload;
        $record = AttendanceRecord::query()->find($payload['attendance_record_id'] ?? null);
        if (! $record || (int) $record->employee_id !== (int) $request->employee_id || (int) $record->company_id !== (int) $request->company_id) {
            report(new RuntimeException("Approved attendance time change request #{$request->id} could not locate a matching attendance record to apply."));

            return;
        }

        $requested = (array) ($payload['requested'] ?? []);
        $updates = array_intersect_key($requested, array_flip(['check_in', 'check_out']));
        if ($updates === []) {
            return;
        }

        $updates['approved_by'] = $request->approvalRequest?->decisions?->last()?->actor_id;
        $record->update($updates);
    }

    public function createAccountingDraft(EmployeeRequest $request): EmployeeRequest
    {
        if ($request->status !== 'approved' || ! $request->requestType->is_financial) {
            throw new RuntimeException('Only approved financial employee requests can be sent to Accounting.');
        }
        if ($request->accounting_move_id) {
            return $request;
        }
        if (BigDecimal::of((string) ($request->amount ?? 0))->isLessThanOrEqualTo(0)) {
            throw new RuntimeException('A positive amount is required for Accounting integration.');
        }
        if ((int) $request->currency_id !== (int) $request->company->currency_id) {
            throw new RuntimeException('Financial employee requests must use the company currency until an approved HR exchange-rate workflow is configured.');
        }

        $type = $request->requestType;
        $journal = Journal::query()->whereKey($type->journal_id)->where('company_id', $request->company_id)->first();
        if (! $journal || $journal->type !== JournalType::GENERAL) {
            throw new RuntimeException('The employee request type requires a company-owned General Journal.');
        }
        $debit = $this->validatedAccount((int) $type->debit_account_id, (int) $request->company_id);
        $credit = $this->validatedAccount((int) $type->credit_account_id, (int) $request->company_id);
        if ($debit->is($credit)) {
            throw new RuntimeException('Employee request debit and credit accounts must be different.');
        }

        DB::transaction(function () use ($request, $journal, $debit, $credit): void {
            $request = EmployeeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->accounting_move_id) {
                return;
            }
            $amount = BigDecimal::of((string) $request->amount)->toScale(4)->__toString();
            $now = now();
            $moveId = DB::table('accounts_account_moves')->insertGetId([
                'journal_id'             => $journal->id,
                'company_id'             => $request->company_id,
                'currency_id'            => $request->currency_id,
                'original_currency_id'   => $request->currency_id,
                'company_currency_id'    => $request->currency_id,
                'date'                   => $request->approved_at?->toDateString() ?? now()->toDateString(),
                'name'                   => 'Employee request '.$request->reference,
                'reference'              => $request->reference,
                'move_type'              => MoveType::ENTRY->value,
                'state'                  => MoveState::DRAFT->value,
                'accounting_source_type' => 'employee_request',
                'accounting_source_id'   => $request->id,
                'review_status'          => 'awaiting_review',
                'conversion_status'      => ConversionStatus::Complete->value,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
            $date = $request->approved_at?->toDateString() ?? now()->toDateString();
            DB::table('accounts_account_move_lines')->insert([
                $this->accountingLine($request, $moveId, $journal->id, $debit->id, $date, $amount, '0', 0),
                $this->accountingLine($request, $moveId, $journal->id, $credit->id, $date, '0', $amount, 1),
            ]);
            $request->update([
                'accounting_move_id'      => $moveId,
                'posted_to_accounting_at' => $now,
            ]);
        });

        return $request->fresh(['accountingMove.lines']);
    }

    /**
     * Section 8 (Claims & Reimbursements): billed_amount / *_deduction /
     * amount ("Net Payment") are only present on claim-shaped requests --
     * anything else leaves billed_amount null and is untouched here, so this
     * never has to know which EmployeeRequestType categories are "claims".
     * The Filament form already live-calculates net payment before a user
     * can submit; this is the server-side backstop against a stale or
     * tampered payload, not the primary UX -- so it throws rather than
     * silently recomputing and overwriting what was submitted.
     */
    private function assertClaimTaxConsistency(EmployeeRequest $request): void
    {
        if ($request->billed_amount === null) {
            return;
        }

        $billed = BigDecimal::of((string) $request->billed_amount);
        $incomeTax = BigDecimal::of((string) ($request->income_tax_deduction ?? 0));
        $salesTax = BigDecimal::of((string) ($request->sales_tax_deduction ?? 0));

        if ($billed->isNegative()) {
            throw new RuntimeException('The billed amount cannot be negative.');
        }
        if ($incomeTax->isNegative() || $salesTax->isNegative()) {
            throw new RuntimeException('Tax deductions cannot be negative.');
        }
        if ($request->tax_deduction_rate !== null
            && (BigDecimal::of((string) $request->tax_deduction_rate)->isNegative()
                || BigDecimal::of((string) $request->tax_deduction_rate)->isGreaterThan('100'))) {
            throw new RuntimeException('Tax deduction rate must be between 0 and 100.');
        }

        $totalDeductions = $incomeTax->plus($salesTax);
        if ($totalDeductions->isGreaterThan($billed)) {
            throw new RuntimeException('Tax deductions cannot exceed the billed amount.');
        }

        $expectedNet = $billed->minus($totalDeductions);
        $actualNet = BigDecimal::of((string) ($request->amount ?? 0));
        if (! $expectedNet->isEqualTo($actualNet)) {
            throw new RuntimeException('Net payment must equal the billed amount minus tax deductions.');
        }
    }

    private function assertRequestIntegrity(EmployeeRequest $request, User $requester): void
    {
        if (! in_array($request->status, ['draft', 'rejected'], true)) {
            throw new RuntimeException('Only draft or rejected employee requests can be submitted.');
        }
        if ((int) $request->employee?->company_id !== (int) $request->company_id
            || (int) $request->requestType?->company_id !== (int) $request->company_id
            || ! $request->requestType?->is_active) {
            throw new RuntimeException('Employee request, employee, and request type must belong to the same company.');
        }
        if ((int) $request->employee->user_id !== (int) $requester->id) {
            $this->hierarchy->assertCanManage($requester, $request->employee);
        }
    }

    private function validatedAccount(int $accountId, int $companyId): Account
    {
        $account = Account::query()
            ->postable()
            ->whereKey($accountId)
            ->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->first();
        if (! $account) {
            throw new RuntimeException('Employee request accounting accounts must be active, postable, and owned by the company.');
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function accountingLine(
        EmployeeRequest $request,
        int $moveId,
        int $journalId,
        int $accountId,
        string $date,
        string $debit,
        string $credit,
        int $sort,
    ): array {
        $signed = BigDecimal::of($debit)->minus($credit)->__toString();

        return [
            'move_id'                => $moveId,
            'journal_id'             => $journalId,
            'company_id'             => $request->company_id,
            'company_currency_id'    => $request->currency_id,
            'currency_id'            => $request->currency_id,
            'original_currency_id'   => $request->currency_id,
            'account_id'             => $accountId,
            'date'                   => $date,
            'debit'                  => $debit,
            'credit'                 => $credit,
            'balance'                => $signed,
            'original_debit'         => $debit,
            'original_credit'        => $credit,
            'original_signed_amount' => $signed,
            'company_debit'          => $debit,
            'company_credit'         => $credit,
            'company_signed_amount'  => $signed,
            'amount_currency'        => $signed,
            'conversion_status'      => ConversionStatus::Complete->value,
            'parent_state'           => MoveState::DRAFT->value,
            'name'                   => $request->title,
            'reference'              => $request->reference,
            'sort'                   => $sort,
            'created_at'             => now(),
            'updated_at'             => now(),
        ];
    }
}
