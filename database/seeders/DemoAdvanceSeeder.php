<?php

namespace Database\Seeders;

use App\Models\DemoRecord;
use App\Services\Demo\DemoRecorder;
use App\Services\Demo\DemoSandbox;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoPdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\Advances\AdvanceService;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Support\AdvanceKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\ApprovalEngine;

/**
 * Business advances for the ShipLine and OpenPort demo companies (php artisan
 * demo:advances): a few staff asking for money before a trip or purchase, in
 * every state HR will meet: paid out and fully closed (spent plus cash handed
 * back), settlement waiting for approval, overdue, waiting for the line
 * manager, waiting for HR and refused. Everything goes through the real
 * services (EmployeeRequestService, AdvanceService) with the clock set to a
 * plausible past moment, so the approval trails, vendor bills and settlement
 * entries are the ones the application itself would write. Every row is
 * recorded in demo_records, so a re-run creates nothing twice and demo:purge
 * removes only demo rows. Truck It In is never touched: its approvers are real
 * people and its books are live.
 */
class DemoAdvanceSeeder extends Seeder
{
    private const COMPANIES = ['ShipLine', 'OpenPort'];

    /** @var array<int, string> */
    public array $warnings = [];

    /** @var array<string, array<string, int>> */
    public array $summary = [];

    private Carbon $realNow;

    private Company $company;

    public function __construct(
        protected DemoRecorder $recorder,
        protected DemoSandbox $sandbox,
    ) {}

