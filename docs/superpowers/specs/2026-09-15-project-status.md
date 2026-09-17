# AureusERP — Project Status

**Repo:** `C:\Intern\Handover\Erp` · **Branch:** `feature/accounting-plugin` · **Stack:** Laravel 13, PHP 8.3, Filament 5, MySQL, Pest

This is the full state of the project as of 2026-09-15 — everything built, tested, running, and still outstanding. Written to be handed to a supervisor or picked up fresh in a new session.

---

## 1. What this branch contains, top to bottom

### 1a. Google Drive document sync (built in an earlier session)
Existing architecture extended, not replaced: `DocumentService` (upload/versioning/checksum/audit), a `DocumentStorageProvider` interface (swappable local/S3), and a new `DriveSyncService` that exports documents to Google Drive per company, with idempotent folder resolution and retryable sync jobs. Gaps found and closed: Payment records (not a `Move` subclass) had no document-attachment support at all; Journal Entry and Payment Evidence had no dedicated Drive folder templates. Verified against a real Google Drive account.

### 1b. 4-role manual test walkthrough (in progress, paused)
A structured, live-browser test of the four finance roles against the segregation-of-duties model (`AccountingPermissions::apOfficer()/controller()/internalAuditor()/vpFinance()`), for presentation to your supervisor.

| Role | Status |
|---|---|
| AP Officer | ✅ Complete |
| Controller | ✅ Complete (2.1–2.7, including two real permission-boundary confirmations) |
| Internal Auditor | 🟡 In progress — 3.1–3.4 done; 3.5–3.7 not started |
| VP Finance | ⬜ Not started |

Real bugs found and fixed during this pass:
- **Three genuinely exploitable "unguarded Create button" bugs** — `ManualAdjustmentResource`, `JournalEntryResource`, and Vendor's `BankAccountsRelationManager` each had a header/relation-manager `CreateAction` with no `->authorize()` call, letting a view-only role (Internal Auditor) actually submit a create despite having no create permission. All three fixed; a background task was spawned to sweep the remaining unguarded `CreateAction`s in Report Templates / Business Rules / FS Tags / Party Classifications / Import Profiles (not currently reachable by any tested role, so lower priority).
- **`ManualAdjustmentResource` has no `view` route** — a role holding only `ViewManualAdjustments` (Internal Auditor, VP Finance) can see the list but can't open a record at all, defeating the point of that permission. Flagged as a follow-up task, not yet built.
- **`test.auditor`/`test.controller`/`test.vpfinance` had `resource_permission = individual`** — a row-level scoping trait that restricted them to only records *they personally created*, which for an oversight role is always zero. This is why Journal Entries appeared empty for the Auditor. **You still need to fix this** — I was blocked by the permission classifier from changing it via tinker; either approve that action or set it yourself in Security → Users (change "Resource permission" to Global for those three accounts).

### 1c. Peer-to-peer invoice & document exchange (built this session — the bulk of recent work)
Covered in full below.

---

## 2. Peer exchange — architecture

**The question this answers:** how does AureusERP send an invoice or file to *another* AureusERP instance, or to someone with no ERP at all?

**Not WebRTC, not libp2p** — deliberately. Those solve browser-NAT-traversal and decentralized-peer-discovery problems this system doesn't have, and neither has a usable PHP implementation. What's built is the same architectural family as real B2B e-invoicing standards (Peppol, AS2/AS4): **signed, point-to-point HTTPS between two known, explicitly-paired parties.**

### Three new database tables (accounting plugin)
- **`accounting_peers`** — a paired remote instance: endpoint URL, encrypted signing secret, hashed inbound token, linked local vendor (`partner_id`)
- **`accounting_outbound_transmissions`** — one row per send; committed *before* any network call so a transport failure never loses intent
- **`accounting_inbound_transmissions`** — evidence of what a peer sent; nothing touches the ledger until a human accepts

### Trust model
1. **Pairing**: one instance issues a one-time code (15 min TTL, hash-only stored); the other redeems it over `POST /api/v1/peer/pair`, and both sides end up holding a shared HMAC secret plus tokens for each direction
2. **Every request after that** carries `Authorization: Bearer`, `X-Aureus-Timestamp`, `X-Aureus-Nonce`, `X-Aureus-Signature` (HMAC-SHA256 over the **raw** body — a proxy altering an amount invalidates the signature)
3. Verified in order: token hash → peer active → timestamp within skew → nonce unseen (replay cache) → signature match. Any failure is a generic `401` (never reveals which check failed) and is audited under `peer_auth_failed`

### SSRF protection
`PeerEndpointGuard` refuses non-HTTPS and any hostname resolving to a private/loopback range by default. `ACCOUNTING_PEERS_ALLOW_LOCAL=true` + `ACCOUNTING_PEERS_REQUIRE_HTTPS=false` switch this off — required for `localhost`/LAN testing, **must stay off in production**.

### The three ways to send something
| Recipient | Mechanism | What they get |
|---|---|---|
| Another AureusERP (peer) | Signed HTTPS POST | Structured data → lands in a review queue → **human accept** creates a **draft** bill (never auto-posted) |
| Someone without AureusERP | Emailed secure claim link + attachment | A public page (no login), downloadable file/PDF, expiring token |
| — | — | — |

Both invoices *and* files (documents) can go either route. **A peer supplies data, never decisions** — it can't invent a vendor, choose a GL account, or assert a total; everything is recomputed/mapped locally, and accept is blocked until the peer is linked to a real local vendor.

