# Architecture Decisions

Status: Documented / Active
Related: [[System Architecture]], [[Business Decisions]], [[Document Architecture]], [[Authorization & Company Scoping]], [[Bank Import]]

This Architecture Decision Record (ADR) documents foundational technical choices, architectural invariants, and rejected alternatives in Aureus ERP.

---

## ADR-001: MySQL / Aureus ERP as Sole Authoritative Source of Truth
- **Context**: Considerations were raised regarding using Google Drive or external spreadsheets as live shared collaborative surfaces where users could modify accounting documents directly.
- **Decision**: MySQL database and Aureus ERP application layer remain the **sole authoritative record** for all financial documents, journal moves, and balances.
- **Consequences**: External cloud storage (Google Drive, AWS S3) serves exclusively as an immutable binary backup payload store. Drive is never read back into the ERP to alter existing financial records.

---

## ADR-002: Single-Door Document Management (`DocumentService`)
- **Context**: Various modules (Invoices, Bills, Bank Imports, Expense claims) require attaching files and calculating SHA-256 integrity checksums.
- **Decision**: All document uploads, downloads, attachments, versionings, and audit logs must route exclusively through `DocumentService`. Direct filesystem or disk manipulation is strictly prohibited.
- **Consequences**: Cryptographic integrity and immutable audit logging cannot be bypassed. Pluggable storage backends (Local disk vs S3) can be swapped transparently without modifying business modules.

---

## ADR-003: Unified Move Ledger (`AccountMove` / `AccountMoveLine`)
- **Context**: Invoices, vendor bills, payments, bank fees, payroll, and adjustments could be modeled in isolated domain tables.
- **Decision**: All financial operations converge into the double-entry `accounts_account_moves` and `accounts_account_move_lines` schema.
- **Consequences**: Trial balance, balance sheet, and P&L statements pull directly from a single canonical data structure. Multi-currency and debit/credit invariants apply uniformly across all document types.

---

## ADR-004: Server-Side RBAC Enforcement over Filament UI Visibility
- **Context**: Filament admin panels allow hiding buttons using `->visible()` or `->hidden()` based on permissions.
- **Decision**: UI visibility guards must always be accompanied by server-side policy enforcement (`authorize()`, policy methods, or service checks).
- **Consequences**: Prevents unauthorized API or Livewire payload manipulation. Bulk actions and batch endpoints validate each individual record independently.

---

## ADR-005: Soft-Delete Immunity for Posted Financial Records
- **Context**: Filament table bulk delete actions allowed users to inadvertently delete posted journal entries and invoices.
- **Decision**: Any attempt to delete an `AccountMove` in `state === 'posted'` triggers an immediate hard abort (`$action->halt()`).
- **Consequences**: Posted accounting history is permanent. Corrections require issuance of compensating credit notes, debit notes, or formal reversal journals.
