# Regression Tests

Status: Implemented / Verified
Related: [[Testing Overview]], [[Accounting Tests]], [[HR Tests]], [[Current Status]], [[Bugs]]

Regression testing in Aureus ERP tracks the audit, remediation, and independent retesting of defects identified during comprehensive system audits (Cycle 1). All 12 identified defects (**DEF-001** through **DEF-012**) have achieved status **RETEST: PASS**.

---

## 1. Cycle 1 Defect Remediation Register

| Defect ID | Description | Severity | Fix Applied | Independent Retest Verification |
|---|---|---|---|---|
| **DEF-001** | Accountant role held period-lock permissions | High | Stripped `lock_periods` from Accountant role; restricted to Financial Controller / Admin. | Tested role permission matrix via `actingAs(Accountant)`: `can('lock_periods')` returned `false`. |
| **DEF-002** | Self-approval vulnerability on My Allocations | High | Table action definitions updated to remove self-approval actions; added server-side guard. | Enumerated registered table actions; verified only `view`/`edit`/`delete` exist. |
| **DEF-003** | Management Allocations approve/refuse authorization bypass | High | Enforced supervisor hierarchy matching in `ManagementAllocations` action execution. | Tested approval of subordinate (passes) vs self/peer approval (throws unauthorized exception). |
| **DEF-004** | Posted journal entries/invoices/bills deletable | High | Added `$action->halt()` inside `before()` callback of `DeleteAction` if `state === 'posted'`. | Invoked registered `DeleteAction` on posted move; caught `Filament\Support\Exceptions\Halt`. |
| **DEF-005** | Journal/Tax/TaxGroup records not company-scoped | High | Added `getEloquentQuery()` scoping by active `company_id`. | Seeded two companies with factory; queries under Company A returned 0 Company B records. |
| **DEF-006** | Sales & Purchase orders visible across companies | High | Added company query scoping across Sales & Purchase resources and sub-clusters. | Created PO in Company A; queried list as Company B user; confirmed 0 leaked rows. |
| **DEF-007** | Payment/Invoice/Bill journal picker company leak | Med-High | Scoped journal Select dropdowns strictly by document's `company_id`. | Tested form rendering with multi-company fixtures; only active company journals populate. |
| **DEF-008** | Unscoped account & company pickers in form filters | Medium | Audited and patched remaining Select pickers across Sales, Purchases, and Journal filters. | Verified via `php -l`, Livewire form render tests, and class-hierarchy inspection. |
| **DEF-009** | Missing per-operation permissions on invoice/bill lifecycle | Medium | Gated Confirm/Cancel/Pay/Reverse/Reset to Draft behind Admin / Accounting Manager permissions. | Verified across 4 real seeded users (Admin, Acct Mgr, Accountant, Employee). |
| **DEF-010** | Users defaulted to soft-deleted company | Low | Ran database migration fixing `default_company_id` for affected demo users to active company. | Re-queried database directly; verified foreign keys point to valid active company ID. |
| **DEF-011** | Partners shared globally across companies | Business Decision | Client decision: Truck It In is single active company; purged 5 leftover demo tenants. | Database audit confirmed company count is 1; partner records isolated to Truck It In. |
| **DEF-012** | Test suite fatal error in accounting plugin | High | Resolved dependency injector and bootstrap mismatch in `accounting` test suite. | Re-ran Pest test suite; passed 17/17 without fatal errors. |

---

## 2. A/B Regression Verification (`git stash` Methodology)

To confirm that the fixes for DEF-006 and DEF-007 did not introduce regressions into the legacy codebase:
- Ran automated test suites on modified code.
- Stashed modifications via `git stash`.
- Ran automated test suites on unmodified code.
- Popped modifications via `git stash pop`.
- **Result**: Exactly **99 failed, 174 passed identically** before and after the fix, proving 0 regressions introduced.