### Permissions (three, deliberately separate)
`ManagePeers` (pair/revoke — admin-only act), `SendTransmissions` (AP/AR daily work), `ReviewInboundTransmissions` (accepting writes to the ledger). AP Officer has review (bills are their job) but not send/pair; Controller has send+review but not pair; Internal Auditor/VP Finance have none.

---

## 3. What's proven to work — real, not mocked

Everything below was run as **actual HTTP traffic between two live running instances**, not just the test suite.

- ✅ Full pairing handshake, real `/pair` call, both sides end up with matching tokens
- ✅ Invoice sent `:8000 → :8001`, queued → delivered → accepted → **draft bill created** with correct numbering, correct journal, and currency mapped by **ISO code** even though the two databases use different numeric currency IDs (159 vs 105) — proves the mapping is genuinely code-based, not an accidental same-ID coincidence
- ✅ File sent `:8000 → :8001`, byte-for-byte identical on arrival, checksum-verified
- ✅ Tampered signature, replayed nonce, stale timestamp — all correctly rejected with a generic 401
- ✅ Redelivery (same idempotency key twice) — returns the existing record, does not duplicate

**Test suite: 15 tests passing, 78 assertions** across `PeerExchangeRoundTripTest`, `PeerFileTransferTest`, `PeerPairingHandshakeTest`, `PeerTempFileCleanupTest`.

## 4. Real bugs found and fixed while proving it live

None of these were caught by the test suite alone — each needed real HTTP traffic or a background adversarial audit to surface.

1. **Pairing tokens stored backwards** (`PeerResource`) — every signed send 401'd after pairing. Fixed by centralizing the mapping in `PeerPairingService::acceptPairingResponse()` so the UI can't reintroduce the swap.
2. **`accept()` never resolved a purchase journal** — draft bills were created with `journal_id = null`, meaning `Move::computeName()` returns early and the bill sits **unnumbered forever**. Fixed to resolve the buyer's purchase journal exactly like the native Create Bill page, and refuse cleanly if none exists.
3. **Every received file leaked a plaintext copy in `%TEMP%` forever** — confirmed via a 6-lens adversarial audit (3/3 verifier votes), then reproduced and fixed live: the decode buffer had no cleanup path, on success *or* failure. Fixed with a `finally` block; verified against real disk state after a live send (temp file count unchanged from baseline).
4. **Every emailed invoice orphaned a temp file** — `tempnam()` creates a real file, then code discarded that name by string-appending `.pdf`, so cleanup never found the real file. Fixed to use `tempnam()`'s actual path.
5. **`--env=peer` on `php artisan serve` silently does nothing** — Laravel's `ServeCommand` only forwards `APP_ENV` to the child PHP process, and a naive `.env.peer` copy still says `APP_ENV=local`, so the second "instance" was quietly hitting the *main* database. Root-caused and fixed (`.env.peer` sets `APP_ENV=peer`; the launch command needs `-d variables_order=EGPCS` — see the test guide).

## 5. Unverified findings — flagged, not fixed

A background adversarial audit (6 independent reviewers + 3-vote verification per finding) hit your session's rate limit partway through verification (144 of ~156 verifier calls errored). The findings below were **raised but never confirmed** — don't treat them as bugs, but they're worth a follow-up pass once the limit resets:

- HTTP client following redirects past the SSRF guard (checked once at pairing, not re-checked at send time)
- Claim tokens/attachments written in plaintext to `storage/logs` when `MAIL_MAILER=log`
- `dns_get_record` behavior for `localhost`/bracketed IPv6/Windows computer names
- Sender never told when an emailed claim link failed to send (failure is logged and swallowed by design, but silently — no UI signal)
- Several manual-test-doc command corrections (bash-only syntax, `--env=peer` — since superseded by fix #5 above)

## 6. Current live state (right now)

| | Instance A (main) | Instance B (peer) |
|---|---|---|
| URL | `http://127.0.0.1:8000` | `http://127.0.0.1:8001` |
| Database | `aureuserp` | `aureuserp_peer` |
| Login | your admin | `peer.admin@aureus.test` / `password` |
| Company | My Company | Peer Trading Co |
| Status | **Running now** | **Running now** |

They are currently **paired and active**, with a linked vendor (`Main Instance Supplier`) and a purchase journal configured on B. Several real invoices and files have already been exchanged successfully between them.

## 7. Outstanding / needs a decision from you

1. **Set `test.auditor`/`test.controller`/`test.vpfinance` to `resource_permission = Global`** (Security → Users) — I was blocked from doing this via tinker.
2. **Finish the 4-role walkthrough** — Internal Auditor 3.5–3.7, all of VP Finance.
3. **Add a `view` route to `ManualAdjustmentResource`** so `ViewManualAdjustments`-only roles can actually open a record (spawned as background task, not started).
4. **Sweep remaining unguarded `CreateAction`s** in Report Templates/Business Rules/FS Tags/etc. (spawned as background task).
5. **Decide on email delivery**: currently `MAIL_MAILER=log` — nothing is actually emailed, links are shown once in the UI for you to copy/send manually. Needs real SMTP credentials (or Mailtrap for testing) to actually deliver.
6. **Re-run the unverified audit findings** once the rate limit resets, particularly the redirect/SSRF one.
7. Nothing has been committed to git yet — 41 files changed/added, all uncommitted on `feature/accounting-plugin`.
