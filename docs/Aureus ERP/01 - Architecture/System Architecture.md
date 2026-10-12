# System Architecture

**Status:** Implemented & Verified  
**Parent Note:** [[Project Overview]]  
**Sub-Notes:** [[Laravel Architecture]], [[Filament Architecture]], [[Database Architecture]], [[Authorization & Company Scoping]]  

---

## 1. High-Level Architectural Topology

Aureus ERP employs a **modular plugin monorepo** architecture on Laravel 13. Rather than writing domain logic in a monolithic `app/` directory, capabilities reside in decoupled packages in `plugins/webkul/`:

```text
[ Browser / Filament UI ]
           │
           ▼
[ HTTP & Livewire Layer ]
    ├─ Multi-Company Middleware (`company_id` enforcement)
    ├─ Authentication (Spatie Shield & Bouncer)
    └─ Locale & Session Handlers
           │
           ▼
[ Domain Plugins (plugins/webkul/*) ]
    ├─ accounts (Ledger Core, Move, MoveLine, Journals, Payments, Tax)
    ├─ accounting (Bank Parsers, FS Tags, Mappings, Financial Reports, Drive Sync)
    ├─ support (Centralized ApprovalEngine, Multi-Company, Currencies)
    ├─ security (Row-Level Permission Scopes, Roles, Gates)
    ├─ employees (Staff Hierarchy, Claims, Sensitive Changes, Attendance)
    ├─ time-off (Leave Request Engine, Accruals)
    └─ purchases / sales (P2P & O2C workflows)
           │
           ▼
[ Data & Storage Tier ]
    ├─ MySQL 8.4 (Company-Scoped Relational Tables, InnoDB Foreign Keys)
    ├─ accounting_documents Disk (Local / S3 with SHA-256 Checksums)
    └─ Database Queue Driver (Async jobs, notification dispatch, Drive sync)
```

---

## 2. Key Architectural Tenets

### 1. Unified General Ledger
All financial transactions across the ERP converge into a single model: `Webkul\Account\Models\Move` (`accounts_account_moves`).
- Customer Invoices: `move_type = 'out_invoice'`
- Vendor Bills: `move_type = 'in_invoice'`
- Credit Notes: `move_type = 'out_refund'`
- Vendor Refunds: `move_type = 'in_refund'`
- Journal Entries: `move_type = 'entry'`

Every move owns child `accounts_account_move_lines` containing debit, credit, account, partner, and currency. No other table stores financial debits or credits.

### 2. Universal Approval Engine
No domain plugin defines isolated approval columns like `is_approved` or bespoke review tables. All multi-tier approvals interface with `Webkul\Support\Services\ApprovalEngine`:
- The subject model implements `morphTo` on `ApprovalRequest`.
- Multi-step hierarchies are defined by `ApprovalWorkflow` and `ApprovalStep`.
- Each decision is logged in `support_approval_decisions` with timestamps, comments, and the deciding user's ID.

### 3. Dual Accounting Plugin Separation
The accounting domain is intentionally split into two distinct packages:
- **`accounts`**: The low-level mathematical double-entry engine. It owns the chart of accounts, debit/credit balancing, move lifecycle states (`draft` $\rightarrow$ `posted` $\rightarrow$ `cancel`), payment matching, and tax computations.
- **`accounting`**: The high-level financial operations layer. It handles bank statement file parsing, transaction rule matching, FS Tag derivations, configurable reporting templates, Google Drive cloud sync, and P2P exchange.

---

## 3. Communication Patterns

- **Synchronous Eloquent Calls:** For transactional integrity, actions like posting moves or creating draft bills from approved claims occur synchronously within database transactions (`DB::transaction`).
- **Domain Events:** State transitions dispatch Laravel events (e.g. `MoveConfirmed`, dispatched when an accounting move is locked and posted).
- **Asynchronous Background Jobs:** Expensive operations (such as syncing outbound invoice PDFs to Google Drive or processing bulk statement files) are queued via `SyncDocumentToDriveJob` and queue workers.

---

## 4. Source References

```text
app/Providers/Filament/AdminPanelProvider.php
plugins/webkul/accounts/src/AccountPlugin.php
plugins/webkul/accounting/src/AccountingPlugin.php
plugins/webkul/support/src/SupportPlugin.php
plugins/webkul/employees/src/EmployeePlugin.php
```