    public function run(): void
    {
        $this->realNow = Carbon::now();
        $this->sandbox->isolate();
        $this->recorder->start();

        try {
            foreach (self::COMPANIES as $name) {
                $this->seedCompany($name);
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            Auth::guard('web')->forgetUser();
            $this->recorder->stop();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedCompany(string $name): void
    {
        $company = Company::query()->where('name', $name)->first();

        if (! $company) {
            $this->warnings[] = "{$name}: company not found, skipped.";

            return;
        }

        $this->company = $company;
        $this->recorder->resetCreated();
        $this->recorder->context($company->id, DemoRecord::PHASE_ACTIVITY);

        $type = EmployeeRequestType::query()->where('company_id', $company->id)->where('code', AdvanceKinds::AdvanceCode)->where('is_active', true)->first();

        if (! $type) {
            $this->warnings[] = "{$name}: business advances are not set up for this company, skipped.";

            return;
        }

        $staff = Employee::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereNotNull('parent_id')
            ->whereHas('user', fn ($query) => $query->where('email', 'like', '%@'.Str::lower($name).'.demo'))
            ->orderBy('id')
            ->get()
            ->reject(fn (Employee $employee): bool => (bool) $employee->user?->roles->contains(fn ($role): bool => str_contains(Str::lower((string) $role->name), 'hr_manager')))
            ->values();

        foreach ($this->plan() as $index => $item) {
            $employee = $staff->get($index % max(1, $staff->count()));
            $key = "advance:{$company->id}:{$item['slot']}";

            if (! $employee) {
                continue;
            }

            try {
                $this->runAdvance($employee, $type, $item, $key);
            } catch (Throwable $e) {
                $this->warnings[] = "{$name}: advance {$item['slot']} skipped: ".Str::limit($e->getMessage(), 200);
            }
        }

        $this->recorder->flush();
        $this->summary[$name] = $this->recorder->created();
        $this->recorder->context(null, DemoRecord::PHASE_STAFF);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function plan(): array
    {
        return [
            ['slot' => 'closed', 'amount' => '60000', 'days_ago' => 24, 'needed_in' => 2, 'title' => 'Lahore client visit', 'purpose' => 'Hotel, fuel and meals for the two-day client visit in Lahore', 'approvals' => 3, 'spent' => '45300', 'settled' => true, 'returned' => '14700'],
            ['slot' => 'settling', 'amount' => '40000', 'days_ago' => 15, 'needed_in' => 2, 'title' => 'Trade fair stand', 'purpose' => 'Banners, printing and stand set-up for the Expo Centre trade fair', 'approvals' => 3, 'spent' => '38250', 'settled' => false],
            ['slot' => 'overdue', 'amount' => '25000', 'days_ago' => 34, 'needed_in' => 1, 'title' => 'Site visits fuel', 'purpose' => 'Fuel and tolls for the warehouse site visits around Karachi', 'approvals' => 3],
            ['slot' => 'open', 'amount' => '35000', 'days_ago' => 8, 'needed_in' => 1, 'title' => 'Customer onboarding trip', 'purpose' => 'Travel and accommodation for onboarding a new customer in Islamabad', 'approvals' => 3],
            ['slot' => 'with-manager', 'amount' => '80000', 'days_ago' => 2, 'needed_in' => 6, 'title' => 'Supplier meetings, Faisalabad', 'purpose' => 'Travel and meals for three supplier meetings in Faisalabad', 'approvals' => 0],
            ['slot' => 'with-hr', 'amount' => '30000', 'days_ago' => 4, 'needed_in' => 3, 'title' => 'Courier and packaging stock', 'purpose' => 'Packaging material and courier charges for the month-end dispatches', 'approvals' => 1],
            ['slot' => 'refused', 'amount' => '150000', 'days_ago' => 11, 'needed_in' => 5, 'title' => 'Team offsite deposit', 'purpose' => 'Venue deposit for the quarterly team offsite', 'approvals' => 0, 'reject' => 'Please send this as a purchase request with the venue quotation instead of an advance.'],
        ];
    }

    /**
     * Creates the advance, or picks it up where an earlier run stopped, and
     * takes it as far as the plan says. Safe to run again.
     *
     * @param  array<string, mixed>  $item
     */
    private function runAdvance(Employee $employee, EmployeeRequestType $type, array $item, string $key): void
    {
        $user = $employee->user;
        $submit = $this->workHour(Carbon::now('Asia/Karachi')->startOfDay()->subDays((int) $item['days_ago'])->addHours(10)->addMinutes(random_int(5, 55)));
        $neededBy = $submit->copy()->addDays((int) $item['needed_in'])->toDateString();

        $existing = $this->recorder->find($key);
        $request = $existing ? EmployeeRequest::query()->find($existing->record_id) : null;

        if (! $request) {
            $request = $this->at($submit, $user, fn (): EmployeeRequest => $this->recorder->transaction(function () use ($employee, $user, $type, $item, $neededBy): EmployeeRequest {
                $request = EmployeeRequest::query()->create([
                    'company_id'      => $employee->company_id,
                    'employee_id'     => $employee->id,
                    'request_type_id' => $type->id,
                    'requested_by'    => $user->id,
                    'currency_id'     => $this->company->currency_id,
                    'title'           => $item['title'],
                    'description'     => $item['purpose'],
                    'amount'          => $item['amount'],
                    'payload'         => ['purpose' => $item['purpose'], 'needed_by' => $neededBy],
                    'account_title'   => $employee->name,
                    'bank_name'       => 'Meezan Bank',
                    'iban'            => 'PK'.random_int(10, 99).'MEZN'.str_pad((string) random_int(1, 9_999_999_999_999_999), 16, '0', STR_PAD_LEFT),
                    'status'          => 'draft',
                ]);

                app(EmployeeRequestService::class)->submit($request, $user);

                return $request;
            }));

            $this->recorder->mark($key, $request);
        }

        $request = $request->fresh();
        $moment = $request->created_at ? Carbon::parse($request->created_at) : $submit->copy();
        $done = $request->approvalRequest ? (int) DB::table('support_approval_decisions')->where('request_id', $request->approvalRequest->id)->where('decision', 'approved')->count() : 0;

        for ($step = $done; $step < (int) $item['approvals']; $step++) {
            $moment = $this->workHour($moment->copy()->addHours(random_int(3, 20)));
            if (! $this->decide($request, $moment, true)) {
                break;
            }
        }

        if (isset($item['reject']) && $request->fresh()->status === 'pending_approval') {
            $this->decide($request, $this->workHour($moment->copy()->addHours(random_int(3, 20))), false, (string) $item['reject']);
        }

        $request = $request->fresh();
        if ($request->status !== 'approved') {
            return;
        }

        $this->trackAccounting($request);

        if (isset($item['spent'])) {
            $this->settle($request, $employee, $item, $moment);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function settle(EmployeeRequest $advance, Employee $employee, array $item, Carbon $paidAt): void
    {
        $user = $employee->user;
        $when = $this->workHour(Carbon::parse($advance->payload['needed_by'] ?? $paidAt, 'Asia/Karachi')->addDays(3)->setTime(11, 0));
        $path = 'employees/requests/demo-receipts-'.$advance->id.'.pdf';

        Storage::disk('local')->put($path, DemoPdf::make('Receipts: '.$advance->title, [
            $employee->name.' - '.$this->company->name,
            'Spent on: '.$advance->description,
            'Total spent: PKR '.number_format((float) $item['spent']),
        ]));
        $this->recorder->trackFile('local', $path);

        $settlement = EmployeeRequest::query()->where('advance_request_id', $advance->id)->latest('id')->first()
            ?? $this->at($when, $user, fn (): EmployeeRequest => $this->recorder->transaction(
                fn (): EmployeeRequest => app(AdvanceService::class)->startSettlement($user, $advance->fresh(), (string) $item['spent'], [$path], 'Receipts attached for: '.$advance->description, $when->toDateString()),
            ));

        if (! ($item['settled'] ?? false)) {
            return;
        }

        $moment = $when->copy();
        for ($step = 0; $step < 4; $step++) {
            $moment = $this->workHour($moment->copy()->addHours(random_int(3, 20)));
            if (! $this->decide($settlement, $moment, true)) {
                break;
            }
        }

        $this->trackAccounting($advance->fresh());

        if (isset($item['returned'])) {
            $finance = $this->financeUser();
            $journals = app(AdvanceService::class)->returnJournalOptions($advance);

            $alreadyReturned = (float) app(AdvanceService::class)->summary($advance->fresh())['outstanding'] <= 0;

            if ($finance && $journals !== [] && ! $alreadyReturned) {
                $returnedAt = $this->workHour($moment->copy()->addDay());

                $this->at($returnedAt, $finance, fn () => $this->recorder->transaction(
                    fn () => app(AdvanceService::class)->recordReturnedCash($finance, $advance->fresh(), (string) $item['returned'], (int) array_key_first($journals), $returnedAt->toDateString(), 'DEP-'.random_int(1000, 9999), 'Balance of the advance handed back'),
                ));
                $this->trackAccounting($advance->fresh());
            }
        }
    }

    /** Settlement and cash-return entries are written without model events, so they are remembered by hand. */
    private function trackAccounting(EmployeeRequest $advance): void
    {
        $sourceIds = EmployeeRequest::query()->where('advance_request_id', $advance->id)->pluck('id')->push($advance->id)->all();
        $moves = DB::table('accounts_account_moves')->where('company_id', $advance->company_id)->whereIn('accounting_source_id', $sourceIds)->pluck('id')->all();

        if ($moves === []) {
            return;
        }

        $this->recorder->track('accounts_account_moves', $moves);
        $this->recorder->track('accounts_account_move_lines', DB::table('accounts_account_move_lines')->whereIn('move_id', $moves)->pluck('id')->all());
        $this->recorder->flush();
    }

    private function decide(EmployeeRequest $request, Carbon $when, bool $approve, ?string $reason = null): bool
    {
        $request = $request->fresh();
        $approval = $request?->approvalRequest;

        if (! $approval || $approval->status !== 'pending') {
            return false;
        }

        $actor = $this->approverFor($approval, (int) $request->employee?->user_id);

        if (! $actor) {
            return false;
        }

        $this->at($when, $actor, fn () => $this->recorder->transaction(fn () => $approve
            ? app(EmployeeRequestService::class)->approve($request, $actor)
            : app(EmployeeRequestService::class)->reject($request, $actor, (string) $reason)));

        return true;
    }

    /** The demo user who decides the current step: never the requester and never a real user. */
    private function approverFor(ApprovalRequest $approval, ?int $subjectUserId): ?User
    {
        ApprovalEngine::flushMemo();
        $engine = app(ApprovalEngine::class);
        $approval = $approval->fresh();

        return $engine->resolveAuthorizedApprovers($approval)
            ->filter(fn (User $user): bool => DemoSandbox::isDemoEmail($user->email) && (int) $user->id !== (int) $subjectUserId)
            ->sortBy(fn (User $user): int => ($user->hasRole('hr_manager') ? 0 : 1_000_000) + (int) $user->id)
            ->first(fn (User $user): bool => $engine->canAct($approval->fresh(), $user));
    }

    private function financeUser(): ?User
    {
        return User::query()
            ->where('email', 'like', '%@'.Str::lower($this->company->name).'.demo')
            ->get()
            ->first(fn (User $user): bool => $user->can(HrPermissions::ProcessFinancialRequests));
    }

    /** Moves a moment into office hours (Karachi, 10:00-18:00, Monday to Friday). */
    private function workHour(Carbon $moment): Carbon
    {
        $moment = $moment->copy()->setTimezone('Asia/Karachi');

        while ($moment->isWeekend()) {
            $moment->addDay()->setTime(10, random_int(0, 59));
        }

        if ($moment->hour < 10) {
            $moment->setTime(10, random_int(0, 59));
        } elseif ($moment->hour >= 18) {
            $moment->addDay()->setTime(10, random_int(0, 59));

            return $this->workHour($moment);
        }

        return $moment;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function at(Carbon $moment, ?User $actor, callable $callback): mixed
    {
        $moment = $moment->copy()->utc()->min($this->realNow->copy()->subMinutes(2));
        $previous = Auth::guard('web')->user();

        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow(CarbonImmutable::instance($moment));

        if ($actor) {
            Auth::guard('web')->setUser($actor);
        }

        try {
            return $callback();
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();

            $previous ? Auth::guard('web')->setUser($previous) : Auth::guard('web')->forgetUser();
        }
    }
}
