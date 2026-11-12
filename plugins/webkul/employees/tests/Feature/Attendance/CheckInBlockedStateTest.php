<?php

/**
 * On a day of approved full-day leave (or a day recorded as leave / holiday)
 * check-in is refused. The My Attendance page and the home screens must say
 * so up front instead of inviting a punch that will be refused, and the page
 * state must agree with the refusal in every case.
 */

require_once __DIR__.'/GeoTestHelpers.php';

use App\Services\Dashboard\HomeDashboardContext;
use App\Services\Dashboard\RoleActionCenterService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\TimeOff\Database\Factories\LeaveTypeFactory;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    // Monday 5 Oct 2026, 13:00 in Karachi: three hours after the 10:00 office start.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'));
    $this->service = app(GeofencedAttendanceService::class);
});

function blockedLeave($employee, string $from, string $to, array $extra = [], string $state = 'validate_two', ?string $typeName = null): void
{
    $typeId = $typeName
        ? LeaveTypeFactory::new()->create(['company_id' => $employee->company_id, 'creator_id' => $employee->user_id, 'name' => $typeName])->id
        : null;

    DB::table('time_off_leaves')->insert($extra + [
        'employee_id'       => $employee->id,
        'company_id'        => $employee->company_id,
        'holiday_status_id' => $typeId,
        'state'             => $state,
        'request_date_from' => $from.' 00:00:00',
        'request_date_to'   => $to.' 00:00:00',
        'number_of_days'    => 1,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

it('tells the page that check-in is closed on a day of approved leave, with the leave type and dates', function () {
    $f = geoFixture();
    blockedLeave($f['employee'], '2026-10-04', '2026-10-06', typeName: 'Annual Leave');

    $state = $this->service->todayState($f['user']);

    expect($state['check_in_blocked'])->toBe(['type' => 'leave', 'name' => 'Annual Leave', 'from' => '4 Oct', 'to' => '6 Oct']);
});

it('tells the page that check-in is closed on a day recorded as a holiday', function () {
    $f = geoFixture();
    AttendanceRecord::query()->create([
        'company_id' => $f['company']->id, 'employee_id' => $f['employee']->id, 'attendance_date' => '2026-10-05',
        'status'     => 'holiday', 'source' => 'manual',
    ]);

    expect($this->service->todayState($f['user'])['check_in_blocked'])->toBe(['type' => 'holiday', 'name' => null, 'from' => null, 'to' => null]);
});

it('agrees with the refusal: the page is blocked exactly when the check-in is refused', function (string $state, array $extra, string $from, string $to, bool $blocked) {
    $f = geoFixture();
    blockedLeave($f['employee'], $from, $to, $extra, $state);

    $pageState = $this->service->todayState($f['user']);
    $result = $this->service->checkIn($f['user'], geoEvidence(), geoRequest());

    expect($pageState['check_in_blocked'] !== null)->toBe($blocked)
        ->and($result->result === Result::OnLeaveOrHoliday)->toBe($blocked)
        ->and($result->accepted)->toBe(! $blocked);
})->with([
    'approved full-day leave'        => ['validate_two', [], '2026-10-05', '2026-10-05', true],
    'approved leave over the day'    => ['validate_two', [], '2026-10-02', '2026-10-09', true],
    'leave still waiting approval'   => ['confirm', [], '2026-10-05', '2026-10-05', false],
    'approved half day'              => ['validate_two', ['request_unit_half' => 1], '2026-10-05', '2026-10-05', false],
    'approved short leave by hours'  => ['validate_two', ['request_unit_hours' => 1], '2026-10-05', '2026-10-05', false],
    'approved leave on another day'  => ['validate_two', [], '2026-10-06', '2026-10-07', false],
]);

it('does not mark a checked-in employee as blocked', function () {
    $f = geoFixture();
    $this->service->checkIn($f['user'], geoEvidence(), geoRequest());
    blockedLeave($f['employee'], '2026-10-05', '2026-10-05');

    expect($this->service->todayState($f['user'])['check_in_blocked'])->toBeNull();
});

it('shows approved leave on My Attendance instead of inviting a check-in', function () {
    $f = geoFixture();
    blockedLeave($f['employee'], '2026-10-04', '2026-10-06', typeName: 'Annual Leave');
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->assertSee('You are on approved leave today')
        ->assertSee('Annual Leave')
        ->assertSee('Contact HR if this is wrong')
        ->assertDontSee('Please check in now')
        ->assertDontSee('Ready to Check In')
        ->assertDontSee('Check In Now');
});

it('still invites a check-in on a normal day after the shift has started', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    Livewire::test(MyAttendance::class)
        ->assertSee('Ready to Check In')
        ->assertSee('Please check in now')
        ->assertSee('Check In Now')
        ->assertDontSee('approved leave today');
});

it('does not nudge an employee on approved leave from the home screens', function () {
    $f = geoFixture();
    blockedLeave($f['employee'], '2026-10-05', '2026-10-05', typeName: 'Casual Leave');
    $this->actingAs($f['user']);

    $context = app(HomeDashboardContext::class);
    $context->flush();
    $rows = collect($context->todayTasks()['mine'])->keyBy('key');

    expect($rows->get('check_in')['label'] ?? null)->toBe('You are on approved leave today')
        ->and($rows->get('check_in')['hint'] ?? null)->toBe('Casual Leave');

    $items = collect(app(RoleActionCenterService::class)->getPendingActionsFor($f['user'], (int) $f['company']->id));

    expect($items->pluck('id'))->not->toContain('emp_missing_checkin');
});
