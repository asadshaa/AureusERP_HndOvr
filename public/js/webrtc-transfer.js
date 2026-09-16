/**
 * Aureus ERP — WebRTC DataChannel P2P Transfer Engine
 * Direct browser-to-browser streaming for invoices, files and PDFs, with an
 * mDNS/LAN candidate bypass. Bytes never touch the server between the two
 * parties: there is no relay and no server-side fallback, by design.
 */

/**
 * SHA-256 of the exact bytes that crossed the wire, hex encoded.
 *
 * Returns null when crypto.subtle is unavailable, which is NOT an edge case
 * here: SubtleCrypto requires a secure context, and the LAN test path is
 * plain http://<host-ip>:8000. Callers must treat null as "cannot hash",
 * fall back to the size check, and SAY SO rather than implying the file was
 * verified when it was not.
 */
async function sha256Hex(buffer) {
    if (!window.crypto || !window.crypto.subtle) return null;
    try {
        const digest = await window.crypto.subtle.digest('SHA-256', buffer);
        return Array.from(new Uint8Array(digest))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    } catch (e) {
        return null;
    }
}

function formatSdp(sdp) {
    if (!sdp) return '';
    const lines = sdp
        .replace(/\r\n/g, '\n')
        .replace(/\r/g, '\n')
        .split('\n')
        .map(line => line.trim())
        .filter(line => line.length > 0);
    return lines.join('\r\n') + '\r\n';
}

