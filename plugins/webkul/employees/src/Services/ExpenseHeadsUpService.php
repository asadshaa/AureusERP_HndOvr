<?php

namespace Webkul\Employee\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Support\AdvanceKinds;
use Webkul\Employee\Support\ExpenseHeadsUp;
use Webkul\Security\Models\User;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * Expense heads-up: validation on submit, turning an approved heads-up into a
 * draft claim, cancelling it, the over-estimate comparison on the claim, and the
 * daily "you said you would spend this" reminder (hr:heads-up-reminders).
 * Nothing here posts to Accounting: only the claim a heads-up becomes does,
 * through the normal claim approval chain.
 */
class ExpenseHeadsUpService
{
    public function __construct(
        protected HierarchicalNotificationService $notifications,
    ) {}

    public static function today(): string
    {
        return AdvanceKinds::today();
    }

    /** Checks and tidies the heads-up fields; runs on every submission. */
    public function prepareSubmission(EmployeeRequest $request): void
    {
        $payload = (array) ($request->payload ?? []);

        if (blank($payload['what_for'] ?? null)) {
            throw new RuntimeException('Please say what you will spend the money on and why.');
        }
        if (blank($payload['expected_on'] ?? null) || strtotime((string) $payload['expected_on']) === false) {
            throw new RuntimeException('Please give the date the expense will be made.');
        }
        if (! is_numeric($payload['estimated_amount'] ?? null) || BigDecimal::of((string) $payload['estimated_amount'])->isLessThanOrEqualTo(0)) {
            throw new RuntimeException('Enter an estimated amount greater than zero.');
        }

        $expectedOn = CarbonImmutable::parse((string) $payload['expected_on'])->toDateString();
        if ($expectedOn < self::today()) {
            throw new RuntimeException('The expected date cannot be in the past. If the money is already spent, file a claim instead.');
        }

        $payload['kind'] = ExpenseHeadsUp::Category;
        $payload['expected_on'] = $expectedOn;
        $payload['estimated_amount'] = (string) BigDecimal::of((string) $payload['estimated_amount'])->toScale(2);

        $request->payload = $payload;
        $request->save();
    }

    /** Whether the heads-up is approved and has not been claimed or cancelled yet. */
    public function isOpen(EmployeeRequest $headsUp): bool
    {
        return ExpenseHeadsUp::isHeadsUp($headsUp->requestType)
            && $headsUp->status === 'approved'
            && blank(data_get($headsUp->payload, 'claim_request_id'));
    }

    public function canConvert(EmployeeRequest $headsUp, User $user): bool
    {
        return $this->isOpen($headsUp) && $this->isOwner($headsUp, $user);
    }

    public function canCancel(EmployeeRequest $headsUp, User $user): bool
    {
        return $this->canConvert($headsUp, $user);
    }

