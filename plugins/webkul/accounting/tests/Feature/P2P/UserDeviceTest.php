<?php

use Illuminate\Database\QueryException;
use Webkul\Security\Models\UserDevice;

require_once __DIR__.'/../../Helpers/DocumentTestHelper.php';

it('creates an active device for a user and reports it as active', function () {
    $user = documentTestUser();

    $device = UserDevice::query()->create([
        'user_id'                => $user->id,
        'company_id'             => $user->default_company_id,
        'label'                  => "Asad's MacBook",
        'platform'               => 'macos',
        'public_key'             => 'BASE64-SPKI-PLACEHOLDER',
        'public_key_fingerprint' => str_repeat('a', 64),
    ]);

    expect($device->exists)->toBeTrue()
        ->and($device->isActive())->toBeTrue()
        ->and($device->revoked_at)->toBeNull()
        ->and($device->user->id)->toBe($user->id)
        ->and($device->company->id)->toBe($user->default_company_id);
});

it('reports a revoked device as inactive and excludes it from the active scope', function () {
    $user = documentTestUser();

    $live = UserDevice::query()->create([
        'user_id'                => $user->id,
        'company_id'             => $user->default_company_id,
        'label'                  => 'Live device',
        'public_key'             => 'KEY-1',
        'public_key_fingerprint' => str_repeat('b', 64),
    ]);

    $revoked = UserDevice::query()->create([
        'user_id'                => $user->id,
        'company_id'             => $user->default_company_id,
        'label'                  => 'Revoked device',
        'public_key'             => 'KEY-2',
        'public_key_fingerprint' => str_repeat('c', 64),
        'revoked_at'             => now(),
    ]);

    expect($revoked->isActive())->toBeFalse();

    $activeIds = UserDevice::query()->active()->forUser($user->id)->pluck('id')->all();

    expect($activeIds)->toContain($live->id)
        ->and($activeIds)->not->toContain($revoked->id);
});

it('rejects a duplicate public key fingerprint', function () {
    $user = documentTestUser();
    $fingerprint = str_repeat('d', 64);

    UserDevice::query()->create([
        'user_id'                => $user->id,
        'company_id'             => $user->default_company_id,
        'label'                  => 'First',
        'public_key'             => 'KEY-1',
        'public_key_fingerprint' => $fingerprint,
    ]);

    expect(fn () => UserDevice::query()->create([
        'user_id'                => $user->id,
        'company_id'             => $user->default_company_id,
        'label'                  => 'Second',
        'public_key'             => 'KEY-2',
        'public_key_fingerprint' => $fingerprint,
    ]))->toThrow(QueryException::class);
});
