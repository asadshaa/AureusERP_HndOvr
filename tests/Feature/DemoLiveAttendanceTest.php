<?php

/**
 * demo:attendance on a live company: only the missing days are filled
 * (existing rows, locked months, holidays, placeholders, employment dates
 * and today are left alone), every row is tracked in demo_records, a re-run
 * creates nothing, and demo:purge --company removes only those rows (a demo
 * day touched since is kept). A fresh company stands in for Truck It In.
 */

use App\Models\DemoRecord;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoActivitySeeder;
use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\AttendanceInterval;
use Webkul\Employee\Models\AttendanceMonthLock;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Company;

beforeEach(function () {
    // Thursday 2026-09-10, 13:00 in Karachi: the last day that may be filled is yesterday.
    $this->travelTo(CarbonImmutable::parse('2026-09-10 08:00:00', 'UTC'));
    config([
        'attendance.monthly_report.office_start'   => '10:00',
        'attendance.monthly_report.office_end'     => '18:00',
        'attendance.monthly_report.timezone'       => 'Asia/Karachi',
        'attendance.monthly_report.weekend_days'   => [6, 7],
        'attendance.notifications.late_check_in'   => false,
    ]);

    $this->company = Company::factory()->create(['name' => 'Demo Live Fill Test Co', 'is_active' => true]);
    $employee = fn (string $name, array $attributes = []): Employee => Employee::query()->create([
        'company_id'   => $this->company->id,
        'name'         => $name,
        'is_active'    => true,
        'time_zone'    => 'Asia/Karachi',
        'joining_date' => '2026-08-24',
        ...$attributes,
    ]);

    $this->ali = $employee('Live Ali');
    $this->placeholder = $employee('Sales Head (Placeholder)');
    $this->inactive = $employee('Former Faraz', ['is_active' => false]);
    $this->leaver = $employee('Leaving Lina', ['leaving_date' => '2026-09-02']);
    // No joining date: the day the employee was created (03 Sep in Karachi) counts.
    $this->newbie = $employee('New Nadia', ['joining_date' => null]);
    DB::table('employees_employees')->where('id', $this->newbie->id)->update(['created_at' => '2026-09-02 20:30:00']);

    // A real day already recorded, a finalised August and a public holiday on Friday 04 Sep.
    $this->realDayId = DB::table('employees_attendance_records')->insertGetId([
        'company_id'      => $this->company->id,
        'employee_id'     => $this->ali->id,
        'attendance_date' => '2026-09-01',
        'check_in'        => '2026-09-01 05:20:00',
        'check_out'       => '2026-09-01 13:00:00',
        'worked_hours'    => 7.6667,
        'status'          => 'present',
        'source'          => 'gps',
        'created_at'      => '2026-09-01 05:20:00',
        'updated_at'      => '2026-09-01 13:00:00',
    ]);
    $this->realIntervalId = DB::table('employees_attendance_intervals')->insertGetId([
        'company_id'           => $this->company->id,
        'employee_id'          => $this->ali->id,
        'attendance_record_id' => $this->realDayId,
        'check_in'             => '2026-09-01 05:20:00',
        'check_out'            => '2026-09-01 13:00:00',
        'source'               => 'gps',
        'created_at'           => '2026-09-01 05:20:00',
        'updated_at'           => '2026-09-01 13:00:00',
    ]);
    AttendanceMonthLock::query()->create(['company_id' => $this->company->id, 'month' => '2026-08', 'status' => AttendanceMonthLock::Finalised, 'finalised_at' => now()]);
    CalendarLeave::query()->create(['name' => 'Test holiday', 'time_type' => 'leave', 'date_from' => '2026-09-04', 'date_to' => '2026-09-04', 'company_id' => $this->company->id]);
});

