# Peer Invoice Exchange — Manual Test Walkthrough

Two AureusERP instances on one machine, exchanging a real invoice.

> **Why two databases and not just two ports:** both instances must have
> independent data or the test proves nothing — a single shared database
> would let "delivery" succeed without anything actually crossing the wire.

---

## Part 1 — Set up the second instance (one-off, ~10 min)

### 1.1 Create the second database

```bash
mysql -u root -p -e "CREATE DATABASE aureuserp_peer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 1.2 Make a second env file

```bash
cp .env .env.peer
```

Edit `.env.peer` and change exactly these four lines:

```
APP_URL=http://127.0.0.1:8001
DB_DATABASE=aureuserp_peer
ACCOUNTING_PEERS_ALLOW_LOCAL=true
ACCOUNTING_PEERS_REQUIRE_HTTPS=false
```

> **The last two matter.** `PeerEndpointGuard` refuses non-HTTPS URLs and any
> host resolving to loopback or a private range — that is the SSRF protection
> doing its job. These two flags switch it off, which is required for
> loopback here and for a LAN deployment (see the Appendix), but must stay
> `false` on any public-facing server.

### 1.3 Add the same two flags to your main `.env`

```
ACCOUNTING_PEERS_ALLOW_LOCAL=true
ACCOUNTING_PEERS_REQUIRE_HTTPS=false
```

### 1.4 Build the second instance's database

```bash
php artisan migrate --force --env=peer
```

If that reports "Nothing to migrate" while tables are clearly missing, this
project has a known plugin-migration discovery quirk — run the plugin
migrations explicitly:

```bash
php artisan migrate --force --env=peer --path=plugins/webkul/accounting/database/migrations
```

Then seed it so it has a company, chart of accounts and an admin user:

```bash
php artisan db:seed --force --env=peer
```

---

## Part 2 — Run both instances

Two terminals, left open:

```bash
php artisan serve --port=8000
```

```bash
php artisan serve --port=8001 --env=peer
```

A third for the queue, because delivery to a peer is a queued job:

```bash
php artisan queue:work --queue=default --stop-when-empty
```

> If the worker dies with `Maximum execution time of 300 seconds exceeded`,
> that is the pre-existing `AdminPanelProvider::set_time_limit(300)` issue,
> not this feature. Re-run the command — `--stop-when-empty` makes it exit
> cleanly once the queue drains.

---

## Part 3 — Pair the two instances

**Instance B (`:8001`) issues the invitation.**

1. Log in at `http://127.0.0.1:8001/admin` as an admin
2. Go to **Accounting → Configuration → Peer Instances**
3. Click **Invite a peer**
   - *Their organisation:* `Instance A`
   - *Linked vendor:* pick any vendor (or leave blank and link it in step 7)
4. Copy the pairing code from the notification

> The code is shown **once** — only its SHA-256 hash is stored, and it
> expires in 15 minutes.

**Instance A (`:8000`) redeems it.**

5. Log in at `http://127.0.0.1:8000/admin` as an admin
6. **Accounting → Configuration → Peer Instances → Enter a pairing code**
   - *Their organisation:* `Instance B`
   - *Their AureusERP URL:* `http://127.0.0.1:8001`
   - *Pairing code:* the code from step 4
   - *This instance's URL:* `http://127.0.0.1:8000`

**Expected:** "Paired successfully". Both instances now show the other as
**Active** with a Last seen timestamp.

7. **On Instance B**, if you skipped the vendor link: use **Link vendor** on
   the peer row. Accepting invoices is blocked until this is set — deliberately,
   so a peer can never cause a vendor to be invented.

---

## Part 4 — Send an invoice

**On Instance A (`:8000`):**

8. Go to **Accounting → Customers → Invoices**, open any invoice (or create
   and confirm one)
9. Click **Send to…** in the header
10. Choose **A paired AureusERP instance**, select `Instance B`, confirm

**Expected:** "Queued for delivery." The queue worker picks it up within a
second or two.

### What to check on the sending side

| Where | Expected |
|---|---|
| Queue terminal | `SendTransmissionJob` processed |
| DB `accounting_outbound_transmissions` | `status = delivered`, `delivered_at` set |

```bash
php artisan tinker --execute="echo \Webkul\Accounting\Models\OutboundTransmission::latest()->first()?->status->value;"
```

---

## Part 5 — Receive and accept

