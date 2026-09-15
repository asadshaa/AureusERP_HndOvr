<?php

namespace Webkul\Accounting\Http\Controllers\API\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Throwable;
use Webkul\Accounting\Models\DocumentTransfer;
use Webkul\Accounting\Services\DocumentTransferService;
use Webkul\Security\Models\UserDevice;

class DocumentTransferController extends Controller
{
    public function __construct(private readonly DocumentTransferService $transfers) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->transfers->pendingFor($request->user())->map(fn (DocumentTransfer $transfer) => [
                'id'         => $transfer->id,
                'document'   => $transfer->document?->title,
                'filename'   => $transfer->document?->currentVersion?->original_filename,
                'sender'     => $transfer->sender?->name,
                'note'       => $transfer->note,
                'status'     => $transfer->status->value,
                'expires_at' => $transfer->expires_at,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_id' => ['required', 'integer'],
            'device_id'   => ['required', 'integer'],
            'note'        => ['nullable', 'string', 'max:500'],
        ]);

        $device = UserDevice::query()->findOrFail($data['device_id']);

        try {
            $transfer = $this->transfers->send(
                $request->user(),
                $data['document_id'],
                $device,
                $data['note'] ?? null,
                $request->ip(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        return response()->json(['data' => [
            'id'         => $transfer->id,
            'status'     => $transfer->status->value,
            'expires_at' => $transfer->expires_at,
        ]], 201);
    }

    public function claim(Request $request, DocumentTransfer $transfer)
    {
        try {
            $result = $this->transfers->claim($request->user(), $transfer, $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->streamDownload(
            fn () => print ($result['contents']),
            $result['version']->original_filename,
        );
    }

    public function cancel(Request $request, DocumentTransfer $transfer): JsonResponse
    {
        try {
            $this->transfers->cancel($request->user(), $transfer, $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Transfer cancelled.']);
    }
}
