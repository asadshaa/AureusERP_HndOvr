<?php

namespace Webkul\Employee\Services\Payroll;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\RequestInstalment;
use Webkul\Employee\Models\RequestInstalmentEvent;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\EmployeeRequestKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Loan instalments and salary-advance deductions (HR parity plan 3.3).
 *
 * On the final approval of a loan, its payload schedule becomes one "due"
 * row per instalment; an approved salary advance becomes one "due" row for
 * its deduct-from month. Loans and advances approved before this existed get
 * their rows from backfill() (idempotent).
 *
 * HR with hr_export_payroll (of the request's company, never on their own
 * loan) can:
 *  - mark an instalment deducted (not before its month), or undo that;
 *  - skip one (optionally carrying its amount to a new instalment after the
 *    last one);
 *  - reschedule what is left (loan amount minus deducted and settled) over a
 *    new number of months from a new first month;
 *  - settle early: every remaining due instalment is closed as "settled".
 * The payroll-input page marks the month's due instalments deducted in one
 * go (markMonthDeducted(), idempotent). Every change is audited in
 * employees_request_instalment_events with the rows before and after.
 *
 * Nothing here posts to accounting or calculates pay.
 */
class RequestInstalmentService
{
    private static ?bool $tableExists = null;

    public function __construct(
        protected HrHierarchyService $hierarchy,
    ) {}

    public static function available(): bool
    {
        return self::$tableExists ??= Schema::hasTable('employees_request_instalments');
    }

    /** loan | salary_advance for a Loan / Salary advance request, null otherwise. */
    public static function kindFor(EmployeeRequest $request): ?string
    {
        return match ($request->requestType?->category) {
            EmployeeRequestKinds::LoanCategory          => RequestInstalment::KindLoan,
            EmployeeRequestKinds::SalaryAdvanceCategory => RequestInstalment::KindAdvance,
            default                                     => null,
        };
    }

    // ------------------------------------------------------------------
    // Authorisation
    // ------------------------------------------------------------------

    /** HR who run the payroll input of the company (hr_export_payroll and a member of it). */
    public static function canManageCompany(?User $user, int $companyId): bool
    {
        return $user !== null
            && $companyId > 0
            && $user->can(HrPermissions::ExportPayroll)
            && CurrentCompany::canAccess($companyId, $user);
    }

    /** Change one request's instalments: payroll HR of its company, the employee in their HR scope, never their own. */
    public function canManage(?User $user, EmployeeRequest $request): bool
    {
        $request->loadMissing('employee');
        $employee = $request->employee;

        return $user !== null
            && $employee !== null
            && static::canManageCompany($user, (int) $request->company_id)
            && (int) $employee->company_id === (int) $request->company_id
            && ($employee->user_id === null || (int) $employee->user_id !== (int) $user->id)
            && $this->hierarchy->canManage($user, $employee);
    }

    /** The employee themself, or HR (payroll or view-all) of the employee's company in their scope. */
    public function canView(?User $user, Employee $employee): bool
    {
        if ($user === null) {
            return false;
        }

        if ($employee->user_id !== null && (int) $employee->user_id === (int) $user->id) {
            return true;
        }

        $companyId = (int) $employee->company_id;

        return ($user->can(HrPermissions::ExportPayroll) || $user->can(HrPermissions::ViewAllRecords))
            && CurrentCompany::canAccess($companyId, $user)
            && $this->hierarchy->canManage($user, $employee);
    }

    // ------------------------------------------------------------------
    // Creation
    // ------------------------------------------------------------------