    /**
     * Claim types a heads-up can be turned into: the company's active financial
     * types, other than business advances and settlements.
     *
     * @return array<int, string> id => name
     */
    public function claimTypeOptions(EmployeeRequest $headsUp): array
    {
        return EmployeeRequestType::query()
            ->where('company_id', $headsUp->company_id)
            ->where('is_active', true)
            ->where('is_financial', true)
            ->where(fn (Builder $types) => $types->whereNull('category')->orWhereNotIn('category', [AdvanceKinds::AdvanceCategory, AdvanceKinds::SettlementCategory]))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Creates the draft claim for an approved heads-up and links the two. The
     * employee then opens the draft to add receipts, tax and bank details and
     * submits it like any other claim.
     */
    public function convertToClaim(EmployeeRequest $headsUp, User $actor, int $claimTypeId, string $actualAmount, string $spentOn, ?string $nature): EmployeeRequest
    {
        return DB::transaction(function () use ($headsUp, $actor, $claimTypeId, $actualAmount, $spentOn, $nature): EmployeeRequest {
            $headsUp = EmployeeRequest::query()->with(['requestType', 'employee'])->lockForUpdate()->findOrFail($headsUp->getKey());

            if (! $this->canConvert($headsUp, $actor)) {
                throw new RuntimeException('Only the employee can turn their own approved heads-up into a claim, and only once.');
            }

            $claimType = EmployeeRequestType::query()->find($claimTypeId);
            if (! $claimType || ! array_key_exists($claimType->getKey(), $this->claimTypeOptions($headsUp))) {
                throw new RuntimeException('Choose one of this company\'s claim types.');
            }
            if (! is_numeric($actualAmount) || BigDecimal::of($actualAmount)->isLessThanOrEqualTo(0)) {
                throw new RuntimeException('Enter the amount you actually spent.');
            }
            if ($spentOn > self::today()) {
                throw new RuntimeException('The date spent cannot be in the future.');
            }

            $amount = (string) BigDecimal::of($actualAmount)->toScale(2);
            $claim = EmployeeRequest::query()->create([
                'company_id'        => $headsUp->company_id,
                'employee_id'       => $headsUp->employee_id,
                'request_type_id'   => $claimType->getKey(),
                'requested_by'      => $actor->getKey(),
                'currency_id'       => $headsUp->currency_id,
                'title'             => 'Claim: '.($headsUp->title ?: data_get($headsUp->payload, 'what_for')),
                'description'       => (string) data_get($headsUp->payload, 'what_for'),
                'nature_of_expense' => $nature,
                'billed_amount'     => $amount,
                'amount'            => $amount,
                'status'            => 'draft',
                'payload'           => [
                    'spent_on'          => $spentOn,
                    'heads_up_id'       => $headsUp->getKey(),
                    'heads_up_estimate' => (string) data_get($headsUp->payload, 'estimated_amount', '0'),
                ],
            ]);

            $headsUp->update(['payload' => array_merge((array) $headsUp->payload, [
                'claim_request_id' => $claim->getKey(),
                'converted_at'     => now()->toDateTimeString(),
            ])]);

            return $claim;
        });
    }

    /** The employee drops an approved heads-up that is no longer going to happen. */
    public function cancel(EmployeeRequest $headsUp, User $actor): EmployeeRequest
    {
        if (! $this->canCancel($headsUp, $actor)) {
            throw new RuntimeException('Only the employee can cancel their own heads-up, and only before it is turned into a claim.');
        }

        $headsUp->update([
            'status'  => 'withdrawn',
            'payload' => array_merge((array) $headsUp->payload, ['cancelled_at' => now()->toDateTimeString()]),
        ]);

        return $headsUp->fresh();
    }

    /**
     * For a claim that came from a heads-up: the estimate, the actual figure and
     * whether it is more than the allowed percentage above the estimate. Null for
     * any other request.
     *
     * @return array{estimated: string, actual: string, over_by_percent: float, flagged: bool, allowed_percent: float}|null
     */
    public function comparison(EmployeeRequest $claim): ?array
    {
        $estimate = data_get($claim->payload, 'heads_up_estimate');
        if (blank(data_get($claim->payload, 'heads_up_id')) || ! is_numeric($estimate) || (float) $estimate <= 0) {
            return null;
        }

        $actual = BigDecimal::of((string) ($claim->billed_amount ?? $claim->amount ?? 0));
        $estimated = BigDecimal::of((string) $estimate);
        $overBy = $actual->minus($estimated)->dividedBy($estimated, 4, RoundingMode::HALF_UP)->multipliedBy(100)->toFloat();
        $headsUp = EmployeeRequest::query()->with('requestType')->find((int) data_get($claim->payload, 'heads_up_id'));
        $allowed = ExpenseHeadsUp::overEstimatePercent($headsUp?->requestType);

        return [
            'estimated'       => (string) $estimated->toScale(2),
            'actual'          => (string) $actual->toScale(2),
            'over_by_percent' => round($overBy, 1),
            'flagged'         => $overBy > $allowed,
            'allowed_percent' => $allowed,
        ];
    }

    /**
     * Daily reminder: an approved heads-up whose expected date was yesterday or
     * earlier and that was never turned into a claim. Told the day after, then
     * weekly. In-app only; idempotent per heads-up and day.
     *
     * @return array{heads_ups: int, notified: int}
     */
    public function sendReminders(?int $companyId = null, ?string $date = null): array
    {
        $today = CarbonImmutable::parse($date ?? self::today(), AdvanceKinds::timezone())->startOfDay();

        $candidates = EmployeeRequest::query()
            ->where('status', 'approved')
            ->whereHas('requestType', fn (Builder $types) => $types->where('category', ExpenseHeadsUp::Category))
            ->whereHas('company', fn (Builder $companies) => $companies->where('is_active', true))
            ->whereNull('payload->claim_request_id')
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId))
            ->with(['employee.user'])
            ->orderBy('id')
            ->get();

        $due = 0;
        $notified = 0;

        foreach ($candidates as $headsUp) {
            $expectedOn = data_get($headsUp->payload, 'expected_on');
            if (blank($expectedOn)) {
                continue;
            }

            $daysLate = (int) CarbonImmutable::parse((string) $expectedOn, AdvanceKinds::timezone())->startOfDay()->diffInDays($today, false);
            if ($daysLate < 1 || ($daysLate - 1) % 7 !== 0) {
                continue;
            }

            $user = $headsUp->employee?->user;
            if (! $user) {
                continue;
            }

            $due++;
            $reference = $headsUp->reference ?: 'heads-up #'.$headsUp->id;
            $amount = number_format((float) data_get($headsUp->payload, 'estimated_amount', 0), 2);
            $notification = Notification::make()
                ->title('Did you make this expense?')
                ->body("You told HR you would spend about {$amount} on ".CarbonImmutable::parse((string) $expectedOn)->format('j M Y')." ({$reference}). If you did, open it and press Turn into claim. If not, cancel it.")
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->viewData(['employee_request' => $headsUp->getKey()]);

            $url = $headsUp->approvalSubjectUrl();
            $notified += $this->notifications->sendIdempotentNotification(
                $user,
                $url ? $notification->actions([Action::make('open')->label('Open')->url($url)->markAsRead()]) : $notification,
                'expense_heads_up_'.$headsUp->getKey().'_'.$today->toDateString(),
                ['event' => 'expense_heads_up_reminder', 'employee_request_id' => $headsUp->getKey()],
            ) ? 1 : 0;
        }

        return ['heads_ups' => $due, 'notified' => $notified];
    }

    private function isOwner(EmployeeRequest $headsUp, User $user): bool
    {
        return (int) $headsUp->requested_by === (int) $user->getKey()
            || ($headsUp->employee?->user_id !== null && (int) $headsUp->employee->user_id === (int) $user->getKey());
    }
}
