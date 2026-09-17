# How to Send Files From AureusERP to Another Computer

This is the **current, corrected** procedure — it includes fixes for real bugs found while testing this live (wrong `--env` handling, swapped pairing tokens, temp-file leaks). If you have an older copy of this guide, use this one instead.

Two instances are **already running and paired** on this machine right now (see §0). If you just want to send a file immediately, skip to **Part 3**.

---

## 0. Current state (if you're continuing right now)

| | Instance A | Instance B |
|---|---|---|
| URL | `http://127.0.0.1:8000` | `http://127.0.0.1:8001` |
| Login | your admin | `peer.admin@aureus.test` / `password` |
| Status | Paired, active | Paired, active |

If both are already up, jump to **Part 3**. If not (or you're setting this up fresh on a different machine), continue from Part 1.

---

## Part 1 — Set up a second instance (one-off)

### 1.1 Create the second database

```bash
mysql -u root -p -e "CREATE DATABASE aureuserp_peer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 1.2 Make a second env file

```bash
cp .env .env.peer
```

Edit `.env.peer` — change these **five** lines (note `APP_ENV`, which earlier guides missed):

```
APP_ENV=peer
APP_URL=http://127.0.0.1:8001
DB_DATABASE=aureuserp_peer
ACCOUNTING_PEERS_ALLOW_LOCAL=true
ACCOUNTING_PEERS_REQUIRE_HTTPS=false
```

> **Why `APP_ENV` matters:** Laravel's `artisan serve` only forwards the `APP_ENV` variable from your shell into the server process it spawns — nothing else in `.env.peer` reaches that child process automatically. Without this line, the "second instance" silently reads the **main** `.env` and hits the wrong database. This was a real bug found while testing.

### 1.3 Add the same two SSRF flags to your main `.env`

```
ACCOUNTING_PEERS_ALLOW_LOCAL=true
ACCOUNTING_PEERS_REQUIRE_HTTPS=false
```

Then `php artisan config:clear`.

### 1.4 Build and seed the second database

```bash
php artisan migrate --force --env=peer
```

If that says "Nothing to migrate" while tables are clearly missing (a known quirk in this repo), run the accounting plugin's migrations explicitly:

```bash
php artisan migrate --force --env=peer --path=plugins/webkul/accounting/database/migrations
```

Then seed it, and create a company/admin/vendor/expense-account (the seeder alone doesn't give you a usable login — see the tinker block in the project status doc, or copy the pattern from an existing `peer.admin@aureus.test` setup).

---

## Part 2 — Run both instances

**Three terminals.**

Instance A:
```bash
php artisan serve --port=8000 --no-reload
```

Instance B — **the `-d variables_order=EGPCS` flag is required**, not optional. Without it, PHP's shell-set `APP_ENV=peer` never reaches PHP's `$_ENV` superglobal on this Windows/PHP-CLI build (confirmed by direct testing — a plain `APP_ENV=peer php artisan serve` silently serves the *main* database):

```bash
APP_ENV=peer php -d variables_order=EGPCS artisan serve --port=8001 --no-reload
```

In PowerShell:
```powershell
$env:APP_ENV="peer"
php -d variables_order=EGPCS artisan serve --port=8001 --no-reload
```

Queue worker (delivery is a queued job):
```bash
php artisan queue:work --stop-when-empty --queue=default
```

**Verify B is really on the peer database** before doing anything else:
```bash
curl -X POST http://127.0.0.1:8001/api/v1/peer/pair -H "Content-Type: application/json" -d '{"code":"probe","endpoint_url":"http://127.0.0.1:8000","name":"probe"}'
```
Expect `{"message":"That pairing code is not valid."}` — a real, live JSON response. If you get a connection error, the server isn't listening yet; wait a few seconds and retry.

---

## Part 3 — Pair the two instances (skip if already paired)

**On B**, log in, go to **Accounting → Configuration → Peer Instances → Invite a peer**:
- Their organisation: `Instance A`
- Linked vendor: pick one (or link later — required before *accepting* anything, not before pairing)
- Copy the pairing code from the notification (shown once, expires in 15 min)

**On A**, log in, **Peer Instances → Enter a pairing code**:
- Their AureusERP URL: `http://127.0.0.1:8001`
- Pairing code: from B
- This instance's URL: `http://127.0.0.1:8000`

**Expected:** "Paired successfully" — both sides show **Active** with a "Last seen" timestamp once something is actually sent.

---

## Part 4 — Send a file, to a paired instance

**On A:**
1. **Accounting → Accounting → Documents** → **Upload document** (pick any real PDF/image/Excel file)
2. On that document's row: **Send to…**
3. Choose **A paired AureusERP instance** → select the peer → confirm

**Expected:** *"File queued for delivery."*

**On B:**
4. **Accounting → Accounting → Inbound Invoices** — the nav badge shows a count waiting
5. Click **View** on the new row — a blue **File** badge confirms it's a document, not an invoice
6. Click **Download file** — works *before* accepting, so you can inspect it first
7. Click **Accept** — *"File accepted"*. Note: **no bill is created** for a file (only invoice transmissions create bills). Find it afterward at **Accounting → Documents**.

### Verify it actually arrived, byte-for-byte

```bash
php artisan tinker --execute="echo \Webkul\Accounting\Models\OutboundTransmission::latest()->first()?->status->value;"
```
Expect `delivered`.

```bash
APP_ENV=peer php artisan tinker --execute="
\$i = \Webkul\Accounting\Models\InboundTransmission::where('payload_type','aureus.document.v1')->latest()->first();
echo \$i->status->value.' doc_id='.\$i->document_id.PHP_EOL;
"
```
Expect `received` (before accepting) or `accepted` (after), with a real `document_id`.

---

## Part 5 — Send a file to someone with **no** AureusERP

No pairing needed for this path at all.

1. On any Document row: **Send to…** → **Someone without AureusERP**
2. Enter their email → confirm
3. **Copy the link from the notification** — shown once, only its hash is stored, it cannot be recovered later

**Important limitation right now:** the system does **not** actually email anything — `MAIL_MAILER=log` means nothing leaves the server. You must copy that link and send it yourself (email, chat, however). The link itself works genuinely — open it in any browser, no login required, with a working **Download file** button.

To make it actually email automatically, real SMTP credentials are needed in `.env` (or Mailtrap for testing):
```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your@email.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
```
Then `php artisan config:clear`.

---

## Part 6 — Sending to a real second Windows PC (not just two ports on one machine)

Four things change:

1. **Bind to the network, not just localhost:**
   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   ```
2. **Open the firewall port** (Administrator terminal, on both PCs):
   ```bash
   netsh advfirewall firewall add rule name="AureusERP 8000" dir=in action=allow protocol=TCP localport=8000
   ```
3. **`APP_URL` must be the LAN IP**, not `127.0.0.1` — get it with `ipconfig`.
4. **Both SSRF flags are required** exactly as above — a LAN IP (`192.168.x.x`) is a private range and is refused by default without `ACCOUNTING_PEERS_ALLOW_LOCAL=true`.

Before configuring anything: `ping <other PC's IP>` from each side — if they can't ping, no amount of AureusERP config will fix that (different subnet, different network).

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| Login says "credentials do not match" on `:8001` | `APP_ENV=peer` isn't reaching the server process — see Part 2's `-d variables_order=EGPCS` note |
| Pairing succeeds, but every send returns 401 | Tokens stored backwards — this was a real bug, fixed; if you see it again, check `PeerPairingService::acceptPairingResponse()` is what the UI actually calls |
| Accepted invoice creates a bill with no number | Missing purchase journal on the receiving company — `accept()` now refuses cleanly with a clear message instead of creating one |
| "No purchase journal is configured" on accept | Create one: Accounting → Configuration → Journals → new Purchase-type journal |
| "Peer URL resolves to a private or loopback address" | `ACCOUNTING_PEERS_ALLOW_LOCAL=true` missing from the sending instance's env |
| Transmission stuck at `queued` forever | No queue worker running, or the other instance isn't reachable — check with `curl` directly |
| Files appearing in `%TEMP%\aur*.tmp` after sending | Should not happen anymore (fixed) — if you see this, it's a regression worth reporting |
