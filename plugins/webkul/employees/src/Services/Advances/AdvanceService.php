<?php

namespace Webkul\Employee\Services\Advances;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\JournalType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Services\PeriodLockService;
use Webkul\Employee\Models\AdvanceEntry;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\AdvanceKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Business advances after approval.
 *
 * An approved Business Advance posts a vendor bill to the employee (the
 * existing EmployeeRequestService::createAccountingDraft()) that debits the
 * "Employee Advances" asset account. This service then tracks what happens to
 * that money, in the audit table employees_advance_entries:
 *
 *   settlement     the employee reports what was really spent (an Advance
 *                  Settlement request, approved like a claim). Approval posts
 *                  Dr Expense / Cr Employee Advances for that amount.
 *   cash_returned  Finance records money the employee handed back. Posts
 *                  Dr Bank or Cash / Cr Employee Advances.
 *
 * Outstanding = advance amount - settled - returned, and never below zero.
 * Spending more than the advance is not settled here: the employee settles
 * what the advance covers and files a normal claim for the extra, so no
 * payable is created by this service. Every posting is a balanced two-line
 * journal entry with the employee as partner, traceable to its source
 * through accounting_source_type / accounting_source_id, and refused in a
 * closed accounting period.
 */
class AdvanceService
{
    /** Settlement requests that still block starting another one for the same advance. */
    private const OpenSettlementStatuses = ['draft', 'pending_approval', 'returned'];

    public function __construct(
        protected HrHierarchyService $hierarchy,
        protected PeriodLockService $periodLocks,
    ) {}

    /**
     * Adds the settled / returned sums to an advances query, so a list can show
     * the outstanding amount without a query per row.
     */
    public function withBalances(Builder $query): Builder
    {
        return $query
            ->withSum(['advanceEntries as advance_settled_sum' => fn (Builder $entries) => $entries->where('type', AdvanceKinds::EntrySettlement)], 'amount')
            ->withSum(['advanceEntries as advance_returned_sum' => fn (Builder $entries) => $entries->where('type', AdvanceKinds::EntryCashReturned)], 'amount');
    }

    /**
     * @return array{advanced: string, settled: string, returned: string, outstanding: string, is_posted: bool, is_closed: bool, settle_by: ?string, is_overdue: bool, pending_settlement_id: ?int}
     */
    public function summary(EmployeeRequest $advance): array
    {
        $advance->loadMissing('requestType');

        $settled = $advance->getAttribute('advance_settled_sum') ?? $advance->advanceEntries()->where('type', AdvanceKinds::EntrySettlement)->sum('amount');
        $returned = $advance->getAttribute('advance_returned_sum') ?? $advance->advanceEntries()->where('type', AdvanceKinds::EntryCashReturned)->sum('amount');

        $advanced = BigDecimal::of((string) ($advance->amount ?? 0));
        $outstanding = $advanced->minus((string) $settled)->minus((string) $returned);
        $outstanding = $outstanding->isNegative() ? BigDecimal::zero() : $outstanding;

        $isPosted = $advance->status === 'approved' && $advance->accounting_move_id !== null;
        $settleBy = data_get($advance->payload, 'settle_by');

        return [
            'advanced'              => (string) $advanced->toScale(2),
            'settled'               => (string) BigDecimal::of((string) $settled)->toScale(2),
            'returned'              => (string) BigDecimal::of((string) $returned)->toScale(2),
            'outstanding'           => (string) $outstanding->toScale(2),
            'is_posted'             => $isPosted,
            'is_closed'             => $isPosted && $outstanding->isZero(),
            'settle_by'             => $settleBy,
            'is_overdue'            => $isPosted && $outstanding->isPositive() && $settleBy !== null && $settleBy < AdvanceKinds::today(),
            'pending_settlement_id' => $this->openSettlement($advance)?->id,
        ];
    }

    /** @return Collection<int, AdvanceEntry> */
    public function entries(EmployeeRequest $advance): Collection
    {
        return $advance->advanceEntries()->with(['sourceRequest:id,reference,title', 'accountingMove:id,name,state'])->orderBy('entry_date')->orderBy('id')->get();
    }

