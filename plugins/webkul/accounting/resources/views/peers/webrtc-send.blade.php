<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>P2P Transfer Sender — {{ $session->code }}</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --success: #16a34a;
            --warning: #ca8a04;
        }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text-main);
            margin: 0;
            padding: 2.5rem 1rem;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .container {
            width: 100%;
            max-width: 34rem;
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 10px 15px -3px rgb(0 0 0 / 0.1);
            padding: 2rem;
            border: 1px solid var(--border);
        }
        .logo-area {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin-bottom: 1.5rem;
        }
        .badge {
            background: #eff6ff;
            color: var(--primary);
            font-size: .75rem;
            font-weight: 700;
            padding: .25rem .6rem;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        h1 {
            font-size: 1.35rem;
            margin: 0 0 .5rem;
            font-weight: 700;
        }
        p.subtitle {
            color: var(--text-muted);
            font-size: .9rem;
            margin: 0 0 1.5rem;
            line-height: 1.4;
        }
        .code-box {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .code-title {
            font-size: .85rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .05em;
            margin-bottom: .5rem;
        }
        .code-value {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: .15em;
            color: var(--primary);
            user-select: all;
        }
        .link-group {
            display: flex;
            gap: .5rem;
            margin-top: 1rem;
        }
        input.link-input {
            flex: 1;
            padding: .65rem .85rem;
            font-size: .85rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            color: var(--text-main);
            outline: none;
        }
        button.btn-copy {
            background: #f1f5f9;
            color: var(--text-main);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: .65rem 1rem;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }
        button.btn-copy:hover {
            background: #e2e8f0;
        }
        .status-box {
            background: #f1f5f9;
            border-radius: 12px;
            padding: 1.25rem;
            margin-top: 1.5rem;
            border: 1px solid var(--border);
        }
        .status-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: .4rem;
        }
        .status-title {
            font-size: .85rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .status-desc {
            font-size: .95rem;
            font-weight: 500;
            color: var(--text-main);
        }
        .progress-bar-container {
            height: 10px;
            background: #e2e8f0;
            border-radius: 999px;
            overflow: hidden;
            margin: 1rem 0 .5rem;
        }
        .progress-bar {
            height: 100%;
            background: var(--primary);
            width: 0%;
            transition: width .2s;
        }
        .progress-meta {
            display: flex;
            justify-content: space-between;
            font-size: .8rem;
            color: var(--text-muted);
        }
        .file-summary {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .footer-note {
            margin-top: 2rem;
            font-size: .8rem;
            color: var(--text-muted);
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-area">
            <span class="badge">WebRTC Sender</span>
            <span style="font-weight: 600; font-size: .95rem; color: var(--text-muted);">Aureus ERP</span>
        </div>

        <h1>{{ $metadata['title'] ?? 'Direct File Transfer' }}</h1>
        <p class="subtitle">Keep this browser tab open. Provide the transfer code or direct link to the recipient on Windows.</p>

        <div class="file-summary">
            <div>
                <div style="font-weight: 600; font-size: .95rem;">{{ $metadata['filename'] ?? 'document.pdf' }}</div>
                @if (! empty($metadata['file_size']))
                    <div style="font-size: .85rem; color: var(--text-muted);">{{ number_format($metadata['file_size']) }} bytes</div>
                @endif
            </div>
            <span class="badge" style="background: #e0f2fe; color: #0284c7;">Encrypted P2P</span>
        </div>

        <div class="code-box">
            <div class="code-title">Transfer Pairing Code</div>
            <div class="code-value">{{ $session->code }}</div>
            <div class="link-group">
                <input type="text" readonly value="{{ $receiveUrl }}" id="receiveUrlInput" class="link-input">
                <button class="btn-copy" id="btnCopyLink">Copy Link</button>
            </div>
            @if (!empty($lanIp) && $lanIp !== '127.0.0.1')
                <div style="margin-top: .6rem; font-size: .8rem; color: var(--text-muted);">
                    Wi-Fi / LAN Link for other PCs: <span style="font-family: monospace; font-weight: 600; color: var(--text-main);">{{ $receiveUrl }}</span>
                </div>
            @endif
        </div>

        <div class="status-box">
            <div class="status-header">
                <div class="status-title">Live Status</div>
                <div id="connectionIndicator" style="font-size: .8rem; font-weight: 600; color: var(--warning);">Connecting...</div>
            </div>
            <div id="statusDesc" class="status-desc">Generating encrypted WebRTC offer...</div>

            <div id="progressArea" style="display: none;">
                <div class="progress-bar-container">
                    <div id="progressBar" class="progress-bar"></div>
                </div>
                <div class="progress-meta">
                    <span id="progressBytes">0 KB</span>
                    <span id="progressPercent">0%</span>
                </div>
            </div>
        </div>
    </div>

    <div class="footer-note">
        Files stream directly between browsers without touching intermediate storage.
    </div>

    <script src="/js/webrtc-transfer.js?v={{ file_exists(public_path('js/webrtc-transfer.js')) ? filemtime(public_path('js/webrtc-transfer.js')) : time() }}"></script>
    <script>
        const code = "{{ $session->code }}";
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const binaryUrl = "/api/v1/webrtc/sessions/" + code + "/binary";

        const statusDesc = document.getElementById('statusDesc');
        const indicator = document.getElementById('connectionIndicator');
        const progressArea = document.getElementById('progressArea');
        const progressBar = document.getElementById('progressBar');
        const progressBytes = document.getElementById('progressBytes');
        const progressPercent = document.getElementById('progressPercent');

        document.getElementById('btnCopyLink').addEventListener('click', () => {
            const input = document.getElementById('receiveUrlInput');
            input.select();
            navigator.clipboard.writeText(input.value);
            document.getElementById('btnCopyLink').textContent = 'Copied!';
            setTimeout(() => { document.getElementById('btnCopyLink').textContent = 'Copy Link'; }, 2000);
        });

        const sender = window.AureusWebRtc.createSender({
            code: code,
            binaryUrl: binaryUrl,
            csrfToken: csrfToken,
            iceServers: @json($iceServers),
            chunkSize: {{ $chunkSize }},
            onStatusChange(state, desc) {
                statusDesc.textContent = desc;
                if (state === 'waiting_peer') {
                    indicator.textContent = 'Waiting for Recipient';
                    indicator.style.color = '#ca8a04';
                } else if (state === 'negotiating' || state === 'initializing') {
                    indicator.textContent = 'Connecting...';
                    indicator.style.color = '#ca8a04';
                } else if (state === 'connected' || state === 'streaming') {
                    indicator.textContent = 'Streaming Direct';
                    indicator.style.color = '#16a34a';
                    progressArea.style.display = 'block';
                } else if (state === 'completed') {
                    indicator.textContent = '✓ Delivered';
                    indicator.style.color = '#16a34a';
                }
            },
            onProgress(sent, total, pct) {
                progressArea.style.display = 'block';
                progressBar.style.width = pct + '%';
                progressPercent.textContent = pct + '%';
                progressBytes.textContent = Math.round(sent / 1024) + ' KB / ' + Math.round(total / 1024) + ' KB';
            },
            onComplete(res) {
                const bytes = res.totalBytes ? res.totalBytes.toLocaleString() + ' bytes' : '';
                statusDesc.textContent = 'Delivered to the recipient' + (bytes ? ' (' + bytes + ')' : '') + '.';
                indicator.textContent = '✓ Delivered';
                indicator.style.color = '#16a34a';

                // Print the hash of what we actually streamed, so it can be
                // compared with the recipient's screen by eye.
                if (res.sha256) {
                    const el = document.createElement('div');
                    el.style.cssText = 'margin-top:.6rem;font-size:.75rem;word-break:break-all;font-family:ui-monospace,Consolas,monospace;color:#64748b;';
                    el.textContent = 'SHA-256 sent: ' + res.sha256;
                    statusDesc.parentNode.appendChild(el);

                    if (res.peerSha256 && res.peerSha256 !== res.sha256) {
                        statusDesc.textContent = 'Warning: the recipient reported a different checksum.';
                        indicator.textContent = 'Checksum mismatch';
                        indicator.style.color = '#dc2626';
                    }
                }
            },
            onError(msg) {
                statusDesc.textContent = 'Error: ' + msg;
                indicator.textContent = 'Failed';
                indicator.style.color = '#dc2626';
            }
        });

        // Closing the tab mid-handshake should tear the connection down
        // rather than leave it dangling until the session TTL.
        window.addEventListener('beforeunload', () => sender.destroy());
    </script>
</body>
</html>