window.AureusWebRtc = {
    /**
     * Sender Peer Controller
     */
    createSender(config) {
        let pc = null;
        let dc = null;
        let pollTimer = null;
        let pollOffset = 0;
        let isTransferring = false;
        let isCompleted = false;
        let totalFileSize = 0;
        let lastReportedPct = -1;
        let sourceHash = null;
        // Receiver candidates can arrive on an earlier poll tick than the
        // answer. addIceCandidate() before setRemoteDescription() throws, and
        // pollOffset advances regardless -- so those candidates were dropped
        // permanently. On a NAT where a lost one was the only workable route,
        // this looks exactly like a firewall problem.
        let pendingCandidates = [];

        const {
            code,
            iceServers = [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun1.l.google.com:19302' },
            ],
            binaryUrl,
            chunkSize = 16384,
            csrfToken = '',
            onStatusChange = () => {},
            onProgress = () => {},
            onComplete = () => {},
            onError = () => {},
        } = config;

        async function init() {
            try {
                onStatusChange('initializing', 'Configuring WebRTC connection...');
                pc = new RTCPeerConnection({ iceServers });

                pc.onicecandidate = async (e) => {
                    if (e.candidate) {
                        await postJson(`/api/v1/webrtc/sessions/${code}/candidate`, {
                            role: 'sender',
                            candidate: e.candidate.toJSON(),
                        });
                    }
                };

                pc.onconnectionstatechange = () => {
                    const st = pc.connectionState;
                    if (st === 'connected') {
                        onStatusChange('connected', 'Peer connected. Ready for data channel stream...');
                    } else if (st === 'disconnected') {
                        onStatusChange('disconnected', 'Recipient disconnected.');
                    } else if (st === 'failed') {
                        onStatusChange('failed', 'P2P link failed.');
                    }
                };

                pc.oniceconnectionstatechange = () => {
                    const st = pc.iceConnectionState;
                    if (st === 'checking') {
                        onStatusChange('negotiating', 'Probing network routes (LAN / Internet)...');
                    } else if (st === 'connected' || st === 'completed') {
                        onStatusChange('connected', 'Direct P2P link established.');
                    }
                };

                // Create DataChannel
                dc = pc.createDataChannel('aureus-file-transfer', {
                    ordered: true,
                });
                dc.binaryType = 'arraybuffer';

                dc.onmessage = async (event) => {
                    if (typeof event.data === 'string') {
                        try {
                            const msg = JSON.parse(event.data);
                            if (msg.type === 'ready' && !isTransferring) {
                                onStatusChange('streaming', 'Receiver ready! Starting direct stream...');
                                await startStreaming();
                            } else if (msg.type === 'ack') {
                                isCompleted = true;
                                onStatusChange('completed', 'File received & verified by peer!');
                                onComplete({
                                    totalBytes: msg.size || totalFileSize,
                                    sha256: sourceHash,
                                    peerSha256: msg.sha256 || null,
                                });
                                stopPolling();
                            }
                        } catch (e) {}
                    }
                };

                dc.onopen = async () => {
                    onStatusChange('connected', 'Data channel opened. Awaiting recipient ready signal...');
                    // Fallback timer: start streaming after 2.5s if receiver connected but didn't send 'ready'
                    setTimeout(async () => {
                        if (!isTransferring && dc && dc.readyState === 'open') {
                            await startStreaming();
                        }
                    }, 2500);
                };

                dc.onerror = (err) => {
                    onError(err.message || 'DataChannel error');
                };

                dc.onclose = () => {
                    // A mid-transfer close means the recipient went away.
                    // Without this the sender sat on "streaming" forever.
                    if (isTransferring && !isCompleted) {
                        onError('The recipient disconnected before the transfer finished.');
                    }
                };

                // Create and set local offer
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);

                onStatusChange('waiting_peer', 'Waiting for receiver to enter code & connect...');
                await postJson(`/api/v1/webrtc/sessions/${code}/offer`, {
                    offer_sdp: formatSdp(offer.sdp),
                });

                // Start polling for receiver's answer & ICE candidates
                startPolling();
            } catch (err) {
                onError(err.message || 'Could not initialize WebRTC sender.');
            }
        }

        function startPolling() {
            pollTimer = setInterval(async () => {
                try {
                    const res = await fetch(`/api/v1/webrtc/sessions/${code}/poll?role=sender&offset=${pollOffset}`);
                    if (!res.ok) return;
                    const data = await res.json();

                    if (!data.success) return;

                    // Receiver signalled completion (ack over the data channel)
                    if (data.status === 'completed' && !isCompleted) {
                        isCompleted = true;
                        onStatusChange('completed', 'Transfer completed & confirmed!');
                        onComplete({ totalBytes: totalFileSize || 0, sha256: sourceHash });
                        stopPolling();
                        return;
                    }

                    // If receiver answered and we haven't applied remote description yet
                    if (data.answer_sdp && pc.signalingState === 'have-local-offer') {
                        onStatusChange('negotiating', 'Received peer answer. Connecting...');
                        await pc.setRemoteDescription(new RTCSessionDescription({
                            type: 'answer',
                            sdp: formatSdp(data.answer_sdp),
                        }));

                        // Flush anything that arrived before we could accept it.
                        for (const cand of pendingCandidates.splice(0)) {
                            try {
                                await pc.addIceCandidate(new RTCIceCandidate(cand));
                            } catch (e) {
                                console.warn('Could not add buffered ICE candidate:', e);
                            }
                        }
                    }

                    if (Array.isArray(data.candidates) && data.candidates.length > 0) {
                        for (const cand of data.candidates) {
                            if (!pc.remoteDescription) {
                                pendingCandidates.push(cand);
                                continue;
                            }
                            try {
                                await pc.addIceCandidate(new RTCIceCandidate(cand));
                            } catch (e) {
                                console.warn('Could not add ICE candidate:', e);
                            }
                        }
                    }

                    pollOffset = data.next_offset || pollOffset;

                    if (isCompleted) {
                        stopPolling();
                    }
                } catch (e) {
                    console.warn('Signaling poll error:', e);
                }
            }, 1000);
        }

        function stopPolling() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        async function startStreaming() {
            if (isTransferring) return;
            isTransferring = true;

            try {
                onStatusChange('streaming', 'Reading file & streaming to peer...');
                const resp = await fetch(binaryUrl);
                if (!resp.ok) throw new Error('Could not read file binary from server.');

                const buffer = await resp.arrayBuffer();
                totalFileSize = buffer.byteLength;

                // Hash the exact bytes about to be streamed so the recipient
                // can prove what it reassembled is what left here. It travels
                // in the eof frame over the encrypted channel, never via the
                // server -- and for an invoice the PDF is rendered on demand,
                // so there is no stored checksum to use instead.
                sourceHash = await sha256Hex(buffer);

                dc.send(JSON.stringify({
                    type: 'header',
                    totalSize: totalFileSize,
                }));

                let offset = 0;
                const bufferThreshold = 65536; // 64KB threshold for backpressure
                dc.bufferedAmountLowThreshold = bufferThreshold;

                function sendNextChunk() {
                    try {
                        while (offset < totalFileSize) {
                            if (!dc || dc.readyState !== 'open') {
                                onError('The connection closed before the transfer finished.');
                                return;
                            }

                            if (dc.bufferedAmount > bufferThreshold) {
                                dc.onbufferedamountlow = () => {
                                    dc.onbufferedamountlow = null;
                                    sendNextChunk();
                                };

                                // The buffer can drain between the check above
                                // and the handler being attached, in which case
                                // the event has already fired and will never
                                // fire again -- a permanent stall. Re-check.
                                if (dc.bufferedAmount <= bufferThreshold) {
                                    dc.onbufferedamountlow = null;
                                    continue;
                                }

                                return;
                            }

                            const chunk = buffer.slice(offset, offset + chunkSize);
                            dc.send(chunk);
                            offset += chunk.byteLength;

                            const pct = Math.round((offset / totalFileSize) * 100);
                            if (pct !== lastReportedPct) {
                                lastReportedPct = pct;
                                onProgress(offset, totalFileSize, pct);
                            }
                        }

                        dc.send(JSON.stringify({
                            type: 'eof',
                            size: totalFileSize,
                            sha256: sourceHash,
                        }));
                        onProgress(totalFileSize, totalFileSize, 100);
                        onStatusChange('streaming', 'All chunks sent. Awaiting recipient verification...');
                    } catch (err) {
                        onError(err.message || 'The connection dropped while sending.');
                    }
                }

                sendNextChunk();
            } catch (err) {
                onError(err.message || 'Failed while streaming data to peer.');
            }
        }

        function destroy() {
            stopPolling();
            if (dc) { dc.close(); dc = null; }
            if (pc) { pc.close(); pc = null; }
        }

        async function postJson(url, body) {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(body),
            });
            return res.json();
        }

        init();

        return { destroy };
    },

    /**
     * Receiver Peer Controller
     */
    createReceiver(config) {
        let pc = null;
        let dc = null;
        let pollTimer = null;
        let pollOffset = 0;
        let fallbackTimer = null;
        let isReceiverCompleted = false;

        let expectedSize = 0;
        let receivedBytes = 0;
        let receivedChunks = [];
        let metadata = null;
        let lastReportedPct = -1;

        const {
            code,
            csrfToken = '',
            onStatusChange = () => {},
            onMetadata = () => {},
            onProgress = () => {},
            onFileReceived = () => {},
            onConnectionStalled = () => {},
            onError = () => {},
        } = config;

        async function init() {
            try {
                onStatusChange('connecting', `Looking up session ${code}...`);

                // 1. Fetch session and sender's offer
                const sessionRes = await fetch(`/api/v1/webrtc/sessions/${code}`);
                if (!sessionRes.ok) {
                    throw new Error('Session code not found or expired.');
                }
                const sessionData = await sessionRes.json();
                metadata = sessionData.metadata;
                onMetadata(metadata);

                // If nothing has arrived after 12s the handshake is probably
                // not going to succeed on this network. Say so rather than
                // silently hanging.
                fallbackTimer = setTimeout(() => {
                    if (receivedBytes === 0 && !isReceiverCompleted) {
                        onConnectionStalled('Still trying to reach the sender directly. Keep this page open — both of you need to stay online.');
                    }
                }, 12000);

                if (!sessionData.offer_sdp) {
                    onStatusChange('waiting_offer', 'Waiting for sender to publish offer...');
                    await waitForOffer();
                } else {
                    await connectWithOffer(sessionData.offer_sdp, sessionData.ice_servers);
                }
            } catch (err) {
                onError(err.message || 'Could not connect to WebRTC session.');
            }
        }

        async function waitForOffer() {
            const interval = setInterval(async () => {
                const res = await fetch(`/api/v1/webrtc/sessions/${code}`);
                if (res.ok) {
                    const data = await res.json();
                    if (data.offer_sdp) {
                        clearInterval(interval);
                        await connectWithOffer(data.offer_sdp, data.ice_servers);
                    }
                }
            }, 1000);
        }

        async function connectWithOffer(offerSdp, iceServers) {
            onStatusChange('connecting', 'Establishing secure peer handshake...');
            pc = new RTCPeerConnection({
                iceServers: iceServers || [{ urls: 'stun:stun.l.google.com:19302' }],
            });

            pc.onicecandidate = async (e) => {
                if (e.candidate) {
                    await postJson(`/api/v1/webrtc/sessions/${code}/candidate`, {
                        role: 'receiver',
                        candidate: e.candidate.toJSON(),
                    });
                }
            };

            pc.onconnectionstatechange = () => {
                const st = pc.connectionState;
                if (st === 'connected') {
                    onStatusChange('connected', 'Peer connected. Ready for data stream.');
                } else if (st === 'failed') {
                    onStatusChange('failed', 'P2P link blocked by firewall or network isolation.');
                    onConnectionStalled('A direct connection was blocked, most likely by a firewall. Ask the sender to retry, or try a different network.');
                }
            };

            pc.oniceconnectionstatechange = () => {
                const st = pc.iceConnectionState;
                if (st === 'checking') {
                    onStatusChange('connecting', 'Probing peer routes (LAN / Internet candidates)...');
                } else if (st === 'connected' || st === 'completed') {
                    onStatusChange('connected', 'Direct P2P link established! Setting up data channel...');
                } else if (st === 'failed') {
                    onStatusChange('failed', 'Direct connection failed.');
                    onConnectionStalled('A direct connection could not be established. Ask the sender to start a new transfer while you are both online.');
                }
            };

            pc.ondatachannel = (e) => {
                dc = e.channel;
                dc.binaryType = 'arraybuffer';

                dc.onmessage = (event) => {
                    handleIncomingData(event.data);
                };

                const notifyReady = () => {
                    onStatusChange('transferring', 'P2P DataChannel open. Receiving file stream...');
                    try {
                        dc.send(JSON.stringify({ type: 'ready' }));
                    } catch (err) {}
                };

                if (dc.readyState === 'open') {
                    notifyReady();
                } else {
                    dc.onopen = notifyReady;
                }

                dc.onerror = () => {
                    onError('The direct connection reported an error.');
                };

                dc.onclose = () => {
                    // Closing mid-stream means the bytes are incomplete. The
                    // receiver used to sit at N% forever instead of saying so.
                    if (!isReceiverCompleted && receivedBytes > 0) {
                        onError('The sender disconnected before the transfer finished. Nothing was saved.');
                    }
                };
            };

            await pc.setRemoteDescription(new RTCSessionDescription({
                type: 'offer',
                sdp: formatSdp(offerSdp),
            }));

            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);

            await postJson(`/api/v1/webrtc/sessions/${code}/answer`, {
                answer_sdp: formatSdp(answer.sdp),
            });

            startPollingCandidates();
        }

        function startPollingCandidates() {
            pollTimer = setInterval(async () => {
                try {
                    const res = await fetch(`/api/v1/webrtc/sessions/${code}/poll?role=receiver&offset=${pollOffset}`);
                    if (!res.ok) return;
                    const data = await res.json();

                    if (Array.isArray(data.candidates)) {
                        for (const cand of data.candidates) {
                            try {
                                await pc.addIceCandidate(new RTCIceCandidate(cand));
                            } catch (e) {
                                console.warn('Could not add ICE candidate:', e);
                            }
                        }
                    }

                    pollOffset = data.next_offset || pollOffset;
                } catch (e) {
                    console.warn('Receiver poll error:', e);
                }
            }, 1000);
        }

        function stopPolling() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            if (fallbackTimer) {
                clearTimeout(fallbackTimer);
                fallbackTimer = null;
            }
        }

        async function handleIncomingData(data) {
            if (typeof data === 'string') {
                let msg;
                try {
                    msg = JSON.parse(data);
                } catch (e) {
                    console.warn('Could not parse text message:', e);
                    return;
                }

                if (msg.type === 'header') {
                    expectedSize = msg.totalSize || 0;
                    receivedBytes = 0;
                    receivedChunks = [];
                    lastReportedPct = -1;
                    onStatusChange('transferring', 'Receiving file directly from the sender...');
                } else if (msg.type === 'eof') {
                    await finishTransfer(msg);
                }
            } else if (data instanceof ArrayBuffer) {
                receivedChunks.push(data);
                receivedBytes += data.byteLength;

                const pct = expectedSize > 0 ? Math.round((receivedBytes / expectedSize) * 100) : 0;
                if (pct !== lastReportedPct) {
                    lastReportedPct = pct;
                    onProgress(receivedBytes, expectedSize, pct);
                }
            }
        }

        /**
         * Verify before delivering, and refuse to deliver anything that fails.
         *
         * The prototype did neither: it never compared sizes, never hashed
         * anything, and told the user "complete" regardless -- so a truncated
         * or corrupted transfer produced a broken PDF presented as a good one.
         */
        async function finishTransfer(eofMsg) {
            isReceiverCompleted = true;
            stopPolling();

            const declaredSize = typeof eofMsg.size === 'number' ? eofMsg.size : expectedSize;

            if (declaredSize && receivedBytes !== declaredSize) {
                onError(`Transfer incomplete: expected ${declaredSize} bytes but received ${receivedBytes}. Nothing was saved.`);
                return;
            }

            const mimeType = metadata?.mime_type || 'application/octet-stream';
            const filename = metadata?.filename || 'received-document';

            const blob = new Blob(receivedChunks, { type: mimeType });
            const localHash = await sha256Hex(await blob.arrayBuffer());

            let integrity;

            if (eofMsg.sha256 && localHash) {
                if (localHash !== eofMsg.sha256) {
                    onError('Checksum mismatch: the file that arrived is not the file that was sent. Nothing was saved.');
                    return;
                }
                integrity = { verified: true, method: 'sha256', sha256: localHash, bytes: receivedBytes };
            } else {
                // Insecure origin (plain http on a LAN IP) -- SubtleCrypto is
                // unavailable. Say exactly that instead of implying a checksum
                // was checked.
                integrity = {
                    verified: true,
                    method: 'size',
                    sha256: localHash || null,
                    bytes: receivedBytes,
                    note: 'Size verified. SHA-256 needs a secure origin (https or localhost).',
                };
            }

            try {
                dc.send(JSON.stringify({ type: 'ack', size: receivedBytes, sha256: localHash }));
            } catch (e) {}

            // Only now is the session genuinely done. Marking it complete
            // before verifying would close out a transfer that failed.
            postJson(`/api/v1/webrtc/sessions/${code}/complete`, {});

            onStatusChange('completed', integrity.method === 'sha256'
                ? 'Transfer complete. SHA-256 verified.'
                : 'Transfer complete. Size verified.');

            const blobUrl = URL.createObjectURL(blob);

            onFileReceived({ blob, blobUrl, filename, size: blob.size, metadata, integrity });
        }

        // There is deliberately no server-relay fallback here. Routing the
        // recipient through /binary would put plaintext on the server, which
        // is the one thing this transport exists to avoid -- and serving it
        // to the recipient means serving it to another company's user. A
        // connection that cannot be made now fails visibly instead.
        // See docs/superpowers/specs/2026-09-16-webrtc-browser-to-browser-transfer.md

        function destroy() {
            stopPolling();
            if (dc) { dc.close(); dc = null; }
            if (pc) { pc.close(); pc = null; }
        }

        async function postJson(url, body) {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(body),
            });
            return res.json();
        }

        init();

        return { destroy };
    },
};
