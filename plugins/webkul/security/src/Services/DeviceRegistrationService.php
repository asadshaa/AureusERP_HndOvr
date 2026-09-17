<?php

namespace Webkul\Security\Services;

use Illuminate\Support\Collection;
use RuntimeException;
use Webkul\Security\Models\User;
use Webkul\Security\Models\UserDevice;

/**
 * Owns the lifecycle of a registered device. Kept deliberately free of
 * any transfer or document concern: this is identity only, so HR or any
 * other plugin can reuse it without depending on accounting.
 *
 * The fingerprint is a SHA-256 of the SPKI public key bytes. It is
 * derived here rather than accepted from the client, so a caller cannot
 * claim a fingerprint that does not match the key it presented.
 */
class DeviceRegistrationService
{
    public function register(User $user, string $label, string $publicKey, ?string $platform = null): UserDevice
    {
        $fingerprint = hash('sha256', $publicKey);

        $device = UserDevice::query()
            ->where('public_key_fingerprint', $fingerprint)
            ->first();

        if ($device !== null) {
            if ($device->user_id !== $user->id) {
                throw new RuntimeException('This device key is already registered to another user.');
            }

            $device->update(['label' => $label, 'platform' => $platform, 'last_seen_at' => now()]);

            return $device->refresh();
        }

        return UserDevice::query()->create([
            'user_id'                => $user->id,
            'company_id'             => $user->default_company_id,
            'label'                  => $label,
            'platform'               => $platform,
            'public_key'             => $publicKey,
            'public_key_fingerprint' => $fingerprint,
            'last_seen_at'           => now(),
        ]);
    }

    public function revoke(User $actor, UserDevice $device): UserDevice
    {
        if ($device->user_id !== $actor->id) {
            throw new RuntimeException('You cannot revoke a device you do not own.');
        }

        $device->update(['revoked_at' => now()]);

        return $device->refresh();
    }

    /**
     * @return Collection<int, UserDevice>
     */
    public function activeFor(User $user): Collection
    {
        return UserDevice::query()
            ->active()
            ->forUser($user->id)
            ->orderBy('id')
            ->get();
    }
}
