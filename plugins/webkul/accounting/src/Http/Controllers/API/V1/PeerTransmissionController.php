<?php

namespace Webkul\Accounting\Http\Controllers\API\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Accounting\Models\Peer;
use Webkul\Accounting\Services\Peers\DocumentExchangeService;
use Webkul\Accounting\Services\Peers\PeerPairingService;

/**
 * The endpoints a remote AureusERP instance talks to.
 *
 * Note what is absent: no `company_id` is accepted from the request on any
 * of these. It is always derived from the authenticated peer row, because
 * accepting it from the caller is how one tenant ends up writing into
 * another's books.
 */
class PeerTransmissionController extends Controller
{
    /**
     * Corner A: redeem a pairing code presented by corner B.
     *
     * Unauthenticated by necessity -- the code IS the credential, and this
     * call is what establishes the ones used from here on.
     */
    public function pair(Request $request, PeerPairingService $pairing): JsonResponse
    {
        $data = $request->validate([
            'code'         => ['required', 'string', 'max:64'],
            'endpoint_url' => ['required', 'string', 'max:2048'],
            'name'         => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $pairing->redeem($data['code'], $data['endpoint_url'], $data['name']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'signing_secret' => $result['signing_secret'],
            // Names are from the caller's perspective: what they send us,
            // and what they should expect from us.
            'your_token'     => $result['token_for_caller'],
            'our_token'      => $result['token_for_us'],
            'peer_name'      => $result['peer']->company?->name,
        ]);
    }

    /**
     * Receive an invoice from a verified peer.
     */
    public function store(Request $request, DocumentExchangeService $exchange): JsonResponse
    {
        /** @var Peer $peer */
        $peer = $request->attributes->get('peer');

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:255'],
            'payload'   => ['required', 'array'],
        ]);

        try {
            $inbound = $exchange->receive(
                $peer,
                $data['reference'],
                $data['payload'],
                $request->ip(),
            );
        } catch (\Throwable $e) {
            // A malformed payload, a failed checksum or a rejected MIME type
            // is PERMANENT -- the same bytes will fail identically forever.
            // Returning 4xx marks it terminal on the sender (see
            // SendTransmissionJob), instead of burning five retries on it.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // 200 rather than 201 when this is a redelivery, so the sender can
        // tell the difference without either outcome being an error.
        $isReplay = ! $inbound->wasRecentlyCreated;

        return response()->json([
            'id'        => $inbound->id,
            'status'    => $inbound->status->value,
            'duplicate' => $isReplay,
        ], $isReplay ? 200 : 202);
    }
}