**On Instance B (`:8001`):**

11. Go to **Accounting → Accounting → Inbound Invoices** — the nav item
    carries a badge with the number waiting
12. Click **View** on the new row

**Expected:** sender name, their STRN, invoice number, line items. If the
line items do not add up to the total they claimed, a red **Total mismatch**
warning appears — amounts are recomputed locally rather than trusted.

13. Click **Accept**

**Expected:** "Draft bill created." Then check **Accounting → Vendors → Bills**
— a new bill exists in **Draft** state, with your *own* numbering, the
sender's invoice number in the Reference field, and the linked vendor as
partner.

> Nothing was posted. The peer's invoice produced a draft for a human to
> review — it never wrote to the ledger on its own.

---

## Part 6 — The security behaviours worth demonstrating

These are what make it more than a file transfer. Each is covered by an
automated test too (`tests/Feature/Peers/PeerExchangeRoundTripTest.php`).

### 6.1 A peer cannot invent a vendor

On Instance B, unlink the vendor from the peer, then try to accept another
invoice.

**Expected:** refused — *"Link peer 'Instance A' to a vendor before accepting
its invoices."*

### 6.2 A tampered payload is rejected

```bash
curl -i -X POST http://127.0.0.1:8001/api/v1/peer/transmissions \
  -H "Authorization: Bearer any-token" \
  -H "X-Aureus-Timestamp: $(date +%s)" \
  -H "X-Aureus-Nonce: $(uuidgen)" \
  -H "X-Aureus-Signature: deadbeef" \
  -H "Content-Type: application/json" \
  -d '{"reference":"forged","payload":{}}'
```

**Expected:** `401 Unauthorized` with a generic message — it never reveals
whether the token or the signature was the problem, because that would let
someone enumerate valid tokens.

Check it was recorded:

```bash
php artisan tinker --execute="echo \Webkul\Accounting\Models\DocumentAudit::where('action','peer_auth_failed')->count();"
```

### 6.3 Redelivery does not duplicate

Send the *same* invoice to the same peer twice.

**Expected:** Instance B still shows **one** inbound row. The second call
returns `200` with `duplicate: true` instead of creating a second invoice —
this is what makes a retry-after-a-lost-response safe.

### 6.4 Revoking cuts a peer off immediately

On Instance B: **Peer Instances → Revoke**. Then send again from A.

**Expected:** the transmission goes to `failed`, not `queued` forever, and
Instance B records nothing. Invoices already received are kept as audit
evidence rather than deleted.

### 6.5 Read-only roles cannot send or pair

Log in as `test.auditor@aureus.test`.

**Expected:** no **Peer Instances** in Configuration, no **Inbound Invoices**
in Accounting, and no **Send to…** button on any invoice.

| Role | Pair peers | Send | Review inbound |
|---|---|---|---|
| Admin | yes | yes | yes |
| Controller | no | yes | yes |
| AP Officer | no | no | yes |
| Internal Auditor | no | no | no |
| VP Finance | no | no | no |

> Pairing is admin-only on purpose: it creates a trust relationship with an
> outside organisation, which is an administrative act rather than
> bookkeeping.

---

## Part 7 — Sending to someone without AureusERP

14. On Instance A, open an invoice → **Send to…**
15. Choose **Someone without AureusERP**, enter any email address
16. Copy the secure link from the notification (shown **once** — only its
    hash is stored)
17. Open it in a private browser window

**Expected:** a clean public invoice page, no login, with a **Download
structured invoice (JSON)** button.

### Then check the failure mode

Change one character in the token and reload.

**Expected:** *"This link is no longer available"* — the exact same page you
would get for an expired or withdrawn link. Wrong, expired and cancelled are
deliberately indistinguishable.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| *"Peer URL resolves to a private or loopback address"* | `ACCOUNTING_PEERS_ALLOW_LOCAL=true` missing from the env file of the instance doing the pairing |
| *"That pairing code is not valid"* | Expired (15 min) or already used — issue a fresh one |
| Transmission stuck at `queued` | No queue worker running |
| *"No expense account is configured"* on accept | The receiving instance has no postable expense account; seed its chart of accounts |
| *"Currency 'PKR' does not exist on this instance"* | Receiving instance lacks that currency — run `IsoCurrencySeeder` |

---

# Appendix — Two separate Windows PCs on a LAN