/** @return array<int, int> the company's attendance record ids recorded in demo_records */
function liveDemoDayIds(Company $company): array
{
    return DemoRecord::query()->where('company_id', $company->id)->where('record_table', 'employees_attendance_records')
        ->pluck('record_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
}

it('fills only the missing work days, tracks every row and creates nothing on a re-run', function () {
    $stats = app(DemoActivitySeeder::class)->fillLiveAttendance($this->company, '2026-08-17', '2026-09-30');

    expect($stats['to'])->toBe('2026-09-09')
        ->and($stats['employees'])->toBe(3)
        ->and($stats['placeholders'])->toBe(['Sales Head (Placeholder)'])
        ->and($stats['existing'])->toBe(1)
        ->and($stats['locked_months'])->toBe(['2026-08'])
        ->and($stats['locked'])->toBe(12)
        ->and($stats['holidays'])->toBe(2)
        // Ali 02, 03, 07, 08, 09 Sep; Nadia 03, 07, 08, 09; Lina 01, 02.
        ->and($stats['days'] + $stats['absent'])->toBe(11)
        ->and($stats['days'])->toBeGreaterThan(5);

    $demoDays = AttendanceRecord::query()->whereIn('id', liveDemoDayIds($this->company))->get();
    $allDays = AttendanceRecord::query()->where('company_id', $this->company->id)->get();

    expect($demoDays)->toHaveCount($stats['days'])
        ->and($allDays->pluck('id')->diff($demoDays->pluck('id'))->values()->all())->toBe([$this->realDayId])
        ->and($demoDays->pluck('employee_id')->unique()->diff([$this->ali->id, $this->newbie->id, $this->leaver->id])->all())->toBe([]);

    foreach ($demoDays as $day) {
        $date = $day->attendance_date->toDateString();

        expect($date >= '2026-09-01' && $date <= '2026-09-09' && $date !== '2026-09-04')->toBeTrue()
            ->and($day->attendance_date->isWeekend())->toBeFalse()
            ->and($day->check_out)->not->toBeNull()
            ->and($day->scheduled_start->toDateTimeString())->toBe($date.' 05:00:00')
            ->and($day->check_in->copy()->setTimezone('Asia/Karachi')->toDateString())->toBe($date);
    }

    expect($demoDays->where('employee_id', $this->leaver->id)->every(fn (AttendanceRecord $day): bool => $day->attendance_date->toDateString() <= '2026-09-02'))->toBeTrue()
        ->and($demoDays->where('employee_id', $this->newbie->id)->every(fn (AttendanceRecord $day): bool => $day->attendance_date->toDateString() >= '2026-09-03'))->toBeTrue();

    $demoIntervals = DemoRecord::query()->where('company_id', $this->company->id)->where('record_table', 'employees_attendance_intervals')->pluck('record_id')->map(fn ($id): int => (int) $id);
    expect($demoIntervals)->toHaveCount($stats['intervals'])
        ->and(AttendanceInterval::query()->whereIn('attendance_record_id', $demoDays->pluck('id'))->pluck('id')->sort()->values()->all())->toBe($demoIntervals->sort()->values()->all());

    // The real day is untouched.
    expect((array) DB::table('employees_attendance_records')->where('id', $this->realDayId)->first(['check_in', 'check_out', 'updated_at']))
        ->toBe(['check_in' => '2026-09-01 05:20:00', 'check_out' => '2026-09-01 13:00:00', 'updated_at' => '2026-09-01 13:00:00'])
        ->and(AttendanceInterval::query()->where('attendance_record_id', $this->realDayId)->pluck('id')->all())->toBe([$this->realIntervalId]);

    // The second run (through the command) creates nothing.
    $counts = [AttendanceRecord::query()->count(), AttendanceInterval::query()->count(), DemoRecord::query()->count()];
    $this->artisan('demo:attendance', ['company' => 'Demo Live Fill Test', '--from' => '2026-08-17'])
        ->expectsOutputToContain('demo:purge --company="Demo Live Fill Test Co"')
        ->assertSuccessful();

    expect([AttendanceRecord::query()->count(), AttendanceInterval::query()->count(), DemoRecord::query()->count()])->toBe($counts);
});

it('purges only the tracked rows of the named live company and keeps the days touched since', function () {
    app(DemoActivitySeeder::class)->fillLiveAttendance($this->company, '2026-08-17', '2026-09-09');
    $demoDayIds = liveDemoDayIds($this->company);
    [$edited, $extended] = array_slice($demoDayIds, 0, 2);

    // A plain demo:purge is about the demo companies only.
    $this->artisan('demo:purge')->expectsConfirmation('Remove all demo data of ShipLine, OpenPort?', 'no')->assertSuccessful();
    expect(liveDemoDayIds($this->company))->toBe($demoDayIds);

    // HR edited one demo day; another got a punch that is not ours.
    DB::table('employees_attendance_records')->where('id', $edited)->update(['updated_at' => now()->addMinute()]);
    $foreignIntervalId = DB::table('employees_attendance_intervals')->insertGetId([
        'company_id'           => $this->company->id,
        'employee_id'          => AttendanceRecord::query()->whereKey($extended)->value('employee_id'),
        'attendance_record_id' => $extended,
        'check_in'             => now()->subHour(),
        'check_out'            => now(),
        'source'               => 'manual',
        'created_at'           => now(),
        'updated_at'           => now(),
    ]);
    $employeeCount = Employee::withTrashed()->where('company_id', $this->company->id)->count();

    $this->artisan('demo:purge', ['--company' => ['Demo Live Fill Test'], '--force' => true])
        ->expectsOutputToContain('2 demo day(s) were kept')
        ->assertSuccessful();

    expect(AttendanceRecord::query()->where('company_id', $this->company->id)->pluck('id')->sort()->values()->all())->toBe(collect([$this->realDayId, $edited, $extended])->sort()->values()->all())
        ->and(AttendanceInterval::query()->whereKey([$this->realIntervalId, $foreignIntervalId])->count())->toBe(2)
        ->and(liveDemoDayIds($this->company))->toBe(collect([$edited, $extended])->sort()->values()->all())
        ->and(Employee::withTrashed()->where('company_id', $this->company->id)->count())->toBe($employeeCount)
        ->and(DB::table('employees_attendance_records')->where('id', $this->realDayId)->value('updated_at'))->toBe('2026-09-01 13:00:00');
});