    /**
     * The employee's approved, paid-out advances that should already have been
     * settled and still have money outstanding.
     *
     * @return Collection<int, EmployeeRequest>
     */
    public function overdueAdvances(int $employeeId, ?int $exceptRequestId = null): Collection
    {
        $advances = $this->withBalances(EmployeeRequest::query())
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereNotNull('accounting_move_id')
            ->whereHas('requestType', fn (Builder $types) => $types->where('category', AdvanceKinds::AdvanceCategory))
            ->when($exceptRequestId, fn (Builder $query) => $query->whereKeyNot($exceptRequestId))
            ->with('requestType')
            ->get();

        return $advances->filter(fn (EmployeeRequest $advance): bool => $this->summary($advance)['is_overdue'])->values();
    }

    public function canSettle(User $user, EmployeeRequest $advance): bool
    {
        $advance->loadMissing(['requestType', 'employee']);

        if (! AdvanceKinds::isAdvance($advance->requestType) || ! $this->isOwnerOrManager($user, $advance)) {
            return false;
        }

        $summary = $this->summary($advance);

        return $summary['is_posted'] && (float) $summary['outstanding'] > 0 && $summary['pending_settlement_id'] === null;
    }

    public function canRecordReturn(User $user, EmployeeRequest $advance): bool
    {
        $advance->loadMissing('requestType');

        if (! AdvanceKinds::isAdvance($advance->requestType)
            || ! $user->can(HrPermissions::ProcessFinancialRequests)
            || ! CurrentCompany::canAccess((int) $advance->company_id, $user)) {
            return false;
        }

        $summary = $this->summary($advance);

        return $summary['is_posted'] && (float) $summary['outstanding'] > 0;
    }

    /**
     * The employee reports what was spent. Creates an Advance Settlement request
     * linked to the advance and sends it for approval.
     *
     * @param  array<int, string>  $receipts  stored receipt files
     *
     * @throws \RuntimeException
     */
    public function startSettlement(User $user, EmployeeRequest $advance, string $amountSpent, array $receipts, string $note, ?string $spentOn = null): EmployeeRequest
    {
        $advance->loadMissing(['requestType', 'employee', 'company']);

        if (! AdvanceKinds::isAdvance($advance->requestType) || ! $this->isOwnerOrManager($user, $advance)) {
            throw new \RuntimeException('You can only settle your own business advance.');
        }

        $summary = $this->summary($advance);

        if (! $summary['is_posted']) {
            throw new \RuntimeException('This advance has not been approved and paid out yet, so there is nothing to settle.');
        }
        if ($summary['pending_settlement_id'] !== null) {
            throw new \RuntimeException('A settlement of this advance is already waiting for approval.');
        }

        $spent = BigDecimal::of($amountSpent);
        $outstanding = BigDecimal::of($summary['outstanding']);

        if ($spent->isLessThanOrEqualTo(0)) {
            throw new \RuntimeException('Enter the amount you spent, more than zero.');
        }
        if ($outstanding->isZero()) {
            throw new \RuntimeException('This advance is already fully settled.');
        }
        if ($spent->isGreaterThan($outstanding)) {
            $extra = $spent->minus($outstanding);
            throw new \RuntimeException('You spent more than the advance left ('.$this->money($outstanding).'). Settle '.$this->money($outstanding).' here and file a separate claim for the extra '.$this->money($extra).'.');
        }
        if ($receipts === []) {
            throw new \RuntimeException('Attach the receipts for what you spent.');
        }
        if (blank($note)) {
            throw new \RuntimeException('Say what the money was spent on.');
        }

        $type = EmployeeRequestType::query()
            ->where('company_id', $advance->company_id)
            ->where('category', AdvanceKinds::SettlementCategory)
            ->where('is_active', true)
            ->first();

        if (! $type) {
            throw new \RuntimeException('Advance settlement is not set up for this company yet. Please ask HR.');
        }

        return DB::transaction(function () use ($user, $advance, $type, $spent, $receipts, $note, $spentOn): EmployeeRequest {
            $settlement = EmployeeRequest::query()->create([
                'company_id'         => $advance->company_id,
                'employee_id'        => $advance->employee_id,
                'request_type_id'    => $type->id,
                'advance_request_id' => $advance->id,
                'requested_by'       => $user->id,
                'currency_id'        => $advance->currency_id,
                'title'              => 'Settlement of '.($advance->reference ?: 'advance #'.$advance->id),
                'description'        => trim($note),
                'amount'             => (string) $spent->toScale(4),
                'attachments'        => array_values($receipts),
                'payload'            => ['kind' => AdvanceKinds::SettlementCategory, 'advance_id' => $advance->id, 'spent_on' => $spentOn],
                'status'             => 'draft',
            ]);

            app(EmployeeRequestService::class)->submit($settlement, $user);

            return $settlement->fresh();
        });
    }

