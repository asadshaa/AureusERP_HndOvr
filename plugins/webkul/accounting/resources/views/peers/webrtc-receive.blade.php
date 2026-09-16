<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>P2P Direct Transfer — Aureus ERP</title>
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
            --danger: #dc2626;
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
            max-width: 32rem;
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
        .input-group {
            display: flex;
            gap: .5rem;
            margin-bottom: 1.25rem;
        }
        input.code-input {
            flex: 1;
            padding: .75rem 1rem;
            font-size: 1.1rem;
            font-weight: 600;
            letter-spacing: .1em;
            text-transform: uppercase;
            border: 2px solid var(--border);
            border-radius: 10px;
            outline: none;
            transition: border-color .15s;
        }
        input.code-input:focus {
            border-color: var(--primary);
        }
        button.btn {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: .75rem 1.25rem;
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s, opacity .15s;
        }
        button.btn:hover {
            background: var(--primary-hover);
        }
        button.btn:disabled {
            opacity: .6;
            cursor: not-allowed;
        }
        .status-box {
            background: #f1f5f9;
            border-radius: 12px;
            padding: 1.25rem;
            margin-top: 1rem;
            border: 1px solid var(--border);
        }
        .status-title {
            font-size: .85rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: .4rem;
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
        .file-card {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1.25rem;
        }
        .file-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: .25rem;
        }
        .file-sub {
            font-size: .85rem;
            color: var(--text-muted);
        }
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: .75rem;
            margin-top: 1.5rem;
        }
        .btn-download {
            background: var(--success);
            color: #fff;
            text-align: center;
            text-decoration: none;
            padding: .85rem;
            border-radius: 10px;
            font-weight: 600;
            display: block;
        }
        .btn-download:hover {
            background: #15803d;
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
            <span class="badge">WebRTC P2P</span>
            <span style="font-weight: 600; font-size: .95rem; color: var(--text-muted);">Aureus ERP</span>
        </div>

        <h1>Receive Invoice or Document</h1>
        <p class="subtitle">Enter the transfer code from the sender to open a direct, encrypted browser-to-browser connection. You both need to stay online while the file transfers.</p>

        <div class="input-group">
            <input type="text" id="transferCode" class="code-input" placeholder="e.g. AUR-4F7K2-9QXMB" value="{{ $initialCode }}" maxlength="24" autocomplete="off" spellcheck="false">
            <button id="btnConnect" class="btn">Connect</button>
        </div>

        <div id="statusBox" class="status-box" style="display: none;">
            <div class="status-title">Connection Status</div>
            <div id="statusDesc" class="status-desc">Initializing...</div>

            <div id="stallArea" style="display: none; margin-top: .75rem; padding-top: .75rem; border-top: 1px solid var(--border);">
                <div id="stallText" style="font-size: .85rem; color: var(--text-muted);"></div>
            </div>

            <div id="progressArea" style="display: none;">
                <div class="progress-bar-container">
                    <div id="progressBar" class="progress-bar"></div>
                </div>
                <div class="progress-meta">
                    <span id="progressBytes">0 KB / 0 KB</span>
                    <span id="progressPercent">0%</span>
                </div>
            </div>
        </div>

        <div id="fileArea" class="file-card" style="display: none;">
            <div id="fileTitle" class="file-title">Invoice #</div>
            <div id="fileSub" class="file-sub">PDF Document</div>

            <div id="integrityInfo" style="margin:.6rem 0 .9rem; font-size:.8rem; line-height:1.5;"></div>

            <div class="action-buttons">
                <a id="downloadLink" href="#" download="" class="btn-download">Download file</a>
            </div>
        </div>
    </div>

    <div class="footer-note">
        Transferred directly peer-to-peer via encrypted RTCDataChannel (DTLS/SCTP).
    </div>

    <script src="/js/webrtc-transfer.js?v={{ file_exists(public_path('js/webrtc-transfer.js')) ? filemtime(public_path('js/webrtc-transfer.js')) : time() }}"></script>
    <script>
        let currentReceiver = null;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const input = document.getElementById('transferCode');
        const btnConnect = document.getElementById('btnConnect');
        const statusBox = document.getElementById('statusBox');
        const statusDesc = document.getElementById('statusDesc');
        const stallArea = document.getElementById('stallArea');
        const stallText = document.getElementById('stallText');
        const progressArea = document.getElementById('progressArea');
        const progressBar = document.getElementById('progressBar');
        const progressBytes = document.getElementById('progressBytes');
        const progressPercent = document.getElementById('progressPercent');
        const fileArea = document.getElementById('fileArea');
        const fileTitle = document.getElementById('fileTitle');
        const fileSub = document.getElementById('fileSub');
        const downloadLink = document.getElementById('downloadLink');
        const integrityInfo = document.getElementById('integrityInfo');

        btnConnect.addEventListener('click', () => {
            const code = input.value.trim();
            if (!code) return;
            startTransfer(code);
        });

        // Auto-connect if initialCode was present in URL
        if (input.value.trim()) {
            startTransfer(input.value.trim());
        }

        function startTransfer(code) {
            btnConnect.disabled = true;
            input.disabled = true;
            statusBox.style.display = 'block';
            stallArea.style.display = 'none';
            fileArea.style.display = 'none';

            if (currentReceiver) {
                currentReceiver.destroy();
            }

            currentReceiver = window.AureusWebRtc.createReceiver({
                code: code,
                csrfToken: csrfToken,
                onStatusChange(state, desc) {
                    statusDesc.textContent = desc;
                    if (state === 'transferring') {
                        progressArea.style.display = 'block';
                        stallArea.style.display = 'none';
                    }
                },
                onConnectionStalled(msg) {
                    stallArea.style.display = 'block';
                    stallText.textContent = msg || '';
                },
                onMetadata(meta) {
                    if (meta) {
                        fileTitle.textContent = meta.title || meta.filename || 'File transfer';
                        fileSub.textContent = meta.filename || '';
                    }
                },
                onProgress(received, total, pct) {
                    progressArea.style.display = 'block';
                    stallArea.style.display = 'none';
                    progressBar.style.width = pct + '%';
                    progressPercent.textContent = pct + '%';
                    const kbReceived = Math.round(received / 1024);
                    const kbTotal = Math.round(total / 1024);
                    progressBytes.textContent = `${kbReceived} KB / ${kbTotal} KB`;
                },
                onFileReceived({ blobUrl, filename, size, integrity }) {
                    fileArea.style.display = 'block';
                    stallArea.style.display = 'none';
                    downloadLink.href = blobUrl;
                    downloadLink.download = filename;

                    // Shown so the recipient can compare it against the
                    // sender's console without taking either side's word.
                    const rows = [`<div><strong>${size.toLocaleString()} bytes</strong> received</div>`];

                    if (integrity && integrity.method === 'sha256') {
                        rows.push('<div style="color:#16a34a;font-weight:600;">&check; SHA-256 verified against the sender</div>');
                    } else {
                        rows.push('<div style="color:#ca8a04;font-weight:600;">Size verified &mdash; SHA-256 not checked</div>');
                        if (integrity && integrity.note) {
                            rows.push(`<div style="color:var(--text-muted);">${integrity.note}</div>`);
                        }
                    }

                    if (integrity && integrity.sha256) {
                        rows.push(`<div style="word-break:break-all;font-family:ui-monospace,Consolas,monospace;color:var(--text-muted);margin-top:.3rem;">${integrity.sha256}</div>`);
                    }

                    integrityInfo.innerHTML = rows.join('');
                },
                onError(errMsg) {
                    statusDesc.textContent = 'Error: ' + errMsg;
                    btnConnect.disabled = false;
                    input.disabled = false;
                }
            });
        }
    </script>
</body>
</html>
