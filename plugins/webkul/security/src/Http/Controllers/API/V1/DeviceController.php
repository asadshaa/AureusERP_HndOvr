<?php

namespace Webkul\Security\Http\Controllers\API\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Webkul\Security\Models\UserDevice;
use Webkul\Security\Services\DeviceRegistrationService;

class DeviceController extends Controller
{
    public function __construct(private readonly DeviceRegistrationService $devices) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->devices->activeFor($request->user())->map(fn (UserDevice $device) => [
                'id'           => $device->id,
                'label'        => $device->label,
                'platform'     => $device->platform,
                'last_seen_at' => $device->last_seen_at,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'      => ['required', 'string', 'max:120'],
            'public_key' => ['required', 'string', 'max:4096'],
            'platform'   => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $device = $this->devices->register(
                $request->user(),
                $data['label'],
                $data['public_key'],
                $data['platform'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            'id'       => $device->id,
            'label'    => $device->label,
            'platform' => $device->platform,
        ]], 201);
    }

    public function destroy(Request $request, UserDevice $device): JsonResponse
    {
        try {
            $this->devices->revoke($request->user(), $device);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['message' => 'Device revoked.']);
    }
}