    /**
     * The request's rows, created once from its approved schedule. Does
     * nothing (returns the existing rows) when they already exist, and
     * nothing at all for a request that is not an approved loan / advance.
     *
     * @return Collection<int, RequestInstalment>
     */
    public function createForApprovedRequest(EmployeeRequest $request, ?User $actor = null, bool $backfill = false): Collection
    {
        if (! static::available()) {
            return new Collection;
        }

        $request->loadMissing('requestType');
        $kind = static::kindFor($request);

        if ($kind === null || $request->status !== 'approved') {
            return new Collection;
        }

        return DB::transaction(function () use ($request, $kind, $actor, $backfill): Collection {
            EmployeeRequest::query()->whereKey($request->id)->lockForUpdate()->first(['id']);

            $existing = $this->rowsOf($request, true);
            if ($existing->isNotEmpty()) {
                return $existing;
            }

            $plan = $this->planFor($request, $kind);
            if ($plan === []) {
                report(new RuntimeException("Approved {$kind} request #{$request->id} has no usable schedule; no instalments were recorded."));

                return new Collection;
            }

            foreach ($plan as $line) {
                RequestInstalment::query()->create([
                    'company_id'  => $request->company_id,
                    'employee_id' => $request->employee_id,
                    'request_id'  => $request->id,
                    'kind'        => $kind,
                    'number'      => $line['number'],
                    'due_month'   => $line['month'],
                    'amount'      => $line['amount'],
                    'status'      => RequestInstalment::Due,
                    'note'        => $backfill ? 'Recorded from the approved schedule (backfill).' : null,
                ]);
            }

            $rows = $this->rowsOf($request);
            // The final approver (the decision that completed the approval).
            $actorId = $actor?->id ?? ($backfill ? null : $request->approvalRequest?->decisions()->reorder()->orderByDesc('id')->value('actor_id'));
            $this->audit($request, null, $backfill ? 'backfilled' : 'created', $actorId, null, null, $this->describe($rows));

            return $rows;
        });
    }

