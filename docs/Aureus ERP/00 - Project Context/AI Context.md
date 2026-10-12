# AI Context — Compact Developer & Agent Guide

**Purpose:** Rapid (sub-5 minute) architectural onboarding for AI coding assistants and developers.  
**Current Branch:** `main` (tracking `handover/main` and `newrepo/main`)  
**Parent Note:** [[Project Overview]]  

---

## 1. Quick Orientation

- **What is this?** An enterprise ERP monorepo for SME operations (focus: **Truck It In**), integrating Financial Accounting (GL, bank statement multi-parser, FS tag mappings), HR (claims, attendance, hierarchy), Procurement (bills, POs), and Drive document syncing.
- **Framework:** Laravel 13 (`^13.0`), PHP 8.3, Filament 5, Livewire 3/4, Tailwind CSS, MySQL 8.4 (InnoDB, strict).
- **Structure:** Modular plugin monorepo under `plugins/webkul/{plugin}` discovered via Composer merge-plugin.
- **Top-Level Rule:** Do NOT recreate systems that already exist. Reuse existing services.

---

## 2. Directory Layout & Key Modules

```text
c:\Intern\Handover\Erp/
├── app/                    # Laravel core providers, notifications, console commands
├── config/                 # Laravel configs (webrtc.php, accounting_drive.php, etc.)
├── docs/Aureus ERP/        # Official Obsidian Knowledge Base
├── plugins/webkul/         # Business domain packages:
│   ├── accounts/           # CORE LEDGER: Move, MoveLine, Account, Journal, Payment, Tax
│   ├── accounting/         # FINANCIAL OPS: Bank imports, Mappings, FS Tags, Reports, Drive Ingestion
│   ├── support/            # FOUNDATION: Universal ApprovalEngine, Company, Currency
│   ├── security/           # RBAC: Spatie Shield, UserPermissionScope, Bouncer
│   ├── employees/          # HR: Employee master, HrHierarchyService, Claims, Geofenced Attendance
│   ├── time-off/           # LEAVES: Leave, Allocation, LeaveApprovalService
│   ├── recruitments/       # RECRUITMENT: Candidate conversion, Job offers
│   ├── purchases/          # P2P: Purchase orders, Vendor management
│   ├── sales/              # O2C: Sales orders, Quotations
│   └── partners/           # PARTIES: Customers, Vendors, Bank accounts
└── tests/                  # Pest test suites
```

---

## 3. Top Architectural Rules

1. **All Accounting Converges into `Move`:** Invoices, Bills, Refunds, and Journal Entries are all `Webkul\Account\Models\Move`. Never create separate invoice tables.
2. **Never Persist Balances onto Accounts:** Balances are dynamically aggregated from posted `MoveLine` records (`accounts_account_move_lines`).
3. **Double-Entry Invariant:** For every posted move, $\sum \text{Debit} == \sum \text{Credit}$.
4. **Universal Approval Engine:** Never write bespoke approval columns or tables. All multi-step workflows route through `Webkul\Support\Services\ApprovalEngine` via `ApprovalRequest` and `ApprovalStep`.
5. **Strict Company Scoping:** Every business model has `company_id`. Queries must be scoped to the authenticated user's `default_company_id` or `allowedCompanies`. Leaking cross-company records is a critical security violation.
6. **No Raw Direct Mutations:** Posted accounting moves (`state = 'posted'`) cannot be edited or deleted directly. They must be reversed.
7. **Filament Actions Require Server-Side Enforcement:** Do not rely on UI `->visible()` for authorization. Server-side checks must guard actions.

---

## 4. Key Services You MUST Reuse

| Capability | Service to Call | Location |
|---|---|---|
| **Multi-Tier Approvals** | `ApprovalEngine` | `plugins/webkul/support/src/Services/ApprovalEngine.php` |
| **HR Org Hierarchy & Line Managers** | `HrHierarchyService` | `plugins/webkul/employees/src/Services/HrHierarchyService.php` |
| **Bank Imports & Parsing** | `BankStatementImportService` | `plugins/webkul/accounting/src/Services/Bank/BankStatementImportService.php` |
| **Transaction & FS Tag Mapping** | `BankMappingService` | `plugins/webkul/accounting/src/Services/Bank/BankMappingService.php` |
| **Bank Draft Journal Generation** | `BankJournalService` | `plugins/webkul/accounting/src/Services/Bank/BankJournalService.php` |
| **Accounting Document Management** | `DocumentService` | `plugins/webkul/accounting/src/Services/DocumentService.php` |
| **Google Drive PDF Ingestion** | `DriveIngestionService` | `plugins/webkul/accounting/src/Services/Drive/DriveIngestionService.php` |
| **Tax Computations** | `TaxManager` | `plugins/webkul/accounts/src/TaxManager.php` |

---

## 5. Dangerous Pitfalls & Known Risks

- **`User::companies()` is a `BelongsToMany` (`allowedCompanies`), NOT a `hasMany`:** The `companies` table does NOT have a `user_id` column. Querying `whereHas('companies')` assuming a `user_id` foreign key breaks MySQL.
- **Filament v3/v5 Notification Action Namespace:** Use `Filament\Actions\Action`, NOT `Filament\Notifications\Actions\Action`.
- **Period Locks:** Modifying past transactions will trigger `PeriodLockService` exceptions if the financial period is closed.
- **Legacy Test Failures in Unused Modules:** `plugins/webkul/inventories` and `plugins/webkul/manufacturing` contain inherited legacy fixture failures. Do not assume those break core accounting/HR.
- **Google Drive Duplication:** Drive ingestion uses SHA-256 file fingerprinting. Do not bypass `DocumentService` when ingesting or exporting files.

---

## 6. Testing & Formatting Standards

- **Test Suite:** Pest PHP (`vendor/bin/pest` or `php artisan test`). Run narrow tests first:
  ```bash
  php artisan test --filter=RelevantTest
  ```
- **Code Formatter:** Always run Laravel Pint before committing:
  ```bash
  vendor/bin/pint --dirty
  ```
- **No `env()` in Application Code:** Always use `config(...)`.

---

## 7. Documentation Index

- [[Project Overview]] — Complete business context
- [[System Architecture]] — Deep architectural breakdown
- [[Accounting Overview]] — General Ledger & core bookkeeping
- [[Bank Import]] — Bank statement parsers & mapping pipeline
- [[HR Overview]] — Employee management, hierarchy, and attendance
- [[Approval Workflow]] — Approval Engine mechanics and claim routing
- [[Document Architecture]] — Document storage & Google Drive sync
- [[Known Issues/Bugs]] — Documented defects and technical debt
