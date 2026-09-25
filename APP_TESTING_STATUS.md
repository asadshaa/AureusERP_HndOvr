# AureusERP — Testing Status

**Cycle 1 · 2026-09-25 · Tester: Claude (QA/audit, fix pass, then independent retest) · Environment:** local dev (`aureuserp` DB, single active company "Truck It In (Pvt) Ltd") + test DB (`aureuserp_testing`).

This cycle had three phases: (1) a read-only audit producing `APP_TESTING_ERRORS.md`, (2) a fix pass against that list, and (3) an independent retest of every fix — re-reading current code fresh and re-running live/functional checks (several with a genuine second company via factories, going beyond what the original fix pass verified) rather than trusting the fix summaries. All 9 `FIXED — PENDING RETEST` items are now **RETEST: PASS**; see `APP_TESTING_ERRORS.md` for the per-item evidence.

---

## Environment / blockers

- No browser password login was performed (by design — see the errors file's ENV-001). All role-based testing used `Auth::loginUsingId()`/`test()->actingAs()` against real seeded users and the real permission system.
- Live data has exactly one active company. Company-isolation defects were code-verified and, where practical, exercised with a second company via factories inside Pest tests (not live data).
- The `aureuserp_testing` database required its own manual migration run for this session's new `accounts_period_locks` table (see ENV-006) — otherwise unrelated Pest tests failed with "table doesn't exist," not a real defect.

---

## What was tested

### Accounting integrity (direct DB checks, not app UI)
- **Ledger balance:** every posted move's debits equal credits (`SUM(debit)=SUM(credit)=255,400.00` across the whole ledger). **PASS.**
- **Accounting equation:** Assets = Liabilities + Equity + Net Profit, computed independently from raw ledger balances (`-5,600 = -5,600`). **PASS.**
- **`parent_state` consistency:** every move line's `parent_state` matches its move's `state`. **PASS.**
- **No postings to group/deprecated accounts.** **PASS.**
- **No move line whose account isn't attached to the move's company.** **PASS.**
- **No duplicate move names within a company.** **PASS.**
- **Report parity:** `TrialBalanceService::compute()` and `BalanceSheet::balanceSheetData()` both reproduce the same totals as the raw ledger query (closing debit/credit both 141,550; Balance Sheet grand total -5,600, matching the raw equation). **PASS.**

### Permissions / roles
- Built a cross-role visibility/permission matrix (Admin, Accounting Manager, HR Manager, Manager, Employee, no-role users, Accountant) across Journal Entries, Bank Mapping, Chart of Accounts, Approval Requests, Employee Requests, Time Off, Employees. Confirmed each role's `canViewAny()`/`can()` results match its intended scope, **except** DEF-001 (Accountant wrongly held period-lock permissions — now fixed).
- Negative-path tested the Approval Engine directly: a non-approver (`Bilal`, `Raza/Admin` — deliberately, per the engine's own no-admin-bypass rule) attempting to approve a request they're not the matched approver for is correctly blocked with a clear exception, and the request stays `pending`. Rejecting with a blank reason is correctly blocked. **PASS** (both are existing, correct behavior).
- Found the actual self-approval defects (DEF-002, DEF-003) by combining permission checks with query-scope checks — a role having a permission is not the same as a screen enforcing it, which is exactly the gap that existed here. **Now fixed.**

### Company isolation
- Commissioned/ran a full code audit across accounting, accounts, employees, time-off, timesheets, support, recruitments, sales, purchases and partners for `getEloquentQuery()` company scoping and unscoped Select/relationship pickers.
- Confirmed correctly-scoped: BankStatement, BankTransactionMapping, Document, InboundTransmission, JournalEntry, JournalItem, ManualAdjustment, Account (Chart of Accounts), BankMappingRule, BusinessRule, DriveIngestionClassification, ExchangeRate, FsTag, ImportProfile, ImportRun, PartyClassification, Peer, Invoice, Bill, Payment, CreditNote, Refund, Employee, Department, AttendanceRecord, EmployeeRequestType, PerformanceCycle/Review, EmployeeSkill, EmployeeRequest, Allocation, TimeOff, Timesheet, ApprovalRequest, ApprovalWorkflow, Applicant/Candidate/JobByPosition/Stage.
- Found and fixed real leaks: Journal, Tax, TaxGroup, Sales Quotations/Orders, Purchase RFQs/Orders/Agreements (DEF-005, DEF-006), and the Payment/Invoice/Bill journal-picker defaults (DEF-007).
- Left open (documented, not fixed): a long tail of individual account/company Select pickers across Journal, Tax, and order-form company fields (DEF-008); whether Partners/Customers/Vendors are intentionally global (DEF-011, needs a business decision, not a bug fix).

### Server-side authorization
- Commissioned/ran a full audit for Filament actions relying only on client-side `->visible()`/`->hidden()` without a matching server-side `->authorize()` or service-level re-check.
- Confirmed protected: Approval Requests approve/reject (re-checked via `ApprovalEngine::decide()`/`canAct()`), Leave Request approve/reject (via `LeaveApprovalService`), Bank Transaction Mapping's review/generate/post actions, FX Revaluation, Bank Statement import.
- Found and fixed: My Allocations and Management Allocations approve/refuse (DEF-002, DEF-003), posted-record deletion on Journal Entries/Invoices/Bills (DEF-004).
- Invoice/Bill lifecycle actions (Confirm/Cancel/Pay/Reverse/Reset to Draft/Set as Checked) had no dedicated per-operation permission — anyone who can edit could perform any of them. Client decision obtained: only Admin/Accounting Manager tier (the same as `PostJournal`), not HR/Employee. Fixed and verified live across 4 real users (DEF-009).

### Data integrity
- Found 2 users (`Demo Accountant`, `Demo Approver`) whose `default_company_id` pointed at a soft-deleted company. Reassigned both to the real active company (DEF-010, fixed).
- Company count grew from 1 to 6 over the course of this session's earlier work (demo/seed data), with 5 now soft-deleted — noted as environment context, not a defect in itself.

### Automated test suite
- Pre-fix baseline (per-plugin `php artisan test`, run before any fixes): `accounting` plugin **fatally aborted the entire run** (DEF-012); `accounts` 116 failed/400 passed; `sales` 46 failed/63 passed; `purchases` 54 failed/110 passed; `time-off` 8 failed/14 passed; `employees` 7 failed/135 passed; `inventories` 732 failed (0 assertions — systemic, unrelated to any change made this session); `manufacturing` 33 failed; `partners`/`products`/`projects`/`recruitments`/`support` all fully green.
- DEF-012 fixed: `accounting` plugin no longer fatally aborts; the specific test file now passes 17/17.
- For every fix applied, ran the narrowest relevant test file(s) and, where a pre-existing failure was suspected, confirmed it with a `git stash`/`git stash pop` A/B comparison against unmodified code rather than assuming. Every failure encountered during the fix pass was either (a) confirmed pre-existing via this method, or (b) fixed directly (DEF-012).
- Full-suite post-fix re-run for `sales`/`purchases` completed and was independently re-verified with a fresh `git stash`/`git stash pop` A/B pass: **99 failed, 174 passed identically with and without this session's fixes** — confirmed zero regressions from the DEF-006/DEF-007 changes.

---

## Coverage NOT tested this cycle (explicitly out of scope or blocked)

- Full browser/UI click-through (blocked by ENV-001; the user was asked earlier this session to drive specific UI checks live, and did for several features).
- Attendance, Performance Review, Recruitment, and Timesheet workflows beyond a basic visibility-matrix spot check (no defects found in that spot check, but full workflow testing — e.g. actually running a performance cycle end-to-end — was not done).
- Multi-currency conversion accuracy beyond confirming report-service parity with raw ledger totals.
- Google Drive ingestion beyond the existing Pest suite (36 passed) and duplicate-detection logic (already fixed and tested earlier this session, before this audit cycle).
- Recurring/scheduled jobs (none exist in the codebase per this session's own earlier research — see `PeriodLockService`'s doc comment).
- `inventories` and `manufacturing` plugins' large pre-existing failure counts (732 and 33 respectively) were noted but not root-caused — they were not touched by any fix in this session and are flagged as a separate, pre-existing concern worth its own investigation.

---

## Summary

| # | Defect | Severity | Status |
|---|---|---|---|
| DEF-001 | Accountant role held period-lock permissions | High | **RETEST: PASS** |
| DEF-002 | Self-approval on My Allocations | High | **RETEST: PASS** |
| DEF-003 | Management Allocations approve/refuse bypass authorization | High | **RETEST: PASS** |
| DEF-004 | Posted journal entries/invoices/bills deletable | High | **RETEST: PASS** |
| DEF-005 | Journal/Tax/TaxGroup not company-scoped | High | **RETEST: PASS** |
| DEF-006 | Sales/Purchase orders not company-scoped | High | **RETEST: PASS** |
| DEF-007 | Payment/Invoice/Bill journal picker company leak | Medium–High | **RETEST: PASS** (BANK-type combination untestable — pre-existing ENV-003 gap, not a fix defect) |
| DEF-008 | Remaining unscoped account/company pickers | Medium | Open — documented, not fixed this pass |
| DEF-009 | No per-operation permission on invoice/bill actions | Medium | **RETEST: PASS** (client decision obtained, fixed and verified) |
| DEF-010 | Users defaulted to a deleted company | Low | **RETEST: PASS** (data fix) |
| DEF-011 | Partners shared across companies | Needs decision | Open — business decision required |
| DEF-012 | Full test suite fatal error | High (for CI) | **RETEST: PASS** |

**10 of 12 documented defects fixed and independently retested — all PASS. 2 left open, each with an explicit reason (scope/time tradeoff for DEF-008, a genuine business decision still needed for DEF-011) rather than a rushed or incomplete fix.**

## Retest methodology (this pass)

Every retest deliberately went beyond re-reading the original fix summary:
- **Live functional tests with a genuine second company** (DEF-005, DEF-006, DEF-007) — created real Company A/Company B pairs with factories and confirmed cross-company queries actually return the right rows, rather than only re-reading the `getEloquentQuery()` code.
- **Direct invocation of the real Filament Action object** (DEF-004) — built the actual registered `DeleteAction` from the resource's table, attached a genuinely posted `Move`, and called its `before()` callback directly; it threw `Filament\Support\Exceptions\Halt`, the literal mechanism `$action->halt()` uses — stronger evidence than the `assertTableActionHidden` test alone.
- **Enumerated the real table's actions** (DEF-002) rather than only grepping for absence — confirmed only `view`/`edit`/`delete` are registered.
- **Tested the "legitimate case still works" side, not just the "bad case is blocked" side** (DEF-003) — confirmed a manager can still approve a genuine subordinate's allocation, not only that self-approval is blocked.
- **Re-queried the database directly** (DEF-010) rather than trusting the earlier confirmation.
- One caveat carried over honestly rather than glossed over: DEF-007's full BANK-type journal picker combination hit the same pre-existing `ENV-003` test-environment seeding gap documented in the original audit; the fix's actual logic (the `company_id` filter) was proven correct via an equivalent non-BANK-type test, but the exact BANK-type path remains unexercised in this environment.

## Remaining for next session

- Live browser click-through for the fixes with a UI surface (DEF-002, DEF-003, DEF-004), since this retest — like the original fix pass — was still server-side only (see ENV-001).
- A follow-up pass on DEF-008's remaining picker list.
- A decision from the client/product owner on DEF-011 (should Partners be company-scoped or intentionally shared?).
