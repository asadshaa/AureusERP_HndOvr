<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Every company works on its own working schedule. ShipLine and OpenPort were
 * set up on the schedule owned by another company ("10-6", Truck It In), so
 * their HR could not see it, and a change by its owner moved all three.
 *
 * For each company whose employees use a schedule owned by a different
 * company, the schedule and its working-hour lines are copied for that company
 * (an existing copy with the same name is reused), then the company's
 * employees and leave requests are pointed at the copy. The hours, time zone
 * and public holidays stay the same, so no attendance figure changes.
 * Schedules that belong to no company are shared by design and are left alone.
 * Idempotent; the counts are written to the log.
 *
 * down() keeps the copies: nobody is moved back onto another company's schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['calendars', 'calendar_attendances', 'employees_employees'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $pairs = DB::table('employees_employees as employee')
            ->join('calendars as calendar', 'calendar.id', '=', 'employee.calendar_id')
            ->whereNotNull('calendar.company_id')
            ->whereColumn('calendar.company_id', '!=', 'employee.company_id')
            ->select('employee.company_id', 'employee.calendar_id')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            DB::transaction(function () use ($pair): void {
                $source = DB::table('calendars')->where('id', $pair->calendar_id)->first();

                $copyId = DB::table('calendars')
                    ->where('company_id', $pair->company_id)
                    ->where('name', $source->name)
                    ->whereNull('deleted_at')
                    ->value('id');

                if (! $copyId) {
                    $copy = (array) $source;
                    unset($copy['id']);
                    $copy['company_id'] = $pair->company_id;
                    $copy['created_at'] = $copy['updated_at'] = now();
                    $copyId = DB::table('calendars')->insertGetId($copy);

                    $lines = DB::table('calendar_attendances')
                        ->where('calendar_id', $source->id)
                        ->get()
                        ->map(function (object $line) use ($copyId): array {
                            $row = (array) $line;
                            unset($row['id']);
                            $row['calendar_id'] = $copyId;

                            return $row;
                        })
                        ->all();

                    if ($lines !== []) {
                        DB::table('calendar_attendances')->insert($lines);
                    }
                }

                $employees = DB::table('employees_employees')
                    ->where('company_id', $pair->company_id)
                    ->where('calendar_id', $source->id)
                    ->update(['calendar_id' => $copyId]);

                $leaves = Schema::hasTable('time_off_leaves')
                    ? DB::table('time_off_leaves')
                        ->where('company_id', $pair->company_id)
                        ->where('calendar_id', $source->id)
                        ->update(['calendar_id' => $copyId])
                    : 0;

                Log::info("Working schedule \"{$source->name}\" (#{$source->id}) copied for company #{$pair->company_id} as #{$copyId}: {$employees} employees and {$leaves} leave requests moved.");
            });
        }
    }

    public function down(): void
    {
        //
    }
};