    /**
     * Final approval of a settlement: moves the settled amount from Employee
     * Advances to the expense account. Idempotent (one settlement is applied once).
     *
     * @throws \RuntimeException
     */
    public function applyApprovedSettlement(EmployeeRequest $settlement): ?AdvanceEntry
    {
        $settlement->loadMissing(['requestType', 'advance.requestType', 'employee', 'company']);
        $advance = $settlement->advance;

        if (! AdvanceKinds::isSettlement($settlement->requestType) || ! $advance || $settlement->status !== 'approved') {
            return null;
        }

        $existing = AdvanceEntry::query()->where('type', AdvanceKinds::EntrySettlement)->where('source_request_id', $settlement->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($settlement, $advance): AdvanceEntry {
            // Serialise settlements and returns of one advance, then read what is left.
            $advance = EmployeeRequest::query()->whereKey($advance->id)->lockForUpdate()->firstOrFail();
            $advance->load('requestType');

            $outstanding = BigDecimal::of($this->summary($advance)['outstanding']);
            $amount = BigDecimal::of((string) $settlement->amount);

            if ($amount->isLessThanOrEqualTo(0) || $amount->isGreaterThan($outstanding)) {
                throw new \RuntimeException('This settlement is more than what is left of the advance ('.$this->money($outstanding).'). Reject it and ask for a settlement of the amount left.');
            }

            $date = $settlement->approved_at?->toDateString() ?? AdvanceKinds::today();
            $type = $settlement->requestType;
            $advancesAccount = $this->postableAccount((int) $advance->requestType->debit_account_id, (int) $settlement->company_id, 'Employee Advances');
            $expenseAccount = $this->postableAccount((int) $type->debit_account_id, (int) $settlement->company_id, 'expense');
            $journal = Journal::query()->whereKey($type->journal_id)->where('company_id', $settlement->company_id)->first();

            if (! $journal || $journal->type !== JournalType::GENERAL) {
                throw new \RuntimeException('Advance settlement has no general journal of this company. Ask your accountant to set one on the Advance Settlement request type.');
            }

            $moveId = $this->postEntry(
                companyId: (int) $settlement->company_id,
                journalId: (int) $journal->id,
                date: $date,
                name: 'ADV-SET/'.$settlement->id,
                reference: (string) ($settlement->reference ?: 'HR-'.$settlement->company_id.'-'.$settlement->id),
                sourceType: AdvanceKinds::SettlementSourceType,
                sourceId: (int) $settlement->id,
                cashFlowCategory: AdvanceKinds::NonCash,
                employee: $settlement->employee,
                debitAccountId: (int) $expenseAccount->id,
                creditAccountId: (int) $advancesAccount->id,
                amount: (string) $amount->toScale(4),
                description: 'Advance settlement: '.($settlement->title ?? ''),
                userId: $settlement->requested_by,
            );

            return AdvanceEntry::query()->create([
                'company_id'         => $settlement->company_id,
                'advance_request_id' => $advance->id,
                'source_request_id'  => $settlement->id,
                'type'               => AdvanceKinds::EntrySettlement,
                'amount'             => (string) $amount->toScale(4),
                'entry_date'         => $date,
                'accounting_move_id' => $moveId,
                'reference'          => $settlement->reference,
                'note'               => $settlement->description,
                'created_by'         => $settlement->requested_by,
            ]);
        });
    }

