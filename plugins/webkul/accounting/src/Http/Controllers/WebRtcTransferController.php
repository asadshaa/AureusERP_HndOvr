<?php

namespace Webkul\Accounting\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Webkul\Accounting\Services\Peers\WebRtcSignalingService;

class WebRtcTransferController extends Controller
{
    public function __construct(
        private readonly WebRtcSignalingService $signaling,
    ) {}

    /**
     * The recipient's page. Open to whoever holds the code, so it is handed
     * only the display metadata -- never the raw session record.
     */
    public function show(?string $code = null)
    {
        $session = $code ? $this->signaling->getActiveSession($code) : null;

        return response()->view('accounting::peers.webrtc-receive', [
            'initialCode' => $code ? strtoupper(trim($code)) : '',
            'session'     => $session,
            'metadata'    => $session ? $this->signaling->publicMetadata($session) : null,
        ]);
    }

    /**
     * The sender's console.
     */
    public function send(string $code)
    {
        $session = $this->signaling->getActiveSession($code);

        // Same 404 whether the session is gone or simply is not this user's,
        // so holding a code tells you nothing about whose transfer it is.
        if (! $session || (int) $session->creator_id !== (int) Auth::id()) {
            abort(404, 'Session expired or not found.');
        }

        $baseReceiveUrl = route('accounting.webrtc.receive', ['code' => $session->code]);
        $lanIp = gethostbyname(gethostname());
        $port = request()->getPort() ?: 8000;
        $lanReceiveUrl = $baseReceiveUrl;

        if (in_array(request()->getHost(), ['127.0.0.1', 'localhost']) && $lanIp && $lanIp !== '127.0.0.1') {
            $lanReceiveUrl = preg_replace('#^https?://[^/]+#', "http://{$lanIp}:{$port}", $baseReceiveUrl);
        }

        return response()->view('accounting::peers.webrtc-send', [
            'session'         => $session,
            'code'            => $session->code,
            'metadata'        => $this->signaling->publicMetadata($session),
            'receiveUrl'      => $lanReceiveUrl,
            'localReceiveUrl' => $baseReceiveUrl,
            'lanIp'           => $lanIp,
            // The receiver reads these from the signaling API; the sender has
            // to be handed them, or it silently falls back to the client's
            // hardcoded STUN list and ignores config/webrtc.php entirely.
            'iceServers'      => config('webrtc.ice_servers', []),
            'chunkSize'       => (int) config('webrtc.chunk_size_bytes', 16384),
        ]);
    }
}
