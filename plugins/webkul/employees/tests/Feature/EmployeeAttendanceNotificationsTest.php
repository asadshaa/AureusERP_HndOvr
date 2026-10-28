<?php

/**
 * Employee attendance notifications: the check-in reminder (10:15 / 14:15
 * after a first half off), the late check-in notice with the grace balance,
 * the forgotten check-out notice, the negative grace balance alert (once a
 * month) and the 10:30 line-manager digest of their own team -- each sent
 * once, never on weekends / holidays / approved leave, company by company.
 */

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Employee\Models\AttendancePolicy;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\EmployeeAttendanceNotifier;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Models\LeaveType;

beforeEach(function () {
    config([
        'attendance.monthly_report.office_start'       => '10:00',
        'attendance.monthly_report.office_end'         => '18:00',
        'attendance.monthly_report.late_grace_minutes' => 0,
        'attendance.monthly_report.timezone'           => 'Asia/Karachi',
        'attendance.monthly_report.weekend_days'       => [6, 7],
        'attendance.time_bank.monthly_grace_minutes'   => 0,
        'attendance.time_bank.carry_over'              => false,
    ]);
    // Wednesday 28 Oct 2026, 10:15 in Karachi.
    eanAt('2026-10-28 10:15');
});

function eanAt(string $local): void
{
    // A UTC test clock: Carbon reads model datetimes in the test clock's time zone.
    test()->travelTo(CarbonImmutable::parse($local, 'Asia/Karachi')->utc());
}

function eanCompany(string $name = 'Notify Co', int $graceMinutes = 0): Company
{
    $company = Company::factory()->create(['name' => $name, 'is_active' => true]);
    AttendancePolicy::query()->create(['company_id' => $company->id, 'monthly_grace_minutes' => $graceMinutes, 'daily_extra_credit_cap_minutes' => 60, 'carry_over' => false]);

    return $company;
}

function eanUser(Company $company): User
{
    $user = User::factory()->create(['default_company_id' => $company->id, 'is_active' => true, 'resource_permission' => PermissionType::INDIVIDUAL]);
    $user->allowedCompanies()->syncWithoutDetaching([$company->id]);

    return $user;
}

/** An employee who joined on the 28th (earlier days of the month are not counted), with a linked user unless $withUser is false. */
function eanEmployee(Company $company, string $name, bool $withUser = true, array $attributes = []): Employee
{
    return Employee::query()->create($attributes + [
        'company_id'   => $company->id,
        'name'         => $name,
        'is_active'    => true,
        'time_zone'    => 'Asia/Karachi',
        'joining_date' => '2026-10-28',
        'user_id'      => $withUser ? eanUser($company)->id : null,
    ]);
}

/** Punch in Karachi local time ("H:i"). */
function eanPunch(Employee $employee, string $date, ?string $in, ?string $out, array $extra = []): AttendanceRecord
{
    $utc = fn (?string $time): ?string => $time === null ? null : CarbonImmutable::parse($date.' '.$time, 'Asia/Karachi')->setTimezone('UTC')->toDateTimeString();

    return AttendanceRecord::query()->create($extra + [
        'company_id'      => $employee->company_id,
        'employee_id'     => $employee->id,
        'attendance_date' => $date,
        'check_in'        => $utc($in),
        'check_out'       => $utc($out),
        'status'          => 'present',
        'source'          => 'manual',
    ]);
}

