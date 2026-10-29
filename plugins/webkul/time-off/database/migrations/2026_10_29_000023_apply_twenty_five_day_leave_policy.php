<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave policy decision of 2026-10-29: 25 days a year, Annual 12, Sick 8 and
 * Casual 5 (was Casual 8 and Sick 5). Half day no longer counts as leave;
 * that is code only (LeaveBalanceService): its requires_allocation "no" set
 * by 000022 is still right and the link to Casual Leave was never stored.
 *
 *  1. Yearly allocation policies still on the old defaults: Casual Leave
 *     8 -> 5 and Sick Leave 5 -> 8 days. Carry-forward caps are unchanged
 *     (Annual 6, Sick and Casual 0). A policy HR set to another value is
 *     left alone.
 *  2. 2026 allocations created by the hire-time allocation or the yearly
 *     allocation (company-owned type, approved, regular, 1 Jan - 31 Dec
 *     2026, named "<type> 2026", no notes or the yearly "Yearly allocation
 *     2026:" notes, nothing carried, still on the old full-year number):
 *     Sick Leave 5 -> 8; Casual Leave 8 -> 5, but never below the days
 *     already used (approved + pending Casual Leave starting in 2026), and
 *     unchanged when 8 or more are used. Each changed allocation gets a line
 *     in its notes, so the change stays auditable.
 *
 * Idempotent: a changed row no longer matches the old number.
 */
return new class extends Migration
{
    private const YEAR = 2026;

    private const MARKER = 'Leave policy of 2026-10-29 (25 days: Annual 12, Sick 8, Casual 5)';

    /** @var array<string, array{0: float, 1: float}> leave type => [old days, new days] */
    private const CHANGES = [
        'Sick Leave'   => [5, 8],
        'Casual Leave' => [8, 5],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('time_off_allocation_policies') || ! Schema::hasTable('time_off_leave_allocations')) {
            return;
        }

        foreach (self::CHANGES as $typeName => [$oldDays, $newDays]) {
            $this->policies($typeName, $oldDays)->update(['annual_days' => $newDays, 'updated_at' => now()]);

            foreach ($this->allocations($typeName, $oldDays) as $allocation) {
                // A decrease never goes below the days already used (nor above the old number).
                $days = $newDays < $oldDays ? min($oldDays, max($newDays, $this->daysUsed($allocation))) : $newDays;
                if (abs($days - $oldDays) < 0.001) {
                    continue;
                }

                $note = sprintf(
                    '%s: %s %s -> %s day(s)%s.',
                    self::MARKER,
                    $typeName,
                    $this->format($oldDays),
                    $this->format($days),
                    $days > $newDays ? ' (not below the '.$this->format($days).' day(s) already used)' : '',
                );

                DB::table('time_off_leave_allocations')->where('id', $allocation->id)->update([
                    'number_of_days' => $days,
                    'notes'          => trim(((string) $allocation->notes)."\n".$note),
                    'updated_at'     => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('time_off_allocation_policies') || ! Schema::hasTable('time_off_leave_allocations')) {
            return;
        }

        foreach (self::CHANGES as $typeName => [$oldDays, $newDays]) {
            $this->policies($typeName, $newDays)->update(['annual_days' => $oldDays, 'updated_at' => now()]);

            $changed = DB::table('time_off_leave_allocations')
                ->where('notes', 'like', '%'.self::MARKER.': '.$typeName.' %')
                ->get(['id', 'notes']);

            foreach ($changed as $allocation) {
                $notes = collect(preg_split('/\R/', (string) $allocation->notes))
                    ->reject(fn (string $line): bool => str_starts_with($line, self::MARKER))
                    ->implode("\n");

                DB::table('time_off_leave_allocations')->where('id', $allocation->id)->update([
                    'number_of_days' => $oldDays,
                    'notes'          => $notes !== '' ? $notes : null,
                    'updated_at'     => now(),
                ]);
            }
        }
    }

    private function policies(string $typeName, float $annualDays): Builder
    {
        return DB::table('time_off_allocation_policies')
            ->where('annual_days', $annualDays)
            ->whereIn('leave_type_id', DB::table('time_off_leave_types')->whereNotNull('company_id')->where('name', $typeName)->select('id'));
    }

    /** @return Collection<int, object> */
    private function allocations(string $typeName, float $oldDays): Collection
    {
        return DB::table('time_off_leave_allocations as allocation')
            ->join('time_off_leave_types as type', 'type.id', '=', 'allocation.holiday_status_id')
            ->whereNotNull('type.company_id')
            ->where('type.name', $typeName)
            ->where('allocation.name', $typeName.' '.self::YEAR)
            ->where('allocation.state', 'validate_two')
            ->where('allocation.allocation_type', 'regular')
            ->whereDate('allocation.date_from', self::YEAR.'-01-01')
            ->whereDate('allocation.date_to', self::YEAR.'-12-31')
            ->where('allocation.number_of_days', $oldDays)
            ->where(fn ($query) => $query->whereNull('allocation.expiring_carryover_days')->orWhere('allocation.expiring_carryover_days', 0))
            ->where(fn ($query) => $query->whereNull('allocation.notes')
                ->orWhere('allocation.notes', '')
                ->orWhere('allocation.notes', 'like', 'Yearly allocation '.self::YEAR.':%'))
            ->orderBy('allocation.id')
            ->get(['allocation.id', 'allocation.employee_id', 'allocation.holiday_status_id', 'allocation.notes']);
    }

    /** Approved and pending days of the allocation's type, starting in the year. */
    private function daysUsed(object $allocation): float
    {
        return (float) DB::table('time_off_leaves')
            ->where('employee_id', $allocation->employee_id)
            ->where('holiday_status_id', $allocation->holiday_status_id)
            ->whereIn('state', ['validate_two', 'confirm', 'validate_one'])
            ->whereRaw('YEAR(COALESCE(request_date_from, date_from)) = ?', [self::YEAR])
            ->sum('number_of_days');
    }

    private function format(float $days): string
    {
        return rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.');
    }
};
