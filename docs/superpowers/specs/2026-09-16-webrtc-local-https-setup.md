# Local HTTPS for manual WebRTC testing

Date: 2026-09-16
Status: working for same-machine tests; one elevated step needed for cross-machine

## Why

WebRTC's `RTCPeerConnection`/`RTCDataChannel` and the checksum verification
added to `public/js/webrtc-transfer.js` (`SubtleCrypto.digest`) both require
a [secure context](https://developer.mozilla.org/en-US/docs/Web/Security/Secure_Contexts).
`http://192.168.x.x:8000` is not one. `https://localhost` and any origin
with a browser-trusted certificate are.

## What's set up

- **mkcert** (`.dev-tools/mkcert.exe`) issued a local CA and a leaf
  certificate (`.dev-tools/dev-cert.pem` / `dev-key.pem`) covering
  `localhost`, `127.0.0.1`, `::1`, and `192.168.1.4` (this machine's current
  Wi-Fi IP — regenerate if it changes, see below). Valid until Dec 2028.
- **Caddy** (`.dev-tools/caddy.exe`) terminates TLS on `:8443` and reverse-
  proxies to `php artisan serve` on `127.0.0.1:8000`, adding
  `X-Forwarded-Proto: https`. `bootstrap/app.php` already has
  `trustProxies(at: '*')`, so Laravel generates `https://` URLs and treats
  the request as secure with no application change.
- Both servers are registered in `.claude/launch.json` as `aureus-erp`
  (backend, :8000) and `aureus-erp-https` (proxy, :8443).
- The mkcert root CA is trusted in this Windows account's certificate store
  (`certutil -addstore -user Root`), so **this machine's browsers show no
  certificate warning** for `https://localhost:8443` or
  `https://192.168.1.4:8443`.
- Verified in Claude's browser pane: `window.isSecureContext === true`,
  `crypto.subtle` present, `RTCPeerConnection` present, the login page
  renders, all 32 asset requests came back `https://` (no mixed content).

Everything under `.dev-tools/` is gitignored — binaries, cert, and private
key never get committed.

## What's NOT done: the firewall

`Test-NetConnection -ComputerName 192.168.1.4 -Port 8443` fails from this
machine itself. Caddy **is** listening on `::` (all interfaces, confirmed
via `Get-NetTCPConnection`), so the process is fine — Windows Firewall is
silently dropping the inbound connection because Caddy has never been
granted access, and adding that rule needs elevation:

```
New-NetFirewallRule : Access is denied.
```

That's a real permission boundary, not a bug — I did not attempt to bypass
it. **Run this once, in an Administrator PowerShell, before cross-machine
testing:**

```powershell
New-NetFirewallRule -DisplayName "Aureus ERP HTTPS dev (Caddy 8443)" -Direction Inbound -Protocol TCP -LocalPort 8443 -Action Allow -Profile Any
```

Right-click PowerShell → "Run as administrator" → paste that line. After
this, same-LAN devices (the macOS test) can reach
`https://192.168.1.4:8443`.

## Starting the servers

From Claude Code, `preview_start` handles both (see `.claude/launch.json`).
Manually, from the repo root, in two terminals:

```powershell
C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe artisan serve --host=127.0.0.1 --port=8000
```

```powershell
.dev-tools\caddy.exe run --config .dev-tools\Caddyfile --adapter caddyfile
```

## URLs for tomorrow's test plan

| Test | URL |
|---|---|
| Windows → Windows (two profiles/browsers, one machine) | `https://localhost:8443` on both — **works now**, no firewall needed |
| Windows → macOS (or any other device on the LAN) | `https://192.168.1.4:8443` — **needs the firewall rule above first** |

If the Wi-Fi IP changes (different network, DHCP renewal), regenerate the
cert with the new IP:

```bash
cd .dev-tools
CAROOT="$(pwd)/ca" ./mkcert.exe -cert-file dev-cert.pem -key-file dev-key.pem localhost 127.0.0.1 ::1 <new-ip>
```

## macOS trust

The self-signed cert will show a warning in Safari/Chrome on macOS until
its CA is trusted there too. **The CA's private key never leaves this
machine** — only the public `rootCA.pem` needs to travel:

1. Copy `.dev-tools/ca/rootCA.pem` to the Mac (AirDrop, USB, email to
   yourself — it's a public certificate, not a secret).
2. Double-click it to open Keychain Access, or:
   `sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain rootCA.pem`
3. In Keychain Access, find "mkcert …", expand Trust, set "When using this
   certificate" to **Always Trust**.

Without this step, Safari/Chrome will show an interstitial warning on
first visit; clicking through it still establishes a real TLS connection,
but whether that counts as a secure context for `SubtleCrypto` varies by
browser and version — importing the CA is the only path I can vouch for
with certainty, since I have no macOS device to verify the click-through
behavior myself.
