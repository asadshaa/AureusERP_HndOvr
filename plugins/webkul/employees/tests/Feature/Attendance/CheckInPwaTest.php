<?php

require_once __DIR__.'/GeoTestHelpers.php';

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Webkul\Employee\Filament\Pages\CheckIn;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Support\Pwa;

function checkInPayload(array $overrides = []): array
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

it('renders the phone check-in page for an eligible employee only', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    expect(CheckIn::canAccess())->toBeTrue()
        ->and(CheckIn::getUrl())->toEndWith('/admin/check-in');

    Livewire::test(CheckIn::class)
        ->assertOk()
        ->assertSee('Not checked in')
        ->assertSee('Check in')
        ->assertSee('Worked today')
        ->assertSee('Last punch')
        ->assertSee('HQ')
        ->assertDontSee('Attendance Calendar');

    [$outsider] = geoEmployee($f['company'], $f['location'], ['is_active' => false]);
    $this->actingAs($outsider);
    expect(CheckIn::canAccess())->toBeFalse();
});

it('checks in and out through the geofenced attendance service', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    $page = Livewire::test(CheckIn::class)
        ->call('checkIn', checkInPayload())
        ->assertSet('outcome.ok', true)
        ->assertSet('outcome.result', 'verified')
        ->assertSee('Checked in at 05:00 AM')
        ->assertSee('Checked in since 05:00 AM')
        ->assertSee('Check out');

    $record = AttendanceRecord::query()->where('employee_id', $f['employee']->id)->sole();
    expect($record->source)->toBe('gps')
        ->and(AttendanceVerification::query()->where('employee_id', $f['employee']->id)->sole()->user_id)->toBe($f['user']->id);

    $this->travel(2)->hours();
    $page->call('checkOut', checkInPayload())
        ->assertSet('outcome.ok', true)
        ->assertSee('Checked out at 07:00 AM')
        ->assertSee('Check in again');

    expect($record->fresh()->check_out)->not->toBeNull();
});

it('rejects a check-in outside the geofence with the shared employee message', function () {
    $f = geoFixture();
    $this->actingAs($f['user']);

    Livewire::test(CheckIn::class)
        ->call('checkIn', checkInPayload(geoOutside()))
        ->assertSet('outcome.ok', false)
        ->assertSet('outcome.result', 'outside_geofence')
        ->assertSee(__('employees::attendance.results.outside_geofence'));

    Livewire::test(CheckIn::class)
        ->call('reportLocationFailure', 'check_in', 'permission_denied', (string) Str::uuid())
        ->assertSet('outcome.result', 'permission_denied');

    expect(AttendanceRecord::query()->where('employee_id', $f['employee']->id)->exists())->toBeFalse();
});

it('serves a valid web app manifest that opens the check-in page', function () {
    $response = $this->get('/manifest.webmanifest')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/manifest+json');

    $manifest = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest)->toHaveKeys(['name', 'short_name', 'start_url', 'display', 'theme_color', 'background_color', 'icons', 'scope'])
        ->and($manifest['name'])->toBe('Aureus')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toStartWith('/admin/check-in')
        ->and($manifest['theme_color'])->toMatch('/^#[0-9a-f]{6}$/i')
        ->and($manifest['theme_color'])->toBe(Pwa::themeColor());

    $icons = collect($manifest['icons']);
    expect($icons->pluck('sizes')->all())->toContain('192x192', '512x512')
        ->and($icons->pluck('purpose')->all())->toContain('maskable');

    foreach ($icons as $icon) {
        expect(public_path(ltrim($icon['src'], '/')))->toBeFile();
    }
});

it('adds the manifest, theme colour and service worker to panel pages', function () {
    $f = geoFixture();

    $this->actingAs($f['user'])
        ->get(CheckIn::getUrl())
        ->assertOk()
        ->assertSee('rel="manifest"', false)
        ->assertSee('<meta name="theme-color" content="'.Pwa::themeColor().'">', false)
        ->assertSee('serviceWorker', false)
        ->assertSee('apple-touch-icon', false)
        ->assertSee('beforeinstallprompt', false);
});

it('ships a service worker that never caches pages or API calls, and an offline page', function () {
    $worker = file_get_contents(public_path('sw.js'));

    expect($worker)->toContain("request.mode === 'navigate'")
        ->and($worker)->toContain('OFFLINE_URL')
        ->and($worker)->toContain("request.method !== 'GET'")
        ->and($worker)->toContain("! type.includes('text/html')")
        ->and(file_get_contents(public_path('offline.html')))->toContain("You're offline");
});
