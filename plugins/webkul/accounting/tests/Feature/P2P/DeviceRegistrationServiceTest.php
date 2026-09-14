<?php

use Webkul\Security\Models\UserDevice;
use Webkul\Security\Services\DeviceRegistrationService;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

beforeEach(function () {
    $this->service = app(DeviceRegistrationService::class);
});

it('registers a device, deriving the fingerprint from the public key', function () {
    $user = documentTestUser();

    $device = $this->service->register($user, 'Work laptop', 'SPKI-PUBLIC-KEY-BYTES', 'windows');

    expect($device->user_id)->toBe($user->id)
        ->and($device->company_id)->toBe($user->default_company_id)
        ->and($device->label)->toBe('Work laptop')
        ->and($device->platform)->toBe('windows')
        ->and($device->public_key_fingerprint)->toBe(hash('sha256', 'SPKI-PUBLIC-KEY-BYTES'))
        ->and($device->last_seen_at)->not->toBeNull()
        ->and($device->isActive())->toBeTrue();
});

it('returns the existing device when the same public key is registered again', function () {
    $user = documentTestUser();

    $first = $this->service->register($user, 'Original label', 'SAME-KEY');
    $second = $this->service->register($user, 'Renamed', 'SAME-KEY');

    expect($second->id)->toBe($first->id)
        ->and($second->label)->toBe('Renamed')
        ->and(UserDevice::query()->count())->toBe(1);
});

it('revokes a device the actor owns', function () {
    $user = documentTestUser();
    $device = $this->service->register($user, 'Laptop', 'KEY');

    $revoked = $this->service->revoke($user, $device);

    expect($revoked->revoked_at)->not->toBeNull()
        ->and($revoked->isActive())->toBeFalse();
});

it('refuses to revoke a device belonging to another user', function () {
    $owner = documentTestUser();
    $outsider = documentTestUser();
    $device = $this->service->register($owner, 'Laptop', 'KEY');

    expect(fn () => $this->service->revoke($outsider, $device))
        ->toThrow(RuntimeException::class, 'You cannot revoke a device you do not own.');

    expect($device->fresh()->isActive())->toBeTrue();
});

it('lists only active devices for the user', function () {
    $user = documentTestUser();

    $live = $this->service->register($user, 'Live', 'KEY-1');
    $revoked = $this->service->register($user, 'Dead', 'KEY-2');
    $this->service->revoke($user, $revoked);

    $ids = $this->service->activeFor($user)->pluck('id')->all();

    expect($ids)->toBe([$live->id]);
});
