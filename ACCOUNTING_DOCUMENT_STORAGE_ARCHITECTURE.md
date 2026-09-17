# Accounting Document Storage — Architecture Decisions

Status as of 2026-09-10: Phases 1-4 complete and merged to `feature/accounting-plugin`. This
file records the decisions made so far and, per an explicit request, documents Google Drive
as a **future** option — not something to build now.

## What's built (Phases 1-4)

- **Storage**: a dedicated `accounting_documents` Laravel disk, switchable between local
  (dev/test default) and S3 via `DOC_STORAGE_DISK`. No AWS credentials required for local
  development. Provider abstraction (`DocumentStorageProvider` interface,
  `LocalDocumentStorageProvider` implementation) so the storage backend can change without
  touching business logic.
- **Domain**: `Document` / `DocumentVersion` / `DocumentAttachment` / `DocumentAudit` models,
  all company-isolated, permission-gated, checksummed (SHA-256, re-verified on every
  download), and fully audited — every upload, download, attach, detach, archive and restore
  is logged with who/when/from-where.
- **UI**: a central "Accounting → Documents" hub (search, filter, preview/download, version
  and audit history), plus a "Supporting documents" tab wired directly onto Customer Invoice
  and Bank Statement records.
- **The one authorized entry point is `DocumentService`.** Nothing else in the codebase reads
  or writes the underlying storage disk directly. This is what makes checksums, audit trail,
  and company isolation actually reliable — there's exactly one door.

MySQL/Aureus remains the sole authoritative source of accounting truth. Documents are
centralized supporting evidence, not a second accounting system.

## Future option: Google Drive as a shared document surface

The idea raised: let anyone on the ERP, from any machine, see and upload documents for
invoices, bank statements, and (potentially) chart-of-accounts records — and have Google
Drive be part of that, so a change made directly in Drive is reflected back in the ERP too.

Two ways to build toward that, in order of recommendation:

### Option 1 (Recommended): ERP → Google Drive, one-way mirror/export

The ERP stays the only writer. `DocumentService` continues to be the single authorized entry
point. A new, additive export step pushes a copy of each document (or a selected subset,
e.g. per-invoice folders) into a Drive folder structure, purely for browsing/sharing
convenience. Drive is never read back into the ERP.

- Preserves everything already built: single source of truth, immutable audit trail,
  checksum guarantees, company isolation.
- Low blast radius: if the export job fails or is disabled, nothing in the accounting domain
  is affected — it's a side effect, not a dependency.
- Gives people the "browse it like a shared drive" experience without giving up control of
  the record.
- Cost: no new authorization/versioning/conflict logic needed. Mainly a scheduled job or
  event listener plus Google Drive API credentials and a folder-mapping convention.

### Option 2 (Higher risk): bidirectional Drive ↔ ERP synchronization

Documents could be uploaded, replaced, or deleted directly in Drive by anyone with folder
access, and those changes would need to flow back into the ERP's own records.

This is a materially different and larger feature than anything in Phases 1-4, because it
introduces a **second writer** into a domain that currently has exactly one. Before this is
scoped as real work, it needs explicit answers to at least:

- **Source-of-truth rules**: when Drive and the ERP disagree about a document's current
  state, which one wins, and how is that decided programmatically (not "whoever asks first")?
- **Conflict resolution**: two edits to the same document in the same window — a real
  merge/lock strategy is required, not a last-write-wins default, since a silent overwrite of
  financial evidence is exactly the failure mode this whole feature exists to prevent.
- **Versioning**: does every Drive-side edit create a new `DocumentVersion`, the same way an
  ERP-side upload does today? How are Drive's own revision history and the ERP's version
  table kept consistent instead of becoming two disagreeing histories?
- **Authorization**: Drive's sharing/permission model and the ERP's company-isolation +
  `AccountingPermissions` model are not the same shape. A person with Drive folder access but
  no ERP permission (or vice versa) is a real gap to close, not an edge case to hand-wave.
- **Audit trail**: `DocumentAudit` currently captures every action with an authenticated ERP
  user and IP. A Drive-side change arrives via webhook/poll with none of that context by
  default — the audit trail either gets materially weaker or needs real work to preserve.
- **Deletion / replacement semantics**: what happens when a file is deleted or replaced in
  Drive — does that delete/archive the ERP's `Document`, or is Drive-side deletion ignored?
  Either answer needs to be a deliberate rule, not incidental behavior.

**This option is not being implemented now, and should not be started just because it's been
discussed.** If it's ever pursued, it should go through the same phased,
explicit-approval process as Phases 1-4 — starting with its own design pass on the six
questions above, not with code.
