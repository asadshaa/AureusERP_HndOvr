<?php

namespace Webkul\Accounting\Http\Controllers\API\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Webkul\Account\Models\Move;
use Webkul\Accounting\Models\Document;
use Webkul\Accounting\Services\DocumentService;
use Webkul\Accounting\Services\Peers\WebRtcSignalingService;
use Webkul\Support\Traits\PDFHandler;

/**
 * Signaling for browser-to-browser transfers.
 *
 * The anonymous half of this controller (show/answer/candidate/poll/complete)
 * is reachable by whoever holds the session code, so it returns display
 * metadata and SDP only. Anything that touches real bytes or real records
 * lives in the authenticated half and re-checks the actor every time.
 */
class WebRtcSignalingController extends Controller
{
    use PDFHandler;

    public function __construct(
        private readonly WebRtcSignalingService $signaling,
        private readonly DocumentService $documents,
    ) {}

    /**
     * Sender opens a transfer session.
     */
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:invoice,document'],
            'id'   => ['required', 'integer'],
        ]);

        $actor = Auth::user();

        try {
            // Both lookups are company-scoped, so a record in another company
            // is indistinguishable from one that does not exist. The
            // prototype ran an unscoped findOrFail() on a client-supplied id.
            $record = $validated['type'] === 'invoice'
                ? Move::query()
                    ->where('company_id', $actor->default_company_id)
                    ->findOrFail($validated['id'])
                : $this->documents->find($actor, $validated['id']);

            $session = $this->signaling->createSession(
                actor: $actor,
                transmittable: $record,
                ipAddress: $request->ip(),
            );

            return response()->json([
                'success'     => true,
                'code'        => $session->code,
                'status'      => $session->status->value,
                'metadata'    => $this->signaling->publicMetadata($session),
                'ice_servers' => config('webrtc.ice_servers', []),
                'chunk_size'  => config('webrtc.chunk_size_bytes', 16384),
                'send_url'    => route('accounting.webrtc.send', ['code' => $session->code]),
                'receive_url' => route('accounting.webrtc.receive', ['code' => $session->code]),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Recipient reads what is on offer. Unauthenticated by design: the
     * recipient may have no account here at all.
     */
    public function show(string $code): JsonResponse
    {
        $session = $this->signaling->getActiveSession($code);

        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => 'Session expired or not found.',
            ], 404);
        }

        return response()->json([
            'success'     => true,
            'code'        => $session->code,
            'status'      => $session->status->value,
            'offer_sdp'   => $session->offer_sdp,
            'metadata'    => $this->signaling->publicMetadata($session),
            'ice_servers' => config('webrtc.ice_servers', []),
            'chunk_size'  => config('webrtc.chunk_size_bytes', 16384),
        ]);
    }

    /**
     * Sender publishes its SDP offer.
     */
    public function offer(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'offer_sdp' => ['required', 'string'],
        ]);

        try {
            $session = $this->signaling->setOffer(Auth::user(), $code, $validated['offer_sdp']);

            return response()->json([
                'success' => true,
                'status'  => $session->status->value,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Recipient publishes its SDP answer.
     */
    public function answer(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'answer_sdp' => ['required', 'string'],
        ]);

        try {
            $session = $this->signaling->submitAnswer($code, $validated['answer_sdp']);

            return response()->json([
                'success' => true,
                'status'  => $session->status->value,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Either side contributes an ICE candidate.
     */
    public function candidate(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'role'      => ['required', 'string', 'in:sender,receiver'],
            'candidate' => ['required', 'array'],
        ]);

        try {
            $this->signaling->addCandidate(
                code: $code,
                role: $validated['role'],
                candidate: $validated['candidate'],
                clientIp: $request->ip(),
            );

            return response()->json(['success' => true]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Handshake poll: status, SDP, and the other side's new candidates.
     */
    public function poll(Request $request, string $code): JsonResponse
    {
        $result = $this->signaling->poll(
            $code,
            $request->query('role') === 'sender' ? 'sender' : 'receiver',
            max(0, (int) $request->query('offset', 0)),
        );

        return response()->json([
            'success' => true,
            ...$result,
        ]);
    }

    /**
     * Mark the transfer finished.
     */
    public function complete(Request $request, string $code): JsonResponse
    {
        $session = $this->signaling->complete($code, $request->ip());

        return response()->json([
            'success' => $session !== null,
            'status'  => $session?->status->value ?? 'expired',
        ]);
    }

    /**
     * The sender's own browser reads its own bytes to feed the data channel.
     *
     * This is the only endpoint that serves file contents, and it is
     * restricted to the session creator. It is NOT a fallback for the
     * recipient: routing the recipient through here would put plaintext on
     * the server, which is the one thing this transport exists to avoid.
     */
    public function binary(Request $request, string $code)
    {
        $session = $this->signaling->getActiveSession($code);

        if (! $session) {
            abort(404, 'Session expired or not found.');
        }

        try {
            $this->signaling->assertCanStream(Auth::user(), $session, $request->ip());
        } catch (Throwable $e) {
            abort(403, $e->getMessage());
        }

        $transmittable = $session->transmittable;

        if ($transmittable instanceof Move) {
            $template = 'accounts::invoice/actions/preview.index';

            if (! view()->exists($template)) {
                abort(500, 'Invoice print template not found.');
            }

            $pdf = $this->generatePDF(view($template, ['record' => $transmittable])->render());

            return response($pdf->output(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.($session->metadata['filename'] ?? 'invoice.pdf').'"',
            ]);
        }

        if ($transmittable instanceof Document) {
            // The authorized read, which re-checks DownloadDocuments and
            // records a Downloaded audit row. The prototype called
            // readCurrentVersionForSync(), whose own docblock forbids exactly
            // this: it is the unscoped, unaudited system path for Drive sync.
            $result = $this->documents->retrieveContents(Auth::user(), $transmittable->id, $request->ip());

            return response($result['contents'], 200, [
                'Content-Type'        => $result['version']->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.$result['version']->original_filename.'"',
            ]);
        }

        abort(400, 'Unsupported transmittable type.');
    }
}