    /**
     * Every approved loan and salary advance without rows gets them
     * (idempotent).
     *
     * @return int requests given rows
     */
    public function backfill(?int $companyId = null): int
    {
        if (! Schema::hasTable('employees_request_instalments')) {
            return 0;
        }

        $created = 0;

        EmployeeRequest::query()
            ->where('status', 'approved')
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->whereHas('requestType', fn ($query) => $query->whereIn('category', [EmployeeRequestKinds::LoanCategory, EmployeeRequestKinds::SalaryAdvanceCategory]))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('employees_request_instalments')->whereColumn('employees_request_instalments.request_id', 'employees_requests.id'))
            ->with('requestType')
            ->chunkById(100, function ($requests) use (&$created): void {
                foreach ($requests as $request) {
                    if ($this->createForApprovedRequest($request, null, true)->isNotEmpty()) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    /**
     * The rows an approved request becomes: the loan's payload schedule
     * (recalculated from the amount, count and first month when it is
     * missing), or the advance's single deduct-from month.
     *
     * @return array<int, array{number: int, month: string, amount: string}>
     */
    public function planFor(EmployeeRequest $request, string $kind): array
    {
        $payload = (array) ($request->payload ?? []);
        $amount = (string) ($request->amount ?? '0');

        if (! is_numeric($amount) || BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            return [];
        }

        if ($kind === RequestInstalment::KindAdvance) {
            $month = $payload['deduct_month'] ?? null;

            return static::isMonth($month)
                ? [['number' => 1, 'month' => (string) $month, 'amount' => (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HALF_UP)]]
                : [];
        }

        $schedule = array_values(array_filter(
            (array) ($payload['schedule'] ?? []),
            fn ($line): bool => is_array($line) && static::isMonth($line['month'] ?? null) && is_numeric($line['amount'] ?? null),
        ));

        if ($schedule === [] && static::isMonth($payload['start_month'] ?? null)) {
            $count = min(EmployeeRequestKinds::MaxInstalments, max(EmployeeRequestKinds::MinInstalments, (int) ($payload['instalments'] ?? 1)));
            $schedule = EmployeeRequestKinds::instalmentSchedule($amount, $count, (string) $payload['start_month']);
        }

        return array_map(fn (array $line, int $index): array => [
            'number' => $index + 1,
            'month'  => (string) $line['month'],
            'amount' => (string) BigDecimal::of((string) $line['amount'])->toScale(2, RoundingMode::HALF_UP),
        ], $schedule, array_keys($schedule));
    }

    // ------------------------------------------------------------------
    // HR changes
    // ------------------------------------------------------------------

    /** @throws AuthorizationException|RuntimeException */
    public function markDeducted(RequestInstalment $instalment, User $actor, ?string $note = null): RequestInstalment
    {
        return $this->changeOne($instalment, $actor, 'deducted', $note, function (RequestInstalment $row) use ($note): array {
            if ($row->status !== RequestInstalment::Due) {
                throw new RuntimeException("Instalment #{$row->number} is ".mb_strtolower($row->statusLabel()).'; only a due instalment can be marked deducted.');
            }
            if ($row->due_month > $this->currentMonth()) {
                throw new RuntimeException('An instalment due in '.static::monthLabel($row->due_month).' cannot be marked deducted before that month.');
            }

            return ['status' => RequestInstalment::Deducted, 'note' => filled($note) ? trim((string) $note) : $row->note];
        });
    }

    /**
     * Skips a due instalment. With $carryForward its amount is added as a new
     * instalment the month after the last one, so the loan still adds up.
     *
     * @throws AuthorizationException|RuntimeException
     */
    public function skip(RequestInstalment $instalment, User $actor, string $reason, bool $carryForward = true): RequestInstalment
    {
        $reason = $this->requireReason($reason);

        return $this->changeOne($instalment, $actor, 'skipped', $reason, function (RequestInstalment $row) use ($reason, $carryForward, $actor): array {
            if ($row->status !== RequestInstalment::Due) {
                throw new RuntimeException("Instalment #{$row->number} is ".mb_strtolower($row->statusLabel()).'; only a due instalment can be skipped.');
            }

            if ($carryForward) {
                $all = RequestInstalment::query()->where('request_id', $row->request_id)->get(['number', 'due_month']);
                $carried = RequestInstalment::query()->create([
                    'company_id'        => $row->company_id,
                    'employee_id'       => $row->employee_id,
                    'request_id'        => $row->request_id,
                    'kind'              => $row->kind,
                    'number'            => (int) $all->max('number') + 1,
                    'due_month'         => static::addMonths((string) $all->max('due_month'), 1),
                    'amount'            => $row->amount,
                    'status'            => RequestInstalment::Due,
                    'note'              => "Carried forward from instalment #{$row->number} (".static::monthLabel($row->due_month).'), which was skipped.',
                    'status_changed_at' => now(),
                    'status_changed_by' => $actor->id,
                ]);

                $this->audit($row->request, $carried->id, 'carried_forward', $actor->id, $reason, null, $this->describe(new Collection([$carried])));
            }

            return ['status' => RequestInstalment::Skipped, 'note' => $reason];
        });
    }

    /**
     * Undoes a deduction marked by mistake: the instalment is due again.
     *
     * @throws AuthorizationException|RuntimeException
     */
    public function revertToDue(RequestInstalment $instalment, User $actor, string $reason): RequestInstalment
    {
        $reason = $this->requireReason($reason);

        return $this->changeOne($instalment, $actor, 'reverted', $reason, function (RequestInstalment $row) use ($reason): array {
            if ($row->status !== RequestInstalment::Deducted) {
                throw new RuntimeException("Instalment #{$row->number} is ".mb_strtolower($row->statusLabel()).'; only a deducted instalment can be made due again.');
            }

            return ['status' => RequestInstalment::Due, 'note' => 'Deduction undone: '.$reason];
        });
    }

    /**
     * Replaces the remaining due instalments: what is left (amount minus
     * deducted and settled) in $count equal monthly instalments from
     * $startMonth, which must come after the last month already deducted.
     *
     * @return Collection<int, RequestInstalment> the request's rows afterwards
     *
     * @throws AuthorizationException|RuntimeException|InvalidArgumentException
     */
    public function reschedule(EmployeeRequest $request, User $actor, string $startMonth, int $count, string $reason): Collection
    {
        $reason = $this->requireReason($reason);
        $this->assertCanManage($actor, $request);

        if (! static::isMonth($startMonth)) {
            throw new InvalidArgumentException('The first month must look like 2026-11.');
        }
        if ($count < EmployeeRequestKinds::MinInstalments || $count > EmployeeRequestKinds::MaxInstalments) {
            throw new InvalidArgumentException('The number of instalments must be between '.EmployeeRequestKinds::MinInstalments.' and '.EmployeeRequestKinds::MaxInstalments.'.');
        }

        return DB::transaction(function () use ($request, $actor, $startMonth, $count, $reason): Collection {
            $rows = $this->rowsOf($request, true);
            if ($rows->isEmpty()) {
                throw new RuntimeException('This request has no instalments to reschedule.');
            }

            $taken = $rows->whereIn('status', [RequestInstalment::Deducted, RequestInstalment::Settled]);
            $outstanding = BigDecimal::of((string) $request->amount)->minus($this->sum($taken));
            if ($outstanding->isLessThanOrEqualTo(0)) {
                throw new RuntimeException('Nothing is left to deduct on this request.');
            }

            $lastTaken = $taken->max('due_month');
            if ($lastTaken !== null && $startMonth <= $lastTaken) {
                throw new RuntimeException('The first month must be after '.static::monthLabel($lastTaken).', the last month already deducted.');
            }

            $due = $rows->where('status', RequestInstalment::Due);
            $before = $this->describe($due);
            RequestInstalment::query()->whereIn('id', $due->modelKeys())->delete();

            $next = (int) $rows->where('status', '!=', RequestInstalment::Due)->max('number') + 1;
            foreach (EmployeeRequestKinds::instalmentSchedule((string) $outstanding, $count, $startMonth) as $index => $line) {
                RequestInstalment::query()->create([
                    'company_id'        => $request->company_id,
                    'employee_id'       => $request->employee_id,
                    'request_id'        => $request->id,
                    'kind'              => static::kindFor($request) ?? RequestInstalment::KindLoan,
                    'number'            => $next + $index,
                    'due_month'         => $line['month'],
                    'amount'            => $line['amount'],
                    'status'            => RequestInstalment::Due,
                    'note'              => 'Rescheduled on '.now()->format('d M Y').'.',
                    'status_changed_at' => now(),
                    'status_changed_by' => $actor->id,
                ]);
            }

            $after = $this->rowsOf($request);
            $this->audit($request, null, 'rescheduled', $actor->id, $reason, $before, $this->describe($after->where('status', RequestInstalment::Due)));

            return $after;
        });
    }

    /**
     * Early settlement: every remaining due instalment is closed as settled.
     *
     * @return int instalments settled
     *
     * @throws AuthorizationException|RuntimeException
     */
    public function settle(EmployeeRequest $request, User $actor, string $reason): int
    {
        $reason = $this->requireReason($reason);
        $this->assertCanManage($actor, $request);

        return DB::transaction(function () use ($request, $actor, $reason): int {
            $due = $this->rowsOf($request, true)->where('status', RequestInstalment::Due);
            if ($due->isEmpty()) {
                throw new RuntimeException('No instalment of this request is due, so there is nothing to settle.');
            }

            $before = $this->describe($due);
            foreach ($due as $row) {
                $row->update([
                    'status'            => RequestInstalment::Settled,
                    'note'              => 'Settled early: '.$reason,
                    'status_changed_at' => now(),
                    'status_changed_by' => $actor->id,
                ]);
            }

            $this->audit($request, null, 'settled', $actor->id, $reason, $before, $this->describe($due));

            return $due->count();
        });
    }

    /**
     * Marks every due instalment of the company's month deducted (the
     * payroll-input page, after the export). Idempotent: deducted rows are
     * left alone, so a second run changes nothing.
     *
     * @param  array<int, int>|null  $employeeIds  limit to these employees (still within the user's scope)
     * @return array{count: int, amount: string}
     *
     * @throws AuthorizationException|RuntimeException|InvalidArgumentException
     */
    public function markMonthDeducted(User $actor, int $companyId, string $month, ?array $employeeIds = null): array
    {
        if (! static::canManageCompany($actor, $companyId)) {
            throw new AuthorizationException('You may not record payroll deductions for this company.');
        }
        if (! static::isMonth($month)) {
            throw new InvalidArgumentException('The month must look like 2026-11.');
        }
        if ($month > $this->currentMonth()) {
            throw new RuntimeException(static::monthLabel($month).' has not started; its instalments cannot be marked deducted yet.');
        }

        $visible = $this->hierarchy->visibleEmployeeIds($actor, $companyId)->all();
        if ($employeeIds !== null) {
            $visible = array_values(array_intersect($visible, array_map('intval', $employeeIds)));
        }

        return DB::transaction(function () use ($actor, $companyId, $month, $visible): array {
            $rows = RequestInstalment::query()
                ->where('company_id', $companyId)
                ->where('due_month', $month)
                ->where('status', RequestInstalment::Due)
                ->whereIn('employee_id', $visible)
                ->with('request')
                ->lockForUpdate()
                ->get();

            $reason = 'Payroll input '.static::monthLabel($month);
            foreach ($rows as $row) {
                $before = $this->describe(new Collection([$row]));
                $row->update([
                    'status'            => RequestInstalment::Deducted,
                    'status_changed_at' => now(),
                    'status_changed_by' => $actor->id,
                ]);
                $this->audit($row->request, $row->id, 'deducted', $actor->id, $reason, $before, $this->describe(new Collection([$row])));
            }

            return ['count' => $rows->count(), 'amount' => (string) $this->sum($rows)->toScale(2, RoundingMode::HALF_UP)];
        });
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /** @return Collection<int, RequestInstalment> */
    public function scheduleFor(EmployeeRequest $request): Collection
    {
        return static::available() ? $this->rowsOf($request) : new Collection;
    }

    /**
     * What the payroll month holds per employee: the loan instalments and
     * advance deductions of the month (due or already deducted), and notes
     * about skipped instalments of the month and earlier ones still due.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array{loan: string, advance: string, due_count: int, due_amount: string, notes: array<int, string>}>
     */
    public function monthFor(int $companyId, string $month, array $employeeIds): array
    {
        if (! static::available() || $employeeIds === []) {
            return [];
        }

        $rows = RequestInstalment::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', array_map('intval', $employeeIds))
            ->where(fn ($query) => $query
                ->where('due_month', $month)
                ->orWhere(fn ($earlier) => $earlier->where('due_month', '<', $month)->where('status', RequestInstalment::Due)))
            ->orderBy('due_month')
            ->orderBy('request_id')
            ->orderBy('number')
            ->get();

        $result = [];
        foreach ($rows->groupBy('employee_id') as $employeeId => $employeeRows) {
            $thisMonth = $employeeRows->where('due_month', $month);
            $counted = $thisMonth->whereIn('status', [RequestInstalment::Due, RequestInstalment::Deducted]);
            $notes = [];

            foreach ($thisMonth->where('status', RequestInstalment::Skipped) as $row) {
                $notes[] = RequestInstalment::kindLabel($row->kind).' instalment #'.$row->number.' skipped this month';
            }
            $earlier = $employeeRows->where('due_month', '<', $month);
            if ($earlier->isNotEmpty()) {
                $notes[] = $earlier->count().' earlier instalment(s) still due ('.number_format((float) (string) $this->sum($earlier), 2).', from '.static::monthLabel((string) $earlier->min('due_month')).'), not included';
            }
            if ($thisMonth->where('status', RequestInstalment::Deducted)->isNotEmpty()) {
                $notes[] = 'Deductions already marked as taken';
            }

            $result[(int) $employeeId] = [
                'loan'       => (string) $this->sum($counted->where('kind', RequestInstalment::KindLoan))->toScale(2, RoundingMode::HALF_UP),
                'advance'    => (string) $this->sum($counted->where('kind', RequestInstalment::KindAdvance))->toScale(2, RoundingMode::HALF_UP),
                'due_count'  => $thisMonth->where('status', RequestInstalment::Due)->count(),
                'due_amount' => (string) $this->sum($thisMonth->where('status', RequestInstalment::Due))->toScale(2, RoundingMode::HALF_UP),
                'notes'      => $notes,
            ];
        }

        return $result;
    }

    /** The request's schedule with statuses as a small HTML table, or null when it has no rows yet. */
    public function scheduleHtml(EmployeeRequest $request): ?string
    {
        $rows = $this->scheduleFor($request);
        if ($rows->isEmpty()) {
            return null;
        }

        $currency = e($request->currency?->code ?? '');
        $colors = [
            RequestInstalment::Deducted => 'color: rgb(22, 163, 74);',
            RequestInstalment::Skipped  => 'color: rgb(217, 119, 6);',
            RequestInstalment::Settled  => 'color: rgb(37, 99, 235);',
        ];
        $body = $rows->map(fn (RequestInstalment $row): string => '<tr>'
            .'<td class="px-3 py-1">'.e((string) $row->number).'</td>'
            .'<td class="px-3 py-1">'.e((string) static::monthLabel($row->due_month)).'</td>'
            .'<td class="px-3 py-1 text-end">'.e(number_format((float) $row->amount, 2)).' '.$currency.'</td>'
            .'<td class="px-3 py-1" style="font-weight: 600; '.($colors[$row->status] ?? '').'">'.e($row->statusLabel()).'</td>'
            .'<td class="px-3 py-1 text-gray-500">'.e((string) ($row->note ?? '')).'</td>'
            .'</tr>')->implode('');

        $taken = $this->sum($rows->whereIn('status', [RequestInstalment::Deducted, RequestInstalment::Settled]));
        $left = $this->sum($rows->where('status', RequestInstalment::Due));

        return '<table class="text-sm"><thead><tr>'
            .'<th class="px-3 py-1 text-start">#</th><th class="px-3 py-1 text-start">Month</th><th class="px-3 py-1 text-end">Amount</th>'
            .'<th class="px-3 py-1 text-start">Status</th><th class="px-3 py-1 text-start">Note</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table>'
            .'<p class="text-sm" style="margin-top: .5rem;">Deducted or settled: '.e(number_format((float) (string) $taken, 2)).' '.$currency
            .' &middot; Still due: '.e(number_format((float) (string) $left, 2)).' '.$currency.'</p>';
    }

    public static function monthLabel(?string $month): string
    {
        return static::isMonth($month) ? CarbonImmutable::createFromFormat('!Y-m', (string) $month)->format('F Y') : (string) $month;
    }

    public static function isMonth(mixed $month): bool
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1;
    }

    // ------------------------------------------------------------------

    /**
     * Locks one instalment, checks the user may change it, applies the
     * change and audits it.
     *
     * @param  callable(RequestInstalment): array<string, mixed>  $change
     */
    private function changeOne(RequestInstalment $instalment, User $actor, string $action, ?string $reason, callable $change): RequestInstalment
    {
        $request = EmployeeRequest::query()->with(['employee', 'requestType'])->find($instalment->request_id);
        if (! $request) {
            throw new RuntimeException('The instalment\'s request no longer exists.');
        }
        $this->assertCanManage($actor, $request);

        return DB::transaction(function () use ($instalment, $actor, $action, $reason, $change, $request): RequestInstalment {
            /** @var RequestInstalment $row */
            $row = RequestInstalment::query()->whereKey($instalment->id)->lockForUpdate()->firstOrFail();
            $row->setRelation('request', $request);

            $before = $this->describe(new Collection([$row]));
            $row->update($change($row) + [
                'status_changed_at' => now(),
                'status_changed_by' => $actor->id,
            ]);

            $this->audit($request, $row->id, $action, $actor->id, $reason, $before, $this->describe(new Collection([$row])));

            return $row->fresh();
        });
    }

    /** @throws AuthorizationException */
    private function assertCanManage(User $actor, EmployeeRequest $request): void
    {
        if (! $this->canManage($actor, $request)) {
            throw new AuthorizationException('Only payroll HR of this company can change these instalments (and never on their own loan or advance).');
        }

        if ($request->status !== 'approved' || static::kindFor($request->loadMissing('requestType')) === null) {
            throw new RuntimeException('Only an approved loan or salary advance has instalments.');
        }
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('Please give a reason.');
        }

        return mb_substr($reason, 0, 1000);
    }

    /** @return Collection<int, RequestInstalment> */
    private function rowsOf(EmployeeRequest $request, bool $lock = false): Collection
    {
        return RequestInstalment::query()
            ->where('request_id', $request->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('number')
            ->get();
    }

    /**
     * @param  iterable<int, RequestInstalment>  $rows
     * @return array<int, array{id: int, number: int, month: string, amount: string, status: string, note: ?string}>
     */
    private function describe(iterable $rows): array
    {
        $described = [];
        foreach ($rows as $row) {
            $described[] = [
                'id'     => (int) $row->id,
                'number' => (int) $row->number,
                'month'  => (string) $row->due_month,
                'amount' => (string) BigDecimal::of((string) $row->amount)->toScale(2, RoundingMode::HALF_UP),
                'status' => (string) $row->status,
                'note'   => $row->note,
            ];
        }

        return $described;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $before
     * @param  array<int, array<string, mixed>>|null  $after
     */
    private function audit(?EmployeeRequest $request, ?int $instalmentId, string $action, ?int $userId, ?string $reason, ?array $before, ?array $after): void
    {
        if ($request === null) {
            return;
        }

        RequestInstalmentEvent::query()->create([
            'company_id'    => $request->company_id,
            'request_id'    => $request->id,
            'instalment_id' => $instalmentId,
            'action'        => $action,
            'user_id'       => $userId,
            'reason'        => $reason !== null ? mb_substr($reason, 0, 1000) : null,
            'before'        => $before,
            'after'         => $after,
        ]);
    }

    /** @param  iterable<int, RequestInstalment>  $rows */
    private function sum(iterable $rows): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($rows as $row) {
            $total = $total->plus((string) $row->amount);
        }

        return $total;
    }

    private static function addMonths(string $month, int $months): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->addMonthsNoOverflow($months)->format('Y-m');
    }

    private function currentMonth(): string
    {
        return CarbonImmutable::now((string) config('attendance.monthly_report.timezone', 'Asia/Karachi'))->format('Y-m');
    }
}
