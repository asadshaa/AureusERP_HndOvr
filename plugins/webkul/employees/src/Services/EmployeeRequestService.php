<?php

namespace Webkul\Employee\Services;

use Brick\Math\BigDecimal;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\Attendance\ApprovedLeaveLookup;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\HierarchicalNotificationService;
use Webkul\TimeOff\Enums\State;

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

        $this->notifyApprovers($request, $requester, $approval);

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
                    'kind'                      => 'attendance_time_change',
                    'attendance_record_id'      => $record->id,
                    'attendance_date'           => $record->attendance_date?->toDateString(),
                    'day_of_week'               => $record->attendance_date?->format('l'),
                    'formatted_date'            => $record->attendance_date?->format('d M Y'),
                    'original'                  => $original,
                    'requested'                 => $requested,
                ],
            ]);

            $this->submit($request, $requester);

            return $request->fresh(['approvalRequest', 'requestType']);
        });
    }

    /**
     * MISSED DAY: the employee has no attendance record at all for a past
     * day (phone failure, geofence failure they cannot fix themselves).
     * Same request type and line-manager approval as a time change; the
     * record is created only when the request is APPROVED. Nothing is
     * created on submission or rejection.
     *
     * @param  array{check_in?: ?string, check_out?: ?string}  $times
     */
    public function requestMissingAttendance(
        Employee $employee,
        User $requester,
        string $attendanceDate,
        array $times,
        ?string $reason = null,
    ): EmployeeRequest {
        if ((int) $employee->user_id !== (int) $requester->id) {
            $this->hierarchy->assertCanManage($requester, $employee);
        }

        $date = Carbon::parse($attendanceDate)->startOfDay();
        if ($date->isFuture() || $date->lt(now()->subDays(30)->startOfDay())) {
            throw new RuntimeException('A missed day can only be requested for the last 30 days.');
        }

        $exists = AttendanceRecord::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $date->toDateString())
            ->exists();
        if ($exists) {
            throw new RuntimeException('A record already exists for that day. Request a time change on it instead.');
        }

        if (ApprovedLeaveLookup::available()) {
            $onLeave = ApprovedLeaveLookup::approvedForEmployee($employee->id, $date->toDateString(), $date->toDateString())->exists();
            if ($onLeave) {
                throw new RuntimeException('A missed attendance day cannot be requested on a day the employee has an approved leave.');
            }
        }

        $times = array_filter(
            array_intersect_key($times, array_flip(['check_in', 'check_out'])),
            fn ($value): bool => filled($value),
        );
        if (! array_key_exists('check_in', $times)) {
            throw new RuntimeException('A missed-day request needs at least a check-in time.');
        }

        $requested = [
            'check_in'  => Carbon::parse($times['check_in'])->toDateTimeString(),
            'check_out' => isset($times['check_out']) ? Carbon::parse($times['check_out'])->toDateTimeString() : null,
        ];
        if ($requested['check_out'] !== null && $requested['check_out'] <= $requested['check_in']) {
            throw new RuntimeException('Check-out must be after check-in.');
        }

        $requestType = EmployeeRequestType::query()
            ->where('company_id', $employee->company_id)
            ->where('code', 'attendance_time_change')
            ->where('is_active', true)
            ->first();
        if (! $requestType) {
            throw new RuntimeException('No active "Attendance Time Change" request type is configured for this company.');
        }

        return DB::transaction(function () use ($employee, $requester, $requestType, $date, $requested, $reason): EmployeeRequest {
            $request = EmployeeRequest::query()->create([
                'company_id'      => $employee->company_id,
                'employee_id'     => $employee->id,
                'request_type_id' => $requestType->id,
                'requested_by'    => $requester->id,
                'title'           => 'Missed attendance for '.$date->toDateString(),
                'description'     => $reason,
                'status'          => 'draft',
                'payload'         => [
                    'kind'            => 'attendance_missing_day',
                    'attendance_date' => $date->toDateString(),
                    'day_of_week'     => $date->format('l'),
                    'formatted_date'  => $date->format('d M Y'),
                    'original'        => ['check_in' => null, 'check_out' => null],
                    'requested'       => $requested,
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
            $reason = $approval->decisions->last()?->reason;
            $request->update([
                'status'           => 'rejected',
                'rejected_at'      => $approval->completed_at ?? now(),
                'rejection_reason' => $reason,
            ]);

            $this->notifyDecision($request, 'rejected', $reason);

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

        $this->notifyDecision($request, 'approved');

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

        if (($payload['kind'] ?? null) === 'attendance_missing_day') {
            $this->applyMissingAttendanceDay($request, $payload);

            return;
        }

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

    /**
     * Creates the attendance record for an APPROVED missed-day request. If a
     * record appeared in the meantime, the requested times are applied to it
     * like a time change instead of creating a duplicate.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyMissingAttendanceDay(EmployeeRequest $request, array $payload): void
    {
        $date = $payload['attendance_date'] ?? null;
        $requested = (array) ($payload['requested'] ?? []);
        if (! $date || empty($requested['check_in'])) {
            report(new RuntimeException("Approved missed-day request #{$request->id} has no usable date or check-in."));

            return;
        }

        $record = AttendanceRecord::query()
            ->where('company_id', $request->company_id)
            ->where('employee_id', $request->employee_id)
            ->where('attendance_date', $date)
            ->first() ?? new AttendanceRecord([
                'company_id'      => $request->company_id,
                'employee_id'     => $request->employee_id,
                'attendance_date' => $date,
                'status'          => 'present',
                'source'          => 'manual',
            ]);

        $record->fill(array_filter([
            'check_in'    => $requested['check_in'],
            'check_out'   => $requested['check_out'] ?? null,
            'approved_by' => $request->approvalRequest?->decisions?->last()?->actor_id,
        ], fn ($value): bool => $value !== null));
        $record->save();
    }

    /**
     * Posts an approved financial employee request as a real vendor Bill
     * (move_type = IN_INVOICE), not a generic MoveType::ENTRY journal entry.
     *
     * This reuses the exact posting mechanism BillResource's own "Confirm"
     * action and DriveInvoicePostingService (Google Drive invoice ingestion)
     * both use -- AccountFacade::confirmMove(), i.e.
     * Webkul\Account\AccountManager::confirmMove(). Only ONE product-type
     * MoveLine (the expense line) is built here; confirmMove() generates the
     * balancing Accounts Payable line itself (via syncDynamicLines() ->
     * syncPaymentTermLines()), resolving the payable account from the
     * employee's Partner (property_account_payable_id, if ever set) or,
     * failing that, the company's own Liability Payable account -- exactly
     * like a real vendor Bill, and exactly like Drive-ingested bills. This
     * class never hand-builds a second, manually-balanced line.
     *
     * Why this matters (see class docs on Bill/JournalEntry -- both are
     * plain `class X extends Move {}` with zero scope of their own): every
     * Bill-scoped view (BillResource's list, its "Register Payment" action,
     * etc.) is scoped purely by `move_type` + `company_id` in the resource's
     * getEloquentQuery(), not by which Eloquent class was used to create the
     * row. A MoveType::ENTRY draft is invisible to all of that and has no
     * Register Payment action; a MoveType::IN_INVOICE move posted this way
     * is a real Bill in every one of those views, the moment it exists.
     *
     * confirmMove() both validates the balance and moves the record straight
     * to state = POSTED -- there is no separate "Finance clicks Post" pause
     * (this matches how a Drive-ingested vendor bill also auto-posts on
     * approval today; see DriveInvoicePostingService). Posting is NOT
     * paying: no Payment is created, scheduled, or implied by this method --
     * registering a payment against the posted Bill remains a fully
     * separate, manual action a Finance user takes afterward via the real
     * "Register Payment" button, exactly as for any other vendor Bill.
     */
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
        // A real vendor Bill (move_type = IN_INVOICE) must post through a
        // PURCHASE journal -- see Move::getValidJournalTypes() /
        // Move::computeJournalId(), which would otherwise silently swap in
        // a different journal for this company at save time. Checked
        // explicitly up front so a misconfigured request type fails loudly
        // here instead of posting against a journal nobody chose.
        $journal = Journal::query()->whereKey($type->journal_id)->where('company_id', $request->company_id)->first();
        if (! $journal || $journal->type !== JournalType::PURCHASE) {
            throw new RuntimeException('The employee request type requires a company-owned Purchase Journal.');
        }
        // Only the debit (expense) account is used to build a line here --
        // credit_account_id is intentionally not read: the balancing
        // Accounts Payable line is generated by confirmMove() itself (see
        // method doc above), not hand-picked from this configuration.
        $debit = $this->validatedAccount((int) $type->debit_account_id, (int) $request->company_id);

        DB::transaction(function () use ($request, $journal, $debit): void {
            $request = EmployeeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->accounting_move_id) {
                return;
            }

            $date = $request->approved_at?->toDateString() ?? now()->toDateString();

            $move = new Move;
            $move->company_id = $request->company_id;
            // The employee's own Partner record (auto-created for every
            // Employee, sub_type "employee") is the vendor here -- required
            // both by confirmMove()'s own guard (a purchase document with no
            // partner is refused) and so Accounting's Payment screen has
            // someone to register a payment against once posted.
            $move->partner_id = $request->employee->partner_id;
            $move->journal_id = $journal->id;
            $move->currency_id = $request->currency_id;
            $move->move_type = MoveType::IN_INVOICE;
            $move->state = MoveState::DRAFT;
            // invoice_date is required by AccountManager::isConfirmAllowedForMove()
            // for any purchase document -- confirmMove() throws without it.
            $move->invoice_date = $date;
            $move->date = $date;
            $move->reference = $request->reference;
            $move->accounting_source_type = 'employee_request';
            $move->accounting_source_id = $request->id;
            $move->review_status = 'awaiting_review';
            // Without this, the move's row-level permission scope
            // (HasPermissionScope::scopeApplyPermissionScope, which filters
            // "whereIn('creator_id', $authorizedUserIds)") can never match --
            // a null/approver-attributed creator_id would make the posted
            // Bill invisible to the requester in Accounting.
            $move->creator_id = $request->requested_by;
            $move->save();

            $line = new MoveLine;
            $line->move_id = $move->id;
            $line->account_id = $debit->id;
            $line->display_type = DisplayType::PRODUCT;
            $line->quantity = 1;
            $line->price_unit = (string) $request->amount;
            $line->discount = 0;
            $line->name = $this->expenseLineDescription($request);
            $line->save();

            // The real posting mechanism -- confirmMove() generates the
            // balancing payable line, computes totals, and refuses to post
            // (reverting to DRAFT) if the result isn't balanced. No
            // hand-rolled debit/credit balancing here.
            AccountFacade::confirmMove($move->fresh('lines'));

            $request->update([
                'accounting_move_id'      => $move->id,
                'posted_to_accounting_at' => now(),
            ]);

            DB::afterCommit(function () use ($request, $move): void {
                app(HierarchicalNotificationService::class)->notifyAccountingReady(
                    $request,
                    (int) $request->company_id,
                    $request->reference ?: ('BILL-'.$move->id),
                    $request->title ?? 'Reimbursement',
                    (string) $request->amount,
                );
            });
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

    /**
     * Descriptive label for the single expense line posted by
     * createAccountingDraft() -- the request's title, plus its configured
     * "nature of expense" when one was recorded, e.g. "Team lunch (Team
     * Event)".
     */
    private function expenseLineDescription(EmployeeRequest $request): string
    {
        return $request->nature_of_expense
            ? "{$request->title} ({$request->nature_of_expense})"
            : $request->title;
    }

    private function notifyApprovers(EmployeeRequest $request, User $requester, ?ApprovalRequest $approval = null): void
    {
        try {
            $approval = $approval ?? $request->approvalRequest;

            if ($approval) {
                app(HierarchicalNotificationService::class)->notifyRequestSubmitted(
                    $approval,
                    $request,
                    $requester,
                );

                return;
            }

            $employee = $request->employee;
            if (! $employee) {
                return;
            }

            $parentId = $employee->parent_id;
            $manager = $parentId ? Employee::query()->with('user')->find($parentId)?->user : null;

            $hasManager = $manager && $manager->is_active && (int) $manager->id !== (int) $requester->id;

            if ($hasManager) {
                $recipients = collect([$manager]);
                $routingNote = '[Routed to Line Manager]';
            } else {
                $recipients = $this->approvals->resolveHrApproversForCompany((int) $request->company_id, (int) $requester->id);
                $routingNote = '[Forwarded to HR: no line manager assigned]';
            }

            if ($recipients->isEmpty()) {
                return;
            }

            $who = $employee->name.((int) $employee->user_id !== (int) $requester->id ? " (by {$requester->name})" : '');
            $what = $request->requestType?->name ?? 'Employee Request';
            $title = ($what === 'Attendance Time Change')
                ? "{$employee->name} requested attendance time change"
                : "{$what} Request: {$employee->name}";
            $body = "{$who} submitted {$what} {$routingNote}";

            $notification = FilamentNotification::make()
                ->warning()
                ->title($title)
                ->body($body);

            foreach ($recipients as $recipient) {
                $idempotencyKey = "employee_request_submit_{$request->id}_{$recipient->id}";
                app(HierarchicalNotificationService::class)->sendIdempotentNotification($recipient, $notification, $idempotencyKey, [
                    'event'               => 'employee_request_submit',
                    'employee_request_id' => $request->id,
                    'company_id'          => $request->company_id,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifyDecision(EmployeeRequest $request, string $decision, ?string $reason = null): void
    {
        try {
            $request->loadMissing(['employee.user', 'employee.parent', 'requester', 'requestType', 'approvalRequest.decisions.actor.employee']);
            $recipients = collect([$request->requester, $request->employee?->user])
                ->filter(fn (?User $u): bool => $u && $u->is_active)
                ->unique('id');

            if ($recipients->isEmpty()) {
                return;
            }

            $what = $request->requestType?->name ?? 'Employee Request';
            $isApproved = $decision === 'approved';

            // Resolve who approved/rejected the request
            $approval = $request->approvalRequest;
            $lastDecision = $approval?->decisions?->last();
            $actor = $lastDecision?->actor;
            $actorEmployee = $actor?->employee ?? ($lastDecision?->actor_id ? Employee::where('user_id', $lastDecision->actor_id)->first() : null);

            $isLineManager = $request->employee && $actorEmployee && (int) $request->employee->parent_id === (int) $actorEmployee->id;
            if ($isLineManager) {
                $approverRole = 'Line Manager';
            } elseif ($actor && ($actor->hasRole(['Admin', 'Super Admin', 'hr', 'hr_manager', 'hr manager', 'hr_ops_manager', 'hr ops manager', 'hr_administrator', 'human resources', 'human resources manager']) || $actor->can('hr_manage_attendance') || $actor->can('hr_view_all_records'))) {
                $approverRole = 'HR';
            } else {
                $approverRole = 'Line Manager';
            }

            $actorName = $actor?->name;
            $approverLabel = $actorName ? "{$approverRole} ({$actorName})" : $approverRole;

            $payload = (array) $request->payload;
            $kind = $payload['kind'] ?? '';
            $isTimeChange = $kind === 'attendance_time_change' || ($request->requestType?->category === 'attendance_correction') || str_contains(strtolower($what), 'time change');
            $isMissedDay = $kind === 'attendance_missing_day';
            $dateStr = $payload['formatted_date'] ?? $payload['attendance_date'] ?? '';

            if ($isTimeChange) {
                $dateInfo = $dateStr ? " for {$dateStr}" : '';
                $title = $isApproved
                    ? "{$approverRole} approved your time change request"
                    : "{$approverRole} rejected your time change request";

                $body = $isApproved
                    ? "Your attendance time change request{$dateInfo} has been approved by {$approverLabel}."
                    : "Your attendance time change request{$dateInfo} was rejected by {$approverLabel}.".($reason ? " Reason: {$reason}" : '');
            } elseif ($isMissedDay) {
                $dateInfo = $dateStr ? " for {$dateStr}" : '';
                $title = $isApproved
                    ? "{$approverRole} approved your missed attendance request"
                    : "{$approverRole} rejected your missed attendance request";

                $body = $isApproved
                    ? "Your missed attendance request{$dateInfo} has been approved by {$approverLabel}."
                    : "Your missed attendance request{$dateInfo} was rejected by {$approverLabel}.".($reason ? " Reason: {$reason}" : '');
            } else {
                $title = $isApproved
                    ? "{$approverRole} approved your {$what} request"
                    : "{$approverRole} rejected your {$what} request";

                $body = $isApproved
                    ? "Your {$what} ('{$request->title}') has been approved by {$approverLabel}."
                    : "Your {$what} ('{$request->title}') was rejected by {$approverLabel}.".($reason ? " Reason: {$reason}" : '');
            }

            $notification = FilamentNotification::make()
                ->color($isApproved ? 'success' : 'danger')
                ->title($title)
                ->body($body);

            $approvalId = $request->approval_request_id ?: $request->id;
            foreach ($recipients as $recipient) {
                $idempotencyKey = "approval_decision_{$approvalId}_{$decision}_{$recipient->id}";
                app(HierarchicalNotificationService::class)->sendIdempotentNotification($recipient, $notification, $idempotencyKey, [
                    'event'               => 'employee_request_decision',
                    'employee_request_id' => $request->id,
                    'decision'            => $decision,
                    'company_id'          => $request->company_id,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
