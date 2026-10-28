<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Yearly leave allocation policy, per company and leave type: the days
 * granted each 1 January (time-off:yearly-allocation) and how many unused
 * days may be carried forward from the previous year. A separate table
 * (not columns on the leave type) because leave types can be shared by all
 * companies (company_id NULL) while the policy is always per company.
 *
 * Seeds today's defaults for every company that has its own Annual / Casual
 * / Sick Leave types: 12 / 8 / 5 days (AllocateDefaultLeaveOnEmployeeCreated,
 * 25 days in total as on the employee dashboard), carry forward up to 6
 * Annual days (HR_PARITY_PLAN decision B3 proposal), nothing for Casual and
 * Sick. Existing rows are never changed.
 */
return new class extends Migration
{
    /** @var array<string, array{0: float, 1: float}> name => [annual days, carry-forward cap] */
    private const DEFAULTS = [
        'Annual Leave' => [12, 6],
        'Casual Leave' => [8, 0],
        'Sick Leave'   => [5, 0],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('time_off_allocation_policies')) {
            Schema::create('time_off_allocation_policies', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('leave_type_id')->constrained('time_off_leave_types')->cascadeOnDelete();
                $table->decimal('annual_days', 8, 2)->default(0);
                $table->decimal('carry_forward_cap', 8, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['company_id', 'leave_type_id'], 'time_off_allocation_policies_company_type_unique');
            });
        }

        $types = DB::table('time_off_leave_types')
            ->whereNotNull('company_id')
            ->whereNull('deleted_at')
            ->whereIn('name', array_keys(self::DEFAULTS))
            ->orderBy('id')
            ->get(['id', 'company_id', 'name']);

        foreach ($types as $type) {
            $exists = DB::table('time_off_allocation_policies')
                ->where('company_id', $type->company_id)
                ->where('leave_type_id', $type->id)
                ->exists();

            if ($exists) {
                continue;
            }

            [$annualDays, $carryForwardCap] = self::DEFAULTS[$type->name];

            DB::table('time_off_allocation_policies')->insert([
                'company_id'        => $type->company_id,
                'leave_type_id'     => $type->id,
                'annual_days'       => $annualDays,
                'carry_forward_cap' => $carryForwardCap,
                'is_active'         => true,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('time_off_allocation_policies');
    }
};
