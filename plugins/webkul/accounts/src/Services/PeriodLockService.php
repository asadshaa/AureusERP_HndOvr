<?php

namespace Webkul\Account\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Account\Models\PeriodLock;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Month-end / period-close guard. A company with a lock set can't have new
 * journal entries posted on or before the locked date -- protects a period
 * that's already been reconciled and reported on from a backdated entry
 * silently changing numbers that were already signed off. Deliberately a
 * single "locked through this date" per company rather than a full
 * fiscal-calendar model: this app has no fiscal-year/period concept today,
 * and a single rolling lock date is the same thing every small-business
 * bookkeeping tool actually uses in practice.
 */
class PeriodLockService
{
    public function lockedThrough(int $companyId): ?CarbonInterface
    {
        return PeriodLock::query()->where('company_id', $companyId)->value('locked_through_date');
    }

    /**
     * Throws if $date falls on or before the company's current lock date.
     * Every direct posting chokepoint (confirmMove, BankJournalService::post,
     * ManualAdjustmentService::post, MigrationJournalService::createJournal)
     * must call this before writing state=posted -- there is no single
     * shared low-level "write posted" function these all funnel through.
     */
    public function assertNotLocked(int $companyId, CarbonInterface|string|null $date): void
    {
        $lockedThrough = $this->lockedThrough($companyId);
        if (! $lockedThrough || $date === null) {
            return;
        }

        $date = is_string($date) ? Carbon::parse($date) : $date;

        if ($date->lte($lockedThrough)) {
            throw new RuntimeException(
                "Cannot post: {$date->toDateString()} falls in a closed accounting period (locked through {$lockedThrough->toDateString()}). Ask whoever manages period locks to reopen it if this entry genuinely needs to land there."
            );
        }
    }

    public function lockThrough(Company $company, User $actor, CarbonInterface $date): PeriodLock
    {
        $lock = PeriodLock::query()->firstOrNew(['company_id' => $company->id]);
        $lock->fill([
            'locked_through_date' => $date,
            'locked_by_user_id'   => $actor->id,
            'locked_at'           => now(),
        ]);
        $lock->save();

        return $lock;
    }

    public function unlock(Company $company): void
    {
        $lock = PeriodLock::query()->where('company_id', $company->id)->first();
        if (! $lock || $lock->locked_through_date === null) {
            return;
        }

        $lock->fill([
            'locked_through_date' => null,
            'locked_by_user_id'   => Auth::id(),
            'locked_at'           => now(),
        ])->save();
    }
}