Everything above assumed two ports on one machine. Across two real PCs,
four things change. Skipping any one of them produces a confusing failure.

Call them **PC-A** (sends) and **PC-B** (receives). Each needs its own
AureusERP install and its own database — they are genuinely separate
instances, not a shared one.

## A.1 Find each machine's LAN address

On each PC:

```
ipconfig
```

Use the **IPv4 Address** under your active adapter — typically
`192.168.x.x` or `10.x.x.x`. Example used below: PC-A `192.168.1.40`,
PC-B `192.168.1.50`.

> Avoid the Wi-Fi/Ethernet distinction biting you: if one PC is on Wi-Fi and
> the other on a cable behind a different subnet, they may not see each
> other at all. `ping 192.168.1.50` from PC-A before going further.

## A.2 Bind the server to the network, not just localhost

`php artisan serve` listens on `127.0.0.1` only by default, so the other PC
cannot reach it no matter what else is configured:

```
php artisan serve --host=0.0.0.0 --port=8000
```

## A.3 Open the port in Windows Firewall

Windows blocks inbound `8000` by default and fails **silently** from the
other machine (it looks like a hang, not a refusal). In an **Administrator**
terminal on each PC:

```
netsh advfirewall firewall add rule name="AureusERP 8000" dir=in action=allow protocol=TCP localport=8000
```

To undo later:

```
netsh advfirewall firewall delete rule name="AureusERP 8000"
```

## A.4 Set each `.env` to the LAN address

On **PC-A**:

```
APP_URL=http://192.168.1.40:8000
ACCOUNTING_PEERS_ALLOW_LOCAL=true
ACCOUNTING_PEERS_REQUIRE_HTTPS=false
```

On **PC-B**:

```
APP_URL=http://192.168.1.50:8000
ACCOUNTING_PEERS_ALLOW_LOCAL=true
ACCOUNTING_PEERS_REQUIRE_HTTPS=false
```

> **Both flags are mandatory here, and not only for testing.** A LAN address
> is a private range, which `PeerEndpointGuard` rejects by default as SSRF
> protection — verified: `http://192.168.1.50:8000` is refused with
> *"Peer URL resolves to a private or loopback address"* until
> `ALLOW_LOCAL` is set. Understand the trade-off: with it on, anyone who can
> enter a peer URL can make that server issue requests to other machines on
> your internal network. Acceptable on a trusted office LAN; not acceptable
> on a public-facing server.

Then on both:

```
php artisan config:clear
```

## A.5 Pair using LAN addresses

Follow **Part 3**, substituting real addresses:

- On PC-B, *Invite a peer* → copy the code
- On PC-A, *Enter a pairing code*:
  - *Their AureusERP URL:* `http://192.168.1.50:8000`
  - *This instance's URL:* `http://192.168.1.40:8000`

Everything from **Part 4** onward is unchanged.

## A.6 If the two PCs are NOT on the same network

Different buildings, or one behind a corporate firewall, is a different
problem — the design assumes the receiver is reachable. Options, roughly in
order of sanity:

| Approach | Notes |
|---|---|
| VPN between the sites | Both ends behave as one LAN; nothing above changes |
| Port-forward + public hostname + real TLS | Then turn **off** `ALLOW_LOCAL` and `REQUIRE_HTTPS=false` — a public endpoint should have neither |
| Host the receiver on a cloud VM | Simplest to reason about; it just has a real URL |

Do **not** port-forward plain HTTP to the internet: the pairing handshake and
every transmission would cross it in the clear, and the HMAC protects
integrity, not confidentiality.

## Troubleshooting the two-PC case

| Symptom | Cause |
|---|---|
| Browser on PC-A cannot open `http://192.168.1.50:8000` at all | Firewall rule missing (A.3), or server not bound with `--host=0.0.0.0` (A.2) |
| *"Peer URL resolves to a private or loopback address"* | `ACCOUNTING_PEERS_ALLOW_LOCAL=true` missing (A.4) |
| *"Peer URLs must use HTTPS"* | `ACCOUNTING_PEERS_REQUIRE_HTTPS=false` missing (A.4) |
| Pairing succeeds, but sending stalls at `queued` | No queue worker on PC-A, or PC-B cannot be reached back — check A.1 ping |
| Claim link opens on PC-A but not PC-B | `APP_URL` still `127.0.0.1`; the link embeds it |
