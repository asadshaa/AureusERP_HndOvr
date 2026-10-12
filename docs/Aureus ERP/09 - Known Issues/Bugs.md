# Bugs

Status: Documented / Tracked
Related: [[Technical Debt]], [[Risks]], [[Regression Tests]], [[Current Status]]

This note catalogues open bugs, recently resolved high-impact defects, and edge-case failure modes in Aureus ERP.

---

## 1. Resolved Critical Bugs (Cycle 1 Audit)

All 12 major defects identified during Cycle 1 system audit were remediated and verified:
- **DEF-001 (Security)**: Accountant role was erroneously granted `lock_periods` permission. Resolved by re-scoping permissions to Financial Controller only.
- **DEF-002 (Integrity)**: Self-approval vulnerability on `My Allocations` screen allowed employees to approve their own leave. Resolved by removing approval actions from self-service views.
- **DEF-003 (Integrity)**: Management Allocations approval bypassed supervisor validation. Resolved with strict hierarchy checking.
- **DEF-004 (Accounting)**: Posted journal entries, customer invoices, and vendor bills were vulnerable to deletion via bulk actions. Resolved by adding server-side halt callbacks on `DeleteAction`.
- **DEF-005 & DEF-006 (Multi-Tenancy)**: Journal, Tax, TaxGroup, Sales Orders, and Purchase Orders leaked across companies in multi-company queries. Scoped strictly via `getEloquentQuery()`.
- **DEF-007 & DEF-008 (UI Leaks)**: Dropdown pickers on forms allowed picking accounts/journals from unassigned companies. Patched across all resource forms.
- **DEF-009 (RBAC)**: Invoice/Bill state transitions lacked granular action permissions. Restricted to Accounting Manager / Admin.
- **DEF-010 (Data)**: Demo users pointed to soft-deleted companies. Repointed to active tenant.
- **DEF-012 (CI/CD)**: Pest test suite crashed with fatal error in `accounting` plugin. Bootstrap and mocks repaired.

---

## 2. Active Known Minor Bugs & Inconveniences

| Issue ID | Description | Component | Workaround / Status |
|---|---|---|---|
| **BUG-021** | Pre-existing fixture failure in `inventories` plugin tests | `plugins/webkul/inventories` | Legacy upstream package issue. Core accounting unaffected. |
| **BUG-022** | Pre-existing failure in `manufacturing` plugin tests | `plugins/webkul/manufacturing` | Legacy package issue. Not used in active freight ops. |
| **BUG-023** | Bank-type journal picker edge-case seeding in test environment | `accounts` test suite | Fixed in code; test database fixture requires specific bank account seeding to pass automated test without mock. |
| **BUG-024** | Soft-deleted company ghost records in legacy tables | Database seeds | 5 inactive demo companies purged; ongoing vigilance required during new seeder runs. |