    /**
     * Bank or cash journals of the advance's company, id => name, for the
     * "cash handed back" form.
     *
     * @return array<int, string>
     */
    public function returnJournalOptions(EmployeeRequest $advance): array
    {
        return Journal::query()
            ->where('company_id', $advance->company_id)
            ->whereIn('type', [JournalType::BANK, JournalType::CASH])
            ->whereNotNull('default_account_id')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Finance records money the employee handed back. Posts Dr Bank or Cash /
     * Cr Employee Advances and reduces what is outstanding.
     *
     * @throws \RuntimeException
     */
    public function recordReturnedCash(User $user, EmployeeRequest $advance, string $amount, int $journalId, string $date, ?string $reference = null, ?string $note = null): AdvanceEntry
    {
        $advance->loadMissing(['requestType', 'employee', 'company']);

        if (! $this->canRecordReturn($user, $advance)) {
            throw new \RuntimeException('You cannot record returned cash for this advance.');
        }

        $returned = BigDecimal::of($amount);
        if ($returned->isLessThanOrEqualTo(0)) {
            throw new \RuntimeException('Enter the amount handed back, more than zero.');
        }

        $journal = Journal::query()
            ->whereKey($journalId)
            ->where('company_id', $advance->company_id)
            ->whereIn('type', [JournalType::BANK, JournalType::CASH])
            ->first();

        if (! $journal || ! $journal->default_account_id) {
            throw new \RuntimeException('Choose a bank or cash journal of this company that has its account set.');
        }

        if ($date > AdvanceKinds::today()) {
            throw new \RuntimeException('The date the cash was handed back cannot be in the future.');
        }

        return DB::transaction(function () use ($user, $advance, $returned, $journal, $date, $reference, $note): AdvanceEntry {
            $advance = EmployeeRequest::query()->whereKey($advance->id)->lockForUpdate()->firstOrFail();
            $advance->load(['requestType', 'employee']);

            $outstanding = BigDecimal::of($this->summary($advance)['outstanding']);
            if ($returned->isGreaterThan($outstanding)) {
                throw new \RuntimeException('The amount handed back is more than what is left of the advance ('.$this->money($outstanding).').');
            }

            $advancesAccount = $this->postableAccount((int) $advance->requestType->debit_account_id, (int) $advance->company_id, 'Employee Advances');
            $cashAccount = $this->postableAccount((int) $journal->default_account_id, (int) $advance->company_id, 'bank or cash');

            $entry = AdvanceEntry::query()->create([
                'company_id'         => $advance->company_id,
                'advance_request_id' => $advance->id,
                'type'               => AdvanceKinds::EntryCashReturned,
                'amount'             => (string) $returned->toScale(4),
                'entry_date'         => $date,
                'reference'          => $reference,
                'note'               => $note,
                'created_by'         => $user->id,
            ]);

            $moveId = $this->postEntry(
                companyId: (int) $advance->company_id,
                journalId: (int) $journal->id,
                date: $date,
                name: 'ADV-RET/'.$entry->id,
                reference: $reference ?: (string) ($advance->reference ?: 'HR-'.$advance->company_id.'-'.$advance->id),
                sourceType: AdvanceKinds::CashReturnSourceType,
                sourceId: (int) $entry->id,
                cashFlowCategory: AdvanceKinds::OperatingReceipts,
                employee: $advance->employee,
                debitAccountId: (int) $cashAccount->id,
                creditAccountId: (int) $advancesAccount->id,
                amount: (string) $returned->toScale(4),
                description: 'Advance cash returned: '.($advance->title ?? ''),
                userId: $user->id,
            );

            $entry->update(['accounting_move_id' => $moveId]);

            return $entry->fresh();
        });
    }

    private function money(BigDecimal $value): string
    {
        return number_format((float) (string) $value->toScale(2), 2);
    }

    private function isOwnerOrManager(User $user, EmployeeRequest $advance): bool
    {
        $employee = $advance->employee;

        if (! $employee || ! CurrentCompany::canAccess((int) $advance->company_id, $user)) {
            return false;
        }

        if ($employee->user_id !== null && (int) $employee->user_id === (int) $user->id) {
            return true;
        }

        return $user->can(HrPermissions::ManageEmployeeRequests) && $this->hierarchy->canManage($user, $employee);
    }

    private function openSettlement(EmployeeRequest $advance): ?EmployeeRequest
    {
        return EmployeeRequest::query()
            ->where('advance_request_id', $advance->id)
            ->whereIn('status', self::OpenSettlementStatuses)
            ->orderByDesc('id')
            ->first();
    }

    private function postableAccount(int $accountId, int $companyId, string $label): Account
    {
        $account = Account::query()
            ->postable()
            ->whereKey($accountId)
            ->where('deprecated', false)
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
            ->first();

        if (! $account) {
            throw new \RuntimeException("The {$label} account cannot be used. Ask your accountant to choose an active account of this company.");
        }

        return $account;
    }

    /**
     * One balanced, posted two-line journal entry with the employee on both
     * lines. Same direct-insert approach as ManualAdjustmentService, refused in
     * a closed period.
     */
    private function postEntry(
        int $companyId,
        int $journalId,
        string $date,
        string $name,
        string $reference,
        string $sourceType,
        int $sourceId,
        string $cashFlowCategory,
        $employee,
        int $debitAccountId,
        int $creditAccountId,
        string $amount,
        string $description,
        ?int $userId,
    ): int {
        if ($debitAccountId === $creditAccountId) {
            throw new \RuntimeException('The two accounts of this entry must be different.');
        }
        if (! $employee?->partner_id) {
            throw new \RuntimeException('This employee has no accounting contact yet. Ask your accountant to set it up.');
        }

        $this->periodLocks->assertNotLocked($companyId, $date, 'post this advance entry');

        $currencyId = (int) DB::table('companies')->where('id', $companyId)->value('currency_id');
        $now = now();

        $moveId = DB::table('accounts_account_moves')->insertGetId([
            'journal_id'             => $journalId, 'company_id' => $companyId, 'currency_id' => $currencyId,
            'partner_id'             => $employee->partner_id, 'date' => $date, 'name' => $name, 'reference' => $reference,
            'move_type'              => MoveType::ENTRY->value, 'state' => MoveState::POSTED->value,
            'accounting_source_type' => $sourceType, 'accounting_source_id' => $sourceId,
            'cash_flow_category'     => $cashFlowCategory, 'review_status' => 'posted',
            'creator_id'             => $userId, 'posted_by_id' => $userId, 'posted_at' => $now, 'posted_before' => true,
            'created_at'             => $now, 'updated_at' => $now,
        ]);

        $line = fn (int $accountId, string $debit, string $credit, string $balance, int $sort): array => [
            'move_id'      => $moveId, 'journal_id' => $journalId, 'account_id' => $accountId, 'partner_id' => $employee->partner_id,
            'company_id'   => $companyId, 'company_currency_id' => $currencyId, 'currency_id' => $currencyId,
            'date'         => $date, 'debit' => $debit, 'credit' => $credit, 'balance' => $balance,
            'parent_state' => MoveState::POSTED->value, 'name' => $description, 'sort' => $sort,
            'created_at'   => $now, 'updated_at' => $now,
        ];

        DB::table('accounts_account_move_lines')->insert([
            $line($debitAccountId, $amount, '0', $amount, 0),
            $line($creditAccountId, '0', $amount, '-'.$amount, 1),
        ]);

        $totals = DB::table('accounts_account_move_lines')->where('move_id', $moveId)->selectRaw('ROUND(SUM(debit), 2) debit, ROUND(SUM(credit), 2) credit')->first();

        if (! $totals || abs((float) $totals->debit - (float) $totals->credit) > 0.005) {
            throw new \RuntimeException('The advance entry is not balanced, so it was not posted.');
        }

        return $moveId;
    }
}
