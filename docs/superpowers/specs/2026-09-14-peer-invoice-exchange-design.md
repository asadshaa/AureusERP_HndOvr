# Peer Invoice Exchange — Design

**Status:** approved 2026-09-14. Implements AureusERP-to-AureusERP invoice/document
exchange, plus delivery to recipients who do not run AureusERP.

## Decisions taken with the user

| Question | Decision |
|---|---|
| Who are the peers? | Two **separate AureusERP instances** (cross-deployment) |
| Non-ERP recipients? | Yes — emailed **PDF attachment *and* a secure expiring claim link** |
| Payload format | **Bespoke versioned JSON** behind a swappable formatter interface (UBL later) |
| Trust model | **Pairing code**, then **HMAC-signed** requests (nonce + timestamp) |
| Topology | **Both instances reachable; push delivery** with a durable retry queue |
| Inbound behaviour | **Review queue**; a human accept creates a **draft** vendor bill |

## Approach

Approach B of three: a **separate peer-exchange subsystem** that shares the document
layer. It reuses `DocumentService` for bytes and the existing audit mechanism, and
leaves the intra-instance `DocumentTransfer` subsystem alone.

Rejected: extending `DocumentTransfer` (its `document_id` is a NOT NULL FK and an
invoice is a `Move`, not a Document; its parties are local `users` rows in one
company — wrong shape, and it would destabilise existing tests). Also rejected:
unifying everything (throws away working code for conceptual tidiness).

Naming keeps the two apart: **transfer** = internal user-to-user, **transmission** =
external peer/email.

## Data model

`accounting_peers` — one row per paired remote instance.
`company_id`, `name`, `endpoint_url`, `status` (pending/active/revoked),
`partner_id` (nullable FK — maps inbound invoices to a local vendor),
`outbound_token` (encrypted), `inbound_token_hash`, `signing_secret` (encrypted),
`paired_at`, `last_seen_at`, `revoked_at`.

`accounting_outbound_transmissions` — one row per send.
`peer_id` (null for external), `channel` (peer/external), `recipient_email`,
`transmittable_type`/`transmittable_id` (morph: Move or Document),
`status` (queued/sent/delivered/failed/expired/cancelled), `attempts`, `last_error`,
`payload_sha256`, `idempotency_key`, `delivered_at`,
`claim_token_hash`, `expires_at`.

`accounting_inbound_transmissions` — one row per receipt.
`peer_id`, `remote_reference`, `payload` (JSON verbatim), `payload_type`,
`status` (received/accepted/rejected), `reviewed_by`, `reviewed_at`,
`rejection_reason`, `created_move_id`, `document_id`.
**`unique(peer_id, remote_reference)`** — makes redelivery safe.

## Trust

Pairing: A generates a short-lived one-time code (endpoint + nonce); B submits it to
A's `/pair`; both sides exchange tokens and a shared signing secret in that one call
and store `active` peer rows.

Every subsequent request carries `Authorization: Bearer`, `X-Aureus-Timestamp`,
`X-Aureus-Nonce`, `X-Aureus-Signature` (HMAC-SHA256 over the **raw** body).
Verified in order: token hash -> peer active -> timestamp skew -> nonce unseen ->
signature. Any failure: 401 + audit row.

Credentials live in the DB (peers are runtime data, not env config), protected with
Laravel `encrypted` casts; only hashes are stored for values we verify.

## Permissions

`ManagePeers`, `SendTransmissions`, `ReviewInboundTransmissions` — deliberately three,
not one: pairing is administrative, sending is daily AP/AR work, and accepting writes
to the ledger. Collapsing them would undo the existing segregation of duties.

## Flows

**Outbound** is a manual Filament action, never automatic on post. The local row
commits *before* any network call (same principle as the Drive integration), then a
`SendTransmissionJob` (`tries=5`, backoff `[30,60,300,900,3600]`, mirroring
`SyncDocumentToDriveJob`) performs the signed POST. 2xx -> delivered; 4xx -> failed
with no retry; 5xx/timeout -> backoff.

**External channel** stores only `claim_token_hash`, emails the PDF attached plus a
claim link. The public claim page uses constant-time comparison, is rate limited, and
renders one neutral "no longer available" message for expired/invalid/cancelled alike
so tokens cannot be enumerated.

**Inbound** sits outside `auth:sanctum` (peers are not users) behind a
`VerifyPeerSignature` middleware. A duplicate `remote_reference` returns **200 with
the existing record** — redelivery is success, not error. Attachment bytes go through
`DocumentService::upload()`, never a direct storage write.

**Accept** creates a **draft** vendor bill (`Move`, `move_type=IN_INVOICE`,
`state=draft`) with its `MoveLine` rows. Mapping rules — the security-critical part:

| Field | Rule |
|---|---|
| Partner | From `peer.partner_id`; accept **blocked** if unset. Never auto-create partners. |
| GL accounts | **Never from the payload.** Local defaults only. |
| Tax | Matched by rate; no match -> flagged, never guessed. |
| Currency | Matched by ISO code; unknown -> accept blocked. |
| Amounts | Totals **recomputed locally**; mismatch shown as a warning. |

Principle: **a peer supplies data, never decisions.**

## Failure modes

1. Local commits, delivery fails -> intent preserved, retry action available.
2. Remote succeeded but response lost -> retry hits the unique constraint, peer
   returns the existing record, sender marks delivered. **Self-healing.**
3. Peer down -> backoff, then `failed` + `last_seen_at` staleness signal.
4. Peer revoked mid-flight -> middleware rejects; queued jobs re-check status before
   sending. Inbound records retained as audit evidence, never deleted.

## Security edges

SSRF is the sharp one: `endpoint_url` is operator-supplied and we POST to it. HTTPS
only, private/loopback ranges rejected by default, with a **config-gated allowlist**
for the two-port localhost demo that is off by default in production.

Also: nonce replay cache (TTL = 2x skew), +/-5 min configurable skew, `hash_equals()`
everywhere, body size checked before parsing and base64 size checked before decoding,
rate limiting on the inbound endpoint and the claim page, and `company_id` derived
from the authenticated user or peer row — **never** from request input (the existing
REST controllers accept it from the client; that is a known defect, not a pattern).

## Testing

Avoid `TestBootstrapHelper` (its `AccountSeeder::run()` null-company bug crashes whole
files); follow the `DocumentServiceTest.php` pattern instead.

Layers: formatter unit; signature-verification unit (tamper/stale/replay/revoked);
outbound feature (`Http::fake()` + `Queue::fake()`); inbound feature (dedupe, bad
signature); accept feature (partner unlinked, unknown currency, total mismatch,
reject writes nothing); and an end-to-end round-trip integration test built first as
a walking skeleton.

## Pre-existing defects found while designing this

Uncommitted `DocumentTransfer` work is **scaffolded but non-functional**:
`DocumentTransferService` references `DocumentAuditAction::TransferSent`,
`::TransferDelivered` and `::TransferCancelled`, none of which exist in the enum (any
call fatals); its migration is absent from `hasMigrations`; its route file is never
loaded because `AccountingServiceProvider` lacks `hasRoute('api')`; and its Filament
UI was never built. Out of scope here, but it should be finished or removed rather
than left broken in the tree.