function eanLeave(Employee $employee, string $typeName, string $date, string $state = 'validate_two', array $extra = []): void
{
    $type = LeaveType::query()->firstOrCreate(['company_id' => $employee->company_id, 'name' => $typeName], ['is_active' => true]);

    DB::table('time_off_leaves')->insert($extra + [
        'employee_id'       => $employee->id,
        'company_id'        => $employee->company_id,
        'holiday_status_id' => $type->id,
        'state'             => $state,
        'request_date_from' => $date.' 00:00:00',
        'request_date_to'   => $date.' 00:00:00',
        'number_of_days'    => 1,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

/** @return array<int, string> notification titles of the employee's user, in order */
function eanTitles(Employee $employee): array
{
    return $employee->fresh()->user?->notifications()->orderBy('created_at')->orderBy('id')->get()
        ->map(fn ($notification): string => (string) $notification->data['title'])->all() ?? [];
}

function eanBodies(Employee $employee, string $title): array
{
    return $employee->fresh()->user->notifications()->get()
        ->filter(fn ($notification): bool => $notification->data['title'] === $title)
        ->map(fn ($notification): string => (string) $notification->data['body'])->values()->all();
}

// ---------------------------------------------------------------------
// 1. Check-in reminder
// ---------------------------------------------------------------------
it('reminds at 10:15 everyone due without a check-in, once, and skips approved leave, On-Duty and first-half off', function () {
    $company = eanCompany();
    $missing = eanEmployee($company, 'Missing');
    $checkedIn = eanEmployee($company, 'Checked In');
    $onLeave = eanEmployee($company, 'On Leave');
    $pending = eanEmployee($company, 'Pending Leave');
    $onDuty = eanEmployee($company, 'Field Visit');
    $firstHalf = eanEmployee($company, 'Morning Off');
    $secondHalf = eanEmployee($company, 'Afternoon Off');
    $noUser = eanEmployee($company, 'No User', false);

    eanPunch($checkedIn, '2026-10-28', '09:55', null);
    eanLeave($onLeave, 'Annual', '2026-10-28');
    eanLeave($pending, 'Casual', '2026-10-28', 'confirm');
    eanLeave($onDuty, 'On Duty', '2026-10-28');
    eanLeave($firstHalf, 'Half Day', '2026-10-28', 'validate_two', ['request_unit_half' => 1, 'request_date_from_period' => 'morning']);
    eanLeave($secondHalf, 'Half Day', '2026-10-28', 'validate_two', ['request_unit_half' => 1, 'request_date_from_period' => 'afternoon']);

    $this->artisan('hr:remind-missing-check-in', ['--company' => $company->id])
        ->expectsOutputToContain('3 sent')
        ->assertSuccessful();
    $this->artisan('hr:remind-missing-check-in', ['--company' => $company->id])
        ->expectsOutputToContain('0 sent for 1 company(ies), 3 already sent')
        ->assertSuccessful();

    expect(eanTitles($missing))->toBe(["You haven't checked in today"])
        ->and(eanBodies($missing, "You haven't checked in today")[0])->toBe('You were due at 10:00 today (Wednesday, 28 Oct 2026). Please check in now, or request a correction or time off if you are away.')
        ->and(eanTitles($pending))->toBe(["You haven't checked in today"])
        ->and(eanTitles($secondHalf))->toBe(["You haven't checked in today"])
        ->and(eanTitles($checkedIn))->toBe([])
        ->and(eanTitles($onLeave))->toBe([])
        ->and(eanTitles($onDuty))->toBe([])
        ->and(eanTitles($firstHalf))->toBe([]);

    // First half off: due at 14:00, reminded at 14:15 (not at 14:10).
    eanAt('2026-10-28 14:10');
    $this->artisan('hr:remind-missing-check-in', ['--company' => $company->id])->assertSuccessful();
    expect(eanTitles($firstHalf))->toBe([]);

    eanAt('2026-10-28 14:15');
    $this->artisan('hr:remind-missing-check-in', ['--company' => $company->id])
        ->expectsOutputToContain('1 sent')
        ->assertSuccessful();
    expect(eanBodies($firstHalf, "You haven't checked in today")[0])->toStartWith('You were due at 14:00 today')
        ->and(eanTitles($missing))->toHaveCount(1);
});

it('does not remind before 10:15, on a weekend, on a public holiday, after office hours or when switched off', function () {
    $company = eanCompany();
    $employee = eanEmployee($company, 'Off Day', true, ['joining_date' => '2026-10-01']);

    eanAt('2026-10-28 10:14');
    $this->artisan('hr:remind-missing-check-in', ['--company' => $company->id])->assertSuccessful();

    eanAt('2026-10-31 10:30'); // Saturday
    $this->artisan('hr:remind-missing-check-in', ['--company' => $company->id])->assertSuccessful();

    CalendarLeave::query()->create(['name' => 'Iqbal Day', 'time_type' => 'leave', 'date_from' => '2026-11-09', 'date_to' => '2026-11-09', 'company_id' => $company->id]);
    eanAt('2026-11-09 10:30'); // Monday, public holiday
    $this->artisan('hr:remind-missing-check-in', ['--company' => $company->id])->assertSuccessful();

    eanAt('2026-10-28 18:15');
    $this->artisan('hr:remind-missing-check-in', ['--company' => $company->id])->assertSuccessful();

    config(['attendance.notifications.check_in_reminder' => false]);
    eanAt('2026-10-29 10:30');
    $this->artisan('hr:remind-missing-check-in', ['--company' => $company->id])->assertSuccessful();

    expect(eanTitles($employee))->toBe([]);

    config(['attendance.notifications.check_in_reminder' => true]);
    $this->artisan('hr:remind-missing-check-in', ['--company' => $company->id])->assertSuccessful();
    expect(eanTitles($employee))->toBe(["You haven't checked in today"]);
});

it('keeps reminders inside the company: another company is not touched and a user outside the company is not told', function () {
    $company = eanCompany('Notify A');
    $other = eanCompany('Notify B');
    $mine = eanEmployee($company, 'Mine');
    $theirs = eanEmployee($other, 'Theirs');
    // An employee of company A whose user has since been moved to company B only.
    $outsider = eanEmployee($company, 'Outsider');
    $outsider->user->allowedCompanies()->detach($company->id);
    DB::table('users')->where('id', $outsider->user_id)->update(['default_company_id' => $other->id]);

    $this->artisan('hr:remind-missing-check-in', ['--company' => $company->id])->assertSuccessful();

    expect(eanTitles($mine))->toBe(["You haven't checked in today"])
        ->and(eanTitles($theirs))->toBe([])
        ->and(eanTitles($outsider))->toBe([]);
});

// ---------------------------------------------------------------------
// 2. Late check-in
// ---------------------------------------------------------------------
it('tells a late employee right after check-in how late they were and the grace balance left, once a day', function () {
    $company = eanCompany('Late Co', 60);
    $late = eanEmployee($company, 'Late Comer');
    $onTime = eanEmployee($company, 'On Time');

    eanAt('2026-10-28 09:58');
    eanPunch($onTime, '2026-10-28', '09:58', null);

    eanAt('2026-10-28 10:25');
    $record = eanPunch($late, '2026-10-28', '10:25', null);

    expect(eanBodies($late, 'You checked in late'))->toBe(['You checked in 25 min late. Grace balance: 35 min left this month.'])
        ->and(eanTitles($onTime))->toBe([]);

    // Check out and in again (a second interval): still one notice.
    eanAt('2026-10-28 13:00');
    $record->update(['check_out' => CarbonImmutable::parse('2026-10-28 13:00', 'Asia/Karachi')->setTimezone('UTC')]);
    app(EmployeeAttendanceNotifier::class)->notifyLateCheckIn($record->id);

    expect(eanTitles($late))->toBe(['You checked in late']);
});

it('says a negative grace balance will show as a shortfall and raises the monthly alert once', function () {
    $company = eanCompany('Short Co', 10);
    $employee = eanEmployee($company, 'Very Late');

    eanAt('2026-10-28 10:25');
    eanPunch($employee, '2026-10-28', '10:25', null);

    expect(eanBodies($employee, 'You checked in late'))->toBe(["You checked in 25 min late. Grace balance: \u{2212}15 min this month; this will show as a shortfall."])
        ->and(eanBodies($employee, 'Your grace balance is below zero'))->toBe(["Your grace balance is \u{2212}15 min this month (October 2026); this will show as a shortfall."]);

    // The evening run does not repeat the month's alert.
    eanAt('2026-10-28 20:00');
    $this->artisan('hr:grace-balance-alerts', ['--company' => $company->id])
        ->expectsOutputToContain('0 sent')
        ->assertSuccessful();

    expect(eanBodies($employee, 'Your grace balance is below zero'))->toHaveCount(1);
});

it('measures lateness from 14:00 after a first half off and ignores back-dated or imported check-ins', function () {
    $company = eanCompany('Half Co', 0);
    $halfDay = eanEmployee($company, 'Morning Off');
    $backDated = eanEmployee($company, 'Back Dated', true, ['joining_date' => '2026-10-01']);
    eanLeave($halfDay, 'Half Day', '2026-10-28', 'validate_two', ['request_unit_half' => 1, 'request_date_from_period' => 'morning']);

    eanAt('2026-10-28 14:20');
    eanPunch($halfDay, '2026-10-28', '14:20', null);
    // HR enters yesterday's late day, and today's 10:30 check-in only at 14:20 (older than the max age).
    eanPunch($backDated, '2026-10-27', '11:00', '18:00');
    config(['attendance.notifications.late_check_in_max_age_minutes' => 120]);
    eanPunch($backDated, '2026-10-28', '10:30', null);

    expect(eanBodies($halfDay, 'You checked in late')[0])->toStartWith('You checked in 20 min late.')
        ->and(eanTitles($backDated))->toBe([]);
});

// ---------------------------------------------------------------------
// 3. Forgotten check-out
// ---------------------------------------------------------------------
it('tells the employee once that they did not check out when the forgotten shift is flagged', function () {
    $company = eanCompany('Forgot Co');
    $employee = eanEmployee($company, 'Forgetful', true, ['joining_date' => '2026-10-01']);

    eanAt('2026-10-28 09:00');
    eanPunch($employee, '2026-10-27', '09:30', null, ['source' => 'gps', 'verification_status' => 'verified']);

    expect(app(GeofencedAttendanceService::class)->flagForgottenShifts())->toBeGreaterThanOrEqual(1);
    app(GeofencedAttendanceService::class)->flagForgottenShifts();

    expect(eanBodies($employee, "You didn't check out"))->toBe(["You didn't check out on Tuesday, 27 Oct 2026 (checked in at 09:30). Please check out or request a correction."]);
});

// ---------------------------------------------------------------------
// 5. Negative grace balance (evening run)
// ---------------------------------------------------------------------
it('alerts once a month when the settled grace balance is below zero, not for a positive balance', function () {
    $company = eanCompany('Balance Co', 0);
    $short = eanEmployee($company, 'Short Hours', true, ['joining_date' => '2026-10-26']);
    $fine = eanEmployee($company, 'Full Hours', true, ['joining_date' => '2026-10-26']);

    eanAt('2026-10-28 09:00');
    foreach (['2026-10-26', '2026-10-27'] as $date) {
        eanPunch($fine, $date, '10:00', '18:00');
    }
    eanPunch($short, '2026-10-26', '10:00', '18:00');
    eanPunch($short, '2026-10-27', '10:00', '17:30');

    // During the day: today is not settled yet; the balance up to yesterday is -30.
    eanAt('2026-10-28 11:00');
    eanPunch($fine, '2026-10-28', '10:00', '18:00');
    eanPunch($short, '2026-10-28', '10:00', '18:00');

    eanAt('2026-10-28 20:00');
    $this->artisan('hr:grace-balance-alerts', ['--company' => $company->id])
        ->expectsOutputToContain('1 sent')
        ->assertSuccessful();
    $this->artisan('hr:grace-balance-alerts', ['--company' => $company->id])
        ->expectsOutputToContain('0 sent')
        ->assertSuccessful();

    expect(eanBodies($short, 'Your grace balance is below zero'))->toBe(["Your grace balance is \u{2212}30 min this month (October 2026); this will show as a shortfall."])
        ->and(eanTitles($fine))->toBe([]);
});

// ---------------------------------------------------------------------
// 6. Line manager digest
// ---------------------------------------------------------------------
it('sends each line manager the late and absent people of their own team at 10:30, once, and nothing when all is well', function () {
    $company = eanCompany('Team Co');
    $manager = eanEmployee($company, 'Manager One');
    $calmManager = eanEmployee($company, 'Manager Two');
    $absent = eanEmployee($company, 'Zed Absent', false, ['parent_id' => $manager->id]);
    $late = eanEmployee($company, 'Amy Late', false, ['parent_id' => $manager->id]);
    $onTime = eanEmployee($company, 'Bob On Time', false, ['parent_id' => $manager->id]);
    $morningOff = eanEmployee($company, 'Cat Morning Off', false, ['parent_id' => $manager->id]);
    $onLeave = eanEmployee($company, 'Dan On Leave', false, ['parent_id' => $manager->id]);
    $fineReport = eanEmployee($company, 'Eve Fine', false, ['parent_id' => $calmManager->id]);
    $notMine = eanEmployee($company, 'Fay Other Team', false);

    eanAt('2026-10-28 10:30');
    eanPunch($late, '2026-10-28', '10:20', null);
    eanPunch($onTime, '2026-10-28', '09:59', null);
    eanPunch($fineReport, '2026-10-28', '09:50', null);
    eanLeave($morningOff, 'Half Day', '2026-10-28', 'validate_two', ['request_unit_half' => 1, 'request_date_from_period' => 'morning']);
    eanLeave($onLeave, 'Annual', '2026-10-28');

    // A manager in another company with an absent report: not part of this run.
    $other = eanCompany('Team Other');
    $otherManager = eanEmployee($other, 'Other Manager');
    eanEmployee($other, 'Other Absent', false, ['parent_id' => $otherManager->id]);

    // HR (company-wide) gets nothing from this digest.
    $hrRole = Role::query()->firstOrCreate(['name' => 'ean_hr_viewer', 'guard_name' => 'web']);
    $hrRole->givePermissionTo(Permission::query()->firstOrCreate(['name' => HrPermissions::ViewAllRecords, 'guard_name' => 'web']));
    $hr = eanUser($company);
    $hr->assignRole($hrRole);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('hr:line-manager-attendance-digest', ['--company' => $company->id])
        ->expectsOutputToContain('1 notification(s) sent')
        ->assertSuccessful();
    $this->artisan('hr:line-manager-attendance-digest', ['--company' => $company->id])
        ->expectsOutputToContain('0 notification(s) sent for 1 company(ies), 1 already sent')
        ->assertSuccessful();

    $notifications = $manager->fresh()->user->notifications()->get();
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->data['title'])->toBe('Your team today: 1 absent, 1 late')
        ->and($notifications->first()->data['body'])->toBe('Wednesday, 28 Oct 2026 (Team Co). Absent (1): Zed Absent. Late (1): Amy Late (20m).')
        ->and($notifications->first()->data['body'])->not->toContain('Fay Other Team')
        ->and(eanTitles($calmManager))->toBe([])
        ->and(eanTitles($otherManager))->toBe([])
        ->and($hr->notifications()->count())->toBe(0);
});

it('skips the line manager digest on a weekend', function () {
    $company = eanCompany('Weekend Team');
    $manager = eanEmployee($company, 'Weekend Manager');
    eanEmployee($company, 'Weekend Report', false, ['parent_id' => $manager->id]);

    eanAt('2026-10-31 10:30');
    $this->artisan('hr:line-manager-attendance-digest', ['--company' => $company->id])
        ->expectsOutputToContain('0 notification(s) sent for 0 company(ies)')
        ->assertSuccessful();

    expect(eanTitles($manager))->toBe([]);
});
