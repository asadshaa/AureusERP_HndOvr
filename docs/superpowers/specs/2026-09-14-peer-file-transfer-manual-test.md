# Sending Files Between Instances — Manual Walkthrough

How to send an actual **file** (PDF, scan, spreadsheet) from one AureusERP
to another, or to someone with no AureusERP at all.

> Setup (second instance, pairing) is in
> `2026-09-14-peer-exchange-manual-test.md` — Parts 1–3, plus the Appendix if
> your second instance is on another Windows PC. **Do that first.** This
> document assumes two paired, running instances.

---

## What actually crosses the wire

The file's bytes travel **inline, base64-encoded, inside the signed
request** — one atomic delivery, so there is never a window where the
receiver holds metadata for a file it cannot retrieve.

Consequences worth knowing before you test:

| | |
|---|---|
| **Size ceiling** | Checked against the *encoded* size. Base64 inflates ~33%, so a 20 MB file becomes ~27 MB on the wire. Default ceiling is 32 MB (`ACCOUNTING_PEERS_MAX_PAYLOAD`). |
| **Allowed types** | PDF, JPEG, PNG, WebP, CSV, XLS/XLSX, DOC/DOCX. An arriving file is re-validated against this list — the sender's claim about its type is not trusted. |
| **Integrity** | SHA-256 is recomputed from the received bytes. A mismatch is refused outright and nothing is stored. |
| **Storage** | Arrives through the normal `DocumentService` path, so it lands in Documents with a checksum and full audit history — not written straight to disk. |

---

## Part A — Send a file to a paired instance

### A.1 Put a file into Documents on the sender (PC-A)

1. **Accounting → Accounting → Documents**
2. **Upload document** — pick a real PDF, give it a title (e.g. `Supplier receipt`)

> Any document already attached to an invoice or bill works too. This is the
> same Documents library the rest of accounting uses; nothing separate.

### A.2 Send it

3. On that document's row, click **Send to…**
4. Choose **A paired AureusERP instance**, pick your peer, confirm

**Expected:** *"File queued for delivery — Sending "Supplier receipt" to
&lt;peer&gt;."*

> If the file is unreadable or over the ceiling, it fails **here**, in front
> of you, rather than silently on a queue worker later.

### A.3 Watch it go

The queue worker terminal should process a `SendTransmissionJob`. Then:

```bash
php artisan tinker --execute="$t = \Webkul\Accounting\Models\OutboundTransmission::latest()->first(); echo $t->payload_type.' | '.$t->status->value;"
```

**Expected:** `aureus.document.v1 | delivered`

---

## Part B — Receive it

### B.1 Find it (PC-B)

5. **Accounting → Accounting → Inbound Invoices** (the badge counts anything
   waiting, files included)

**Expected:** a new row with a blue **File** badge in the *Kind* column, and
the filename under *Reference*. The *Total* column is `—` — a file has no
invoice total.

### B.2 Inspect before accepting

6. Click **View**

**Expected:** sender, filename, MIME type, size, and a green confirmation
that it was *stored and checksum-verified on arrival*.

### B.3 Get the file

7. Click **Download file**

**Expected:** the original file, byte-identical, with its original filename.
This works **before** accepting — you can examine what you were sent before
deciding anything.

### B.4 Accept it

8. Click **Accept**

**Expected:** *"File accepted — The file is in Accounting > Documents."*
Note the wording differs from an invoice: **no draft bill is created**,
because a file is not an invoice. Confirm it under
**Accounting → Accounting → Documents**.

> Receiving already stored the file; accepting records the human decision.
> Those are deliberately separate — a file landing in your system is not the
> same as someone agreeing to it.

---

## Part C — Send a file to someone without AureusERP

9. On PC-A, on any document row: **Send to… → Someone without AureusERP**
10. Enter any email address, confirm
11. Copy the link from the notification — **shown once**, only its hash is stored
12. Open it in a private browser window

**Expected:** a clean public page — title, filename, type, size — and a
**Download file** button. No login.

### Then break it deliberately

13. Change one character in the token and reload

**Expected:** *"This link is no longer available."* The identical page you'd
get for an expired or withdrawn link — wrong, expired and cancelled are
indistinguishable on purpose, so nobody can probe for valid tokens.

---

## Part D — Failure behaviours worth showing a supervisor

Each is covered by an automated test in
`tests/Feature/Peers/PeerFileTransferTest.php`.

### D.1 A corrupted file is refused, and not retried

A file altered in transit fails its checksum. The receiver returns **422**,
not 500 — which matters: `SendTransmissionJob` treats 4xx as *terminal*, so
a permanently corrupt file fails once instead of burning five retries.

Verify nothing was stored:

```bash
php artisan tinker --execute="echo \Webkul\Accounting\Models\InboundTransmission::whereNull('document_id')->count();"
```

### D.2 A malicious filename cannot escape the folder

A sender claiming `../../../../windows/system32/evil.pdf` has the entire path
stripped; only `evil.pdf` survives. The filename is attacker-controlled input
that reaches the filesystem, so it is never used as given.

### D.3 Oversized files are refused before queueing

Lower the ceiling and try a large file:

```bash
php artisan tinker --execute="config(['accounting_peers.max_payload_bytes' => 1024]); echo 'ceiling lowered for this process only';"
```

**Expected** when sending: *"… is too large to transmit once encoded (X MB)."*
Rejected at the click, never queued.

### D.4 A read-only role cannot send files

Log in as `test.auditor@aureus.test` and open **Documents**.

**Expected:** no **Send to…** on any row. Sending needs
`accounting_send_transmissions`; Internal Auditor and VP Finance hold neither
it nor the ability to pair peers.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| No **Send to…** on a document | Missing `accounting_send_transmissions`, or no active peer exists yet |
| *"… has no uploaded file yet"* | The Document record exists but has no version — re-upload the file |
| *"too large to transmit once encoded"* | Encoded size exceeds `ACCOUNTING_PEERS_MAX_PAYLOAD`; raise it or send a smaller file |
| Receiver returns 422 | Checksum mismatch, or a MIME type outside the allowlist. Permanent — the sender will not retry |
| Row shows **File** but no **Download file** button | `document_id` is null, meaning storage failed on arrival; check the receiver's `accounting_documents` disk |
| Accepted a file but no bill appeared | Correct. Files never create bills — only invoice transmissions do |
