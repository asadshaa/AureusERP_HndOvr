<?php

require_once __DIR__.'/../../plugins/webkul/employees/tests/Feature/Attendance/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Enums\AttendanceVerificationStatus as Status;
use Webkul\Employee\Filament\Pages\MyAttendance;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;

function realWorldPayload(array $overrides = []): array
{
    return $overrides + [
        'latitude'          => GEO_LAT,
        'longitude'         => GEO_LNG,
        'accuracy'          => 12.5,
        'captured_at'       => CarbonImmutable::now('UTC')->getTimestampMs(),
        'client_request_id' => (string) Str::uuid(),
    ];
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
});

it('handles an insecure context failure when browser accesses via insecure LAN IP', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    $clientRequestId = (string) Str::uuid();

    Livewire::test(MyAttendance::class)
        ->call('reportLocationFailure', 'check_in', 'insecure_context', $clientRequestId)
        ->assertSet('outcome.ok', false)
        ->assertSet('outcome.result', 'insecure_context')
        ->assertSee('Location can only be used over a secure (https) connection');

    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->count())->toBe(0);

    $verification = AttendanceVerification::query()
        ->where('employee_id', $f['employee']->id)
        ->where('client_request_id', $clientRequestId)
        ->sole();

    expect($verification->accepted)->toBeFalse()
        ->and($verification->result)->toBe(Result::InsecureContext)
        ->and($verification->metadata['client_error_code'])->toBe('insecure_context');
});

it('detects a rooted phone reporting suspiciously tight 0.5m accuracy and flags it for review', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    $clientRequestId = (string) Str::uuid();
    $payload = realWorldPayload([
        'accuracy'          => 0.5,
        'client_request_id' => $clientRequestId,
    ]);

    Livewire::test(MyAttendance::class)
        ->call('checkIn', $payload)
        ->assertSet('outcome.ok', true)
        ->assertSet('outcome.result', 'needs_review')
        ->assertSee('Checked in at 10:00 AM') // Asia/Karachi: the profile has no timezone of its own
        ->assertSee('could not be confirmed precisely, so HR may review it');

    $record = AttendanceRecord::query()
        ->where('employee_id', $f['employee']->id)
        ->sole();

    expect($record->verification_status)->toBe(Status::NeedsReview->value);

    $verification = AttendanceVerification::query()
        ->where('employee_id', $f['employee']->id)
        ->where('client_request_id', $clientRequestId)
        ->sole();

    expect($verification->accepted)->toBeTrue()
        ->and($verification->result)->toBe(Result::NeedsReview)
        ->and($verification->flags)->toContain('suspicious_accuracy')
        ->and((float) $verification->accuracy_meters)->toBe(0.5)
        ->and($verification->review_status)->toBe('pending');
});

it('proves idempotency and FOR UPDATE row-level locking during rapid check-in attempts', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    $sameRequestId = (string) Str::uuid();
    $payload = realWorldPayload(['client_request_id' => $sameRequestId]);

    DB::enableQueryLog();

    // First check-in attempt
    $page = Livewire::test(MyAttendance::class)
        ->call('checkIn', $payload)
        ->assertSet('outcome.ok', true)
        ->assertSet('outcome.result', 'verified');

    // Verify row-level FOR UPDATE lock on employee
    $queries = collect(DB::getQueryLog());
    $forUpdateQuery = $queries->first(fn ($q) => str_contains(strtolower($q['query']), 'for update') && str_contains(strtolower($q['query']), 'employees'));
    expect($forUpdateQuery)->not->toBeNull();

    // Verify 1 record and 1 verification created
    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->count())->toBe(1);
    expect(AttendanceVerification::query()->where('employee_id', $f['employee']->id)->count())->toBe(1);

    // Rapid second call with the exact same client_request_id (idempotent replay)
    $page->call('checkIn', $payload)
        ->assertSet('outcome.ok', true)
        ->assertSet('outcome.result', 'verified');

    // Idempotency: still exactly 1 record and 1 verification
    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->count())->toBe(1);
    expect(AttendanceVerification::query()->where('employee_id', $f['employee']->id)->count())->toBe(1);

    // Rapid third attempt with a different client_request_id on the same day while shift is open
    $newRequestId = (string) Str::uuid();
    $page->call('checkIn', realWorldPayload(['client_request_id' => $newRequestId]))
        ->assertSet('outcome.ok', false)
        ->assertSet('outcome.result', 'open_shift_exists')
        ->assertSee('You are still checked in');

    // Attendance records remains strictly 1; the rejected duplicate attempt is audited as non-accepted
    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->count())->toBe(1);

    $rejectedVerif = AttendanceVerification::query()
        ->where('employee_id', $f['employee']->id)
        ->where('client_request_id', $newRequestId)
        ->sole();

    expect($rejectedVerif->accepted)->toBeFalse()
        ->and($rejectedVerif->result)->toBe(Result::OpenShiftExists);
});
