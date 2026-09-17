<?php

return [
    /*
     | Master switch for WebRTC browser-to-browser direct transfer.
     */
    'enabled' => env('WEBRTC_P2P_ENABLED', true),

    /*
     | STUN / TURN servers for NAT traversal.
     | Default to Google's public STUN servers for standard internet & LAN traversal.
     */
    'ice_servers' => [
        ['urls' => 'stun:stun.l.google.com:19302'],
        ['urls' => 'stun:stun1.l.google.com:19302'],
        ['urls' => 'stun:stun2.l.google.com:19302'],
    ],

    /*
     | Session TTL in minutes. A signaling session expires if not connected within this window.
     */
    'session_ttl_minutes' => (int) env('WEBRTC_SESSION_TTL', 15),

    /*
     | Maximum file payload size in bytes (default 32MB).
     */
    'max_payload_bytes' => (int) env('WEBRTC_MAX_PAYLOAD', 32 * 1024 * 1024),

    /*
     | Chunk size in bytes for RTCDataChannel streaming (default 16KB for optimal SCTP packet sizing).
     */
    'chunk_size_bytes' => 16384,
];
