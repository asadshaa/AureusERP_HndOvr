<?php

namespace Webkul\Employee\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeCodeSequence;
use Webkul\Support\Models\Company;

/**
 * Employee codes per company: PREFIX-0001, PREFIX-0002, ... The prefix comes
 * from the company's row in employees_employee_code_sequences (created on
 * first use from the company name's initials, e.g. "Truck It In (Pvt) Ltd"
 * -> TII). Codes already taken in the company are skipped, and the
 * sequence row is locked while a number is handed out, so two employees
 * created at the same time never get the same code.
 */
class EmployeeCodeGenerator
{
    public function sequenceFor(int $companyId): EmployeeCodeSequence
    {
        return EmployeeCodeSequence::query()->firstOrCreate(
            ['company_id' => $companyId],
            ['prefix' => $this->defaultPrefix($companyId), 'padding' => 4, 'next_number' => 1],
        );
    }

    /** The next free code of the company (and moves the sequence past it). */
    public function next(int $companyId): string
    {
        $this->sequenceFor($companyId);

        return DB::transaction(function () use ($companyId): string {
            $sequence = EmployeeCodeSequence::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $number = max(1, $sequence->next_number);

            while (Employee::withTrashed()
                ->where('company_id', $companyId)
                ->where('employee_number', $sequence->format($number))
                ->exists()) {
                $number++;
            }

            $sequence->update(['next_number' => $number + 1]);

            return $sequence->format($number);
        });
    }

    /** Fill an empty employee_number before an employee is saved; a code already set is never changed. */
    public function assignIfEmpty(Employee $employee): void
    {
        if (! $employee->company_id || filled($employee->employee_number)) {
            return;
        }

        $employee->employee_number = $this->next((int) $employee->company_id);
    }

    /**
     * Give every employee of the company (or all companies) without a code
     * one, oldest first. Only empty codes are filled.
     *
     * @return array<int, array{employee_id: int, company_id: int, name: string, code: string}>
     */
    public function backfill(?int $companyId = null, bool $dryRun = false): array
    {
        $assigned = [];
        $preview = [];

        $employees = Employee::withTrashed()
            ->whereNotNull('company_id')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->where(fn ($query) => $query->whereNull('employee_number')->orWhere('employee_number', ''))
            ->orderBy('company_id')
            ->orderBy('id')
            ->get(['id', 'company_id', 'name', 'employee_number']);

        foreach ($employees as $employee) {
            $company = (int) $employee->company_id;

            if ($dryRun) {
                $code = $this->previewNext($company, $preview);
            } else {
                $code = $this->next($company);

                // A plain update: no model events, so nothing but the code changes.
                $updated = Employee::withTrashed()
                    ->whereKey($employee->id)
                    ->where(fn ($query) => $query->whereNull('employee_number')->orWhere('employee_number', ''))
                    ->toBase()
                    ->update(['employee_number' => $code, 'updated_at' => now()]);

                if ($updated === 0) {
                    continue;
                }
            }

            $assigned[] = ['employee_id' => (int) $employee->id, 'company_id' => $company, 'name' => (string) $employee->name, 'code' => $code];
        }

        return $assigned;
    }

    /** Initials of the company name without legal suffixes: "Truck It In (Pvt) Ltd" -> TII, "ShipLine" -> SL. */
    public function defaultPrefix(int $companyId): string
    {
        $name = (string) Company::withTrashed()->whereKey($companyId)->value('name');
        $name = preg_replace('/\(.*?\)/', ' ', $name);
        $name = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', (string) $name);

        $words = collect(preg_split('/[^A-Za-z0-9]+/', (string) $name, -1, PREG_SPLIT_NO_EMPTY))
            ->reject(fn (string $word): bool => in_array(strtolower($word), ['pvt', 'private', 'ltd', 'limited', 'llc', 'inc', 'co', 'company', 'smc', 'plc', 'the', 'and'], true));

        $prefix = strtoupper($words->map(fn (string $word): string => $word[0])->implode(''));

        if (strlen($prefix) < 2 && $words->isNotEmpty()) {
            $prefix = strtoupper(substr((string) $words->first(), 0, 3));
        }

        return substr($prefix, 0, 10) ?: 'EMP';
    }

    /** @param  array<int, int>  $preview */
    private function previewNext(int $companyId, array &$preview): string
    {
        $sequence = EmployeeCodeSequence::query()->where('company_id', $companyId)->first()
            ?? new EmployeeCodeSequence(['company_id' => $companyId, 'prefix' => $this->defaultPrefix($companyId), 'padding' => 4, 'next_number' => 1]);

        $number = $preview[$companyId] ?? max(1, $sequence->next_number);

        while (Employee::withTrashed()->where('company_id', $companyId)->where('employee_number', $sequence->format($number))->exists()) {
            $number++;
        }

        $preview[$companyId] = $number + 1;

        return $sequence->format($number);
    }
}
