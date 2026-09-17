# WebRTC browser-to-browser document transfer — design and security review

Date: 2026-09-16
Status: implemented (hardened from an existing untracked prototype)

## What this is

Direct browser-to-browser transfer of an invoice PDF or a stored document
between two people, over an encrypted `RTCDataChannel`. The server brokers
the WebRTC handshake (SDP offer/answer plus ICE candidates) and nothing
else. File bytes never traverse the server between the two parties.

## Why this is not a duplicate of the peer exchange

`docs/superpowers/specs/2026-09-15-project-status.md` line 38 says, of the
instance-to-instance exchange:

> "Not WebRTC, not libp2p — deliberately."

That rejection is correct **for its own problem** and does not apply here.
Two servers with stable public endpoints do not need NAT traversal, so
WebRTC would have been pure overhead. Two humans on laptops behind
different corporate NATs are the case WebRTC exists for. These are
different peers solving different problems:

| | Peer exchange (committed, `7674125`) | This feature |
|---|---|---|
| Peers | Two ERP *instances* | Two *people's browsers* |
| Transport | Signed server-to-server HTTPS | DTLS-encrypted `RTCDataChannel` |
| Identity | HMAC handshake, long-lived shared secret | Ephemeral session code, no identity |
| Liveness | Store-and-forward, retried for an hour | Both parties online simultaneously |
| Writes to ledger | Yes, via reviewed inbound queue | **No — see "Deliberate omission"** |

`docs/superpowers/plans/2026-09-14-document-peer-transfer-foundation.md`
line 7 already specified this as a planned phase that would "swap the
delivery mechanism while reusing every table, permission and audit action
built here." This implementation follows that mandate: it adds no new
permission, no new audit action, and no parallel document store.

## The confidentiality decision

The foundation plan left one question open (lines 33–39):

> "The only justification for peer transport that survives the constraints
> above is confidentiality: WebRTC's DTLS is mandatory, so even a TURN
> relay sees ciphertext only... If confidentiality is the actual driver,
> then server-path delivery defeats the purpose, because the server holds
> plaintext. In that case the fallback must become 'transfer unavailable,
> both devices must be online' rather than a server relay."

**Resolved: confidentiality is the driver, so there is no server relay.**

The prototype had one (`public/js/webrtc-transfer.js:495` — the *receiver*
fetched the file from `GET /api/v1/webrtc/sessions/{code}/binary` after a
12-second timeout). That fallback simultaneously destroyed the only reason
to use WebRTC and opened the worst hole in the feature, because serving
that endpoint to the recipient means serving it to a user of another
company. It has been removed. A connection that cannot be established now
fails visibly and tells both parties to retry while both are online.

`binary` survives only as the **sender's own browser reading its own file**
to feed into the data channel, and is now restricted to the session creator.

## Deliberate omission: no ledger writes

The prototype had a `POST sessions/{code}/ingest` endpoint that wrote an
`InboundTransmission` — the same review queue whose `accept()` books a
draft vendor bill. It has been removed rather than secured.

`accounting_inbound_transmissions.peer_id` is `NOT NULL` by design: an
inbound accounting record must be attributable to an authenticated peer.
A WebRTC counterparty is, by construction, *whoever held the session code*
— there is no handshake and no identity. The prototype resolved that
mismatch by manufacturing a `Peer` row with `status = Active` and
`paired_at = now()`, bypassing `PeerPairingService` entirely, or by
attaching the payload to an arbitrary existing peer via `->first()`.

Either way the result was attacker-shaped JSON sitting in a reviewer's
queue wearing a real vendor's name, one click from becoming a draft bill.
Forging a trust relationship to satisfy a foreign key is not a workaround;
the foreign key was the control.

A recipient who wants a received invoice in their books uses one of the two
paths that already exist and already authenticate the sender:

1. Pair the instances and use the committed peer exchange, or
2. Upload the received PDF through `DocumentService` like any other document.

## Flow

1. Sender picks **Direct browser transfer** on an invoice or document.
2. `POST /api/v1/webrtc/sessions` → creates a session, audits
   `TransmissionSent`, returns a code and a receive URL.
3. Sender opens `/p2p/send/{code}`, creates the offer, posts it.
4. Recipient opens `/p2p/receive/{code}` (or types the code), fetches the
   offer, posts an answer. Both sides trickle ICE candidates through
   `POST .../candidate` and read the other side's with `GET .../poll`.
5. The data channel opens. The sender's browser fetches its own bytes from
   `GET .../binary` and streams them in 16 KB chunks.
6. Recipient's browser reassembles, verifies size, saves via a blob URL.
7. `POST .../complete` marks the session done and audits
   `TransmissionDelivered`.

## Security properties

| Control | Implementation |
|---|---|
| Session code | 60 bits, Crockford base32 (no I/L/O/U), `AUR-XXXX-XXXX-XXXX` |
| Code lifetime | 15 min (`webrtc.session_ttl_minutes`), enforced on every read |
| Replay | Terminal statuses are not connectable; `answer` is single-shot |
| Byte access | `binary` is creator-only, company-checked, permission-checked |
| Metadata | Public `show`/`poll` return display fields only — no invoice payload |
| Company isolation | Derived from the actor, never accepted from the request |
| Permissions | Reuses `AccountingPermissions::SendTransmissions`; documents additionally require `DownloadDocuments` via `DocumentService::retrieveContents()` |
| Audit | `TransmissionSent`, `TransmissionDelivered`, `AccessDenied` — existing cases, `metadata.transport = 'webrtc'` |
| Rate limit | `throttle:60,1` on the anonymous signaling endpoints |

### What an attacker who guesses a code still gets

Nothing but a handshake. `show` returns the document title, filename and
MIME type so the recipient knows what they are accepting — the same
disclosure the claim-link page already makes — plus the SDP offer, which is
useless without the sender completing a DTLS handshake. They cannot fetch
bytes (`binary` is creator-only) and they cannot write anything.

They *can* answer the session before the intended recipient, which is the
residual risk inherent to a bearer code. At 60 bits with a 15-minute TTL
and 60 requests/minute, an attacker gets roughly one guess in 10^13 per
session. The mitigation is the code's entropy, which is why it was raised.

## Known limitations

- **No TURN server is configured.** `config/webrtc.php` ships three Google
  STUN servers. STUN alone fails for symmetric NAT, which is common on
  corporate networks. Without a TURN relay some transfers between distant
  networks will not connect. Adding TURN is a deployment decision
  (credentials, bandwidth cost), not a code change.
- **Both parties must be online at the same time.** This is inherent, and
  now explicit rather than papered over by a server relay.
- **Signaling is 1-second HTTP polling**, not WebSockets. This is a
  deliberate fit to the stack — the app runs no WebSocket daemon, and
  polling for the ~10 seconds a handshake takes is cheaper than operating
  one.
- **`config/webrtc.php` sets `max_payload_bytes` to 32 MB** while
  `DocumentService::MAX_FILE_SIZE_BYTES` is 20 MB. No document above 20 MB
  can exist, so the ceiling is unreachable; it is left as the transport's
  own guard rather than tightened, to avoid implying a second limit.
