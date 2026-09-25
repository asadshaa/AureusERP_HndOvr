# AureusERP — Testing Errors

Cycle 1 · 2026-09-25 · Tester: Claude (QA/audit) · Environment: local dev (`aureuserp` DB) + test DB (`aureuserp_testing`)

Application defects and environment blockers are listed separately. "Code-verified" means the defect was confirmed by reading the code on the exact execution path. It was not executed, usually because executing it would destroy real data or needs a second active company.

---

## Part A — Application defects

### DEF-001 · Accountant role can lock and unlock accounting periods
- **Module:** Accounting → Period Lock / Permissions
- **Severity:** High (segregation of duties)
- **Status:** **FIXED — PENDING RETEST.**
- **Reproduce:** `Auth::loginUsingId(2)` (Demo Accountant, role `Accountant`), then `->can(AccountingPermissions::ManagePeriodLock)`.
- **Expected:** `false`. Period close is a Controller, Accounting Manager or Admin responsibility. Accountant cannot even post journals.
- **Actual (before fix):** `true`. The Accountant role also gets `PeriodLockPage`.
- **Root cause:** `AccountingPermissions::accountant()` is `array_diff(self::all(), [exclusions])`. Any new permission added to `all()` flows into Accountant unless it is explicitly excluded. `ManagePeriodLock` and `PeriodLockPage` were added to `all()` and not to the exclusion list.
- **Fix:** Added `ManagePeriodLock`/`PeriodLockPage` to `accountant()`'s exclusion list. Because the leaked permission had already been synced into the live DB, also directly revoked `ManagePeriodLock`/`PeriodLockPage` from the `Accountant`/`accountant` role via `Role::revokePermissionTo()` (code changes alone don't retract an already-granted permission — `AccountingPermissionRegistrar::synchronize()` only ever inserts, never revokes).
- **Verified:** `Demo Accountant->can(ManagePeriodLock)` now `false`; `PostJournal` still correctly `false`; `view_any_accounting_invoice` still correctly `true` (no collateral permission loss).
- **Files:** `plugins/webkul/accounting/src/Support/AccountingPermissions.php` (`accountant()`).

### DEF-002 · Employees can approve their own leave allocations
- **Module:** Time Off → My Time → My Allocations
- **Severity:** High (approval bypass)
- **Status:** **FIXED — PENDING RETEST.**
- **Reproduce:** Log in as Bilal Ahmed (role `Employee`) and open My Allocations. `canViewAny()` is `true` and 10 rows are visible. Click **Approve** on your own allocation in state Confirm or Validate One.
- **Expected:** An employee cannot approve their own allocation. Approval should go through the approval engine and its approver checks.
- **Actual (before fix):** The action ran `$record->update(['state' => VALIDATE_TWO])` directly. There was no `->authorize()`, no permission check, and no ApprovalEngine call. The only guard was `->hidden()` on the current state. **Refuse** was the same.
- **Root cause:** Upstream scaffold row actions were kept on the employee self-service screen.
- **Fix:** Every row on `MyAllocationResource` is, by construction (`getEloquentQuery()` filters to `whereHas('employee', fn ($q) => $q->where('user_id', Auth::id()))`), the viewer's own allocation — so an approve/refuse button here can only ever be self-approval. Removed both actions entirely rather than gating them, since there is no legitimate case for them on this specific screen (approving a subordinate's allocation is the Management cluster's job — see DEF-003).
- **Files:** `plugins/webkul/time-off/src/Filament/Clusters/MyTime/Resources/MyAllocationResource.php`.

### DEF-003 · Manager-side allocation approve/refuse bypass the approval engine
- **Module:** Time Off → Management → Allocations
- **Severity:** High
- **Status:** **FIXED — PENDING RETEST.**
- **Reproduce:** Any user who can open Allocations sees Approve and Refuse on rows. On the Edit page they also see Approve, Refuse and Mark as ready to confirm.
- **Expected:** Same enforcement as leave requests. `TimeOffResource` routes through `LeaveApprovalService` → `ApprovalEngine::decide()`, which re-checks `canAct()`.
- **Actual (before fix):** Direct `$record->update(['state' => …])` with no `->authorize()` and no approver check.
- **Fix:** This resource has no dedicated ApprovalRequest/workflow the way `Leave` does (only a plain Confirm → Validate One → Validate Two/Refuse state machine), so routing it through the full ApprovalEngine would be a parallel-system change beyond the smallest safe fix. Instead added `->authorize(HrPermissions::ApproveLeave)` to all 5 actions (approve/refuse on the list, approve/refuse/mark-as-ready-to-confirm on the Edit page), plus an explicit self-approval guard, because `HrHierarchyService::visibleEmployeeIds()` always includes the viewer's own employee id, so a manager with `ApproveLeave` would still see and could otherwise act on their own row.
- **Verified:** Zainab (HR Manager) and Sarah (Manager) both hold `hr_approve_leave`; Bilal (Employee) does not, so he's blocked at `->authorize()`. Sarah's own allocation (employee_id → user_id 42, matching her own actor id) is blocked by the self-check.
- **Files:**
  - `plugins/webkul/time-off/src/Filament/Clusters/Management/Resources/AllocationResource.php`
  - `.../AllocationResource/Pages/EditAllocation.php`

### DEF-004 · Posted journal entries can be permanently deleted
- **Module:** Accounting → Journal Entries; Customers → Invoices (bulk)
- **Severity:** High (accounting integrity and auditability)
- **Status:** **FIXED — PENDING RETEST.**
- **Reproduce:** As Khurram, who holds `delete_accounting_journal::entry` and `delete_any_…`, use the row **Delete** or bulk **Delete** on a posted journal entry.
- **Expected:** Posted moves cannot be deleted. They must be reversed, which the app already supports and which was fixed today.
- **Actual (before fix):**
  - `JournalEntryResource` `DeleteAction` (line 420) and `DeleteBulkAction` (line 431) had no state guard.
  - `InvoiceResource`/`BillResource` hid the row delete only when the record was posted, and their bulk deletes had no guard at all.
- **Fix:** Added a `->before()` closure to the row `DeleteAction` and bulk `DeleteBulkAction` on all three resources (JournalEntryResource, InvoiceResource, BillResource) that checks `state === MoveState::POSTED` and calls `$action->halt()` with a notification explaining to use Reverse instead. `->hidden()` alone only hides the button (not a server-side guard); `before()` is what actually blocks the delete even if the button were reached another way. The `Move` model still has no `deleting` hook or `SoftDeletes`, so a *draft* move remains a hard delete — unchanged, since drafts are correctly deletable.
- **Regression test added:** `plugins/webkul/accounting/tests/Feature/JournalEntryDeleteGuardTest.php` — asserts the delete action is hidden for a posted move (passes). Two additional `callTableAction`-based assertions were attempted but hit an unrelated Livewire-test-harness record-resolution quirk with this table's preset-view tabs; the fix itself was independently confirmed by direct code reading and a standalone debug script showing `mountTableAction` behaves correctly.
- **Files:**
  - `plugins/webkul/accounting/src/Filament/Clusters/Accounting/Resources/JournalEntryResource.php`
  - `plugins/webkul/accounts/src/Filament/Resources/InvoiceResource.php`
  - `plugins/webkul/accounts/src/Filament/Resources/BillResource.php`

### DEF-005 · Journals, Taxes and Tax Groups are not company-scoped
- **Module:** Accounting configuration (accounts plugin, and the accounting and invoices cluster subclasses)
- **Severity:** High (company isolation)
- **Status:** **FIXED — PENDING RETEST.**
- **Expected:** List and edit screens show only the active company's rows.
- **Actual (before fix):** `JournalResource`, `TaxResource` and `TaxGroupResource` had no `getEloquentQuery()` override. All three models carry `company_id`.
- **Fix:**
  - `JournalResource::getEloquentQuery()` now filters `where('company_id', Auth::user()?->default_company_id)`.
  - `TaxResource::getEloquentQuery()` reuses the model's own existing `Tax::scopeForCompany()` (rather than re-deriving the filter) — `company_id` is required (non-nullable) on this model, so a strict match is correct.
  - `TaxGroupResource::getEloquentQuery()` filters to the viewer's company **or** `company_id IS NULL` — `company_id` is nullable on this model by design (a null row is a genuinely shared/global tax group), so a strict-only filter would have hidden legitimate global rows.
- **Tests run:** `plugins/webkul/accounts` full suite (filtered around Journal/Tax): 3 failed, 100 passed. All 3 failures confirmed pre-existing via `git stash` A/B (identical failures/messages on unmodified code) — unrelated to this fix (`TaxPickerHardeningTest` exercises `Tax::taxValidationRule()`, a raw model-level rule that never touches `TaxResource`; `RepeaterLinePersistenceTest` fails on a `FiscalPosition` null-property bug present before this fix too).
- **Files:** `plugins/webkul/accounts/src/Filament/Resources/{JournalResource,TaxResource,TaxGroupResource}.php`

### DEF-006 · Sales and Purchase orders are not company-scoped
- **Module:** Sales (Quotations, Orders, To Invoice, To Upsell) and Purchases (RFQs, Purchase Orders, Agreements)
- **Severity:** High (company isolation)
- **Status:** **FIXED — PENDING RETEST.**
- **Actual (before fix):**
  - `getEloquentQuery()` was only `parent::getEloquentQuery()->orderByDesc('id')` in sales `QuotationResource` and purchases `OrderResource`/`PurchaseAgreementResource`.
  - `HasPermissionScope::scopeApplyPermissionScope` filters by creator/user, not company — so it does not substitute for a company filter.
- **Fix:** Added `->where('company_id', Auth::user()?->default_company_id)` to the base `getEloquentQuery()` in sales `QuotationResource` and purchases `OrderResource`/`PurchaseAgreementResource`. Confirmed sales `OrderResource`, `OrderToInvoiceResource` and `OrderToUpsellResource` all `extends QuotationResource` and call `parent::getEloquentQuery()` first, so they inherit the fix automatically — same for purchases `QuotationResource extends OrderResource`. No separate change was needed in those four files.
- **Not fixed / out of scope for this pass:** `HasPermissionScope` itself (creator/user scoping) was left untouched — it's a shared trait used by many resources for a different purpose (assignment-based visibility), and broadening its behavior would be a much larger change than this specific defect calls for.
- **Files:**
  - `plugins/webkul/sales/src/Filament/Clusters/Orders/Resources/QuotationResource.php`
  - `plugins/webkul/purchases/src/Filament/Admin/Clusters/Orders/Resources/OrderResource.php`
  - `plugins/webkul/purchases/src/Filament/Admin/Clusters/Orders/Resources/PurchaseAgreementResource.php`

### DEF-007 · Payment form can pre-select another company's journal
- **Module:** Accounts → Payments; Invoice journal picker
- **Severity:** Medium–High
- **Status:** **FIXED — PENDING RETEST.**
- **Actual (before fix):**
  - `PaymentResource.php:200` set the default to `Journal::whereIn('type', [BANK, CASH, CREDIT_CARD])->first()`, with no company filter.
  - The journal pickers at `PaymentResource.php:192–197`, `InvoiceResource.php:224–228`, and the equivalent picker in `BillResource.php:245` filtered by type only.
- **Fix:** Added `->where('company_id', Auth::user()?->default_company_id)` to the `modifyQueryUsing` on all three pickers, and to the `Journal::` lookup used for `PaymentResource`'s default.
- **Files:** `plugins/webkul/accounts/src/Filament/Resources/{PaymentResource,InvoiceResource,BillResource}.php`

### DEF-008 · Other company-owned dropdowns list every company's records
- **Module:** Accounts configuration; Sales; Purchases
- **Severity:** Medium
- **Status:** Open. Not fixed this pass — see below.
- **Actual:**
  - Journal form account pickers have no company filter: default, income, expense, suspense, payment and allowed accounts (`JournalResource.php:118–318`).
  - The Journal company field uses `Company::pluck('name','id')` (line 370, verified).
  - Tax repartition account pickers (`TaxResource.php:175, 234`) and the tax group picker (`:103`) are unscoped.
  - Sales and Purchase order company pickers use `withTrashed()` and list every company, including deleted ones, instead of `allowedCompanies()`.
  - The order company filters (`RelationshipConstraint::make('company')`) list every company.
- **Evidence:** From the scoping audit. The `Company::pluck` line was verified directly.
- **Why not fixed this pass:** This is a long tail of individually small picker fixes across many files. DEF-005/006/007 (the resources' own list/edit-page scoping, and the two highest-traffic payment/invoice journal pickers) were prioritized as the higher-value, lower-risk fixes. Recommend a dedicated follow-up pass through this specific list.

### DEF-009 · Invoice/bill posting operations have no per-operation permission
- **Module:** Accounts → Invoice/Bill actions (Confirm, Cancel, Pay, Reverse, Reset to Draft, Set as Checked)
- **Severity:** Medium (design gap)
- **Status:** Open. Not fixed this pass.
- **Actual:** None of the action classes call `->authorize()`, and `AccountManager` performs no `can()`/Gate checks. Anyone who can edit an invoice can post, cancel, pay, reverse or reset it. There is no "post invoice" permission, unlike bank and manual-adjustment journals, which require `PostJournal`.
- **Why not fixed this pass:** Introducing a new permission (e.g. `PostInvoice`) requires deciding which existing roles should retain unconditional invoice-posting rights, granting/backfilling it consistently across the finance role bundles, and re-syncing — a design decision, not a mechanical bug fix, and the instructions for this pass call for the smallest safe fix over inventing new permission plumbing under time pressure. Flagging for a deliberate follow-up with an explicit decision on the new permission's role assignments.
- **Files:** `plugins/webkul/accounts/src/Filament/Resources/InvoiceResource/Actions/*.php`

### DEF-010 · Users whose default company is a deleted company
- **Module:** Users / Companies
- **Severity:** Low
- **Status:** **FIXED — PENDING RETEST** (data fix, not a code fix).
- **Actual (before fix):** Users #2 (Demo Accountant) and #3 (Demo Approver) had `default_company_id = 2`. Company #2 "Truck It In (Demo)" was soft-deleted on 2026-09-24.
- **Expected:** A company cannot be soft-deleted while it is still a user's default. At minimum, these users should be reassigned.
- **Fix:** Reassigned both users' `default_company_id` to company #1 (the real active company, Truck It In (Pvt) Ltd) and synced `allowedCompanies()` to include it. Verified via `->fresh()`.
- **Not fixed:** The underlying gap — nothing stops a company from being soft-deleted while still referenced as someone's default — is unaddressed. That would need a guard in the company-deletion path itself; flagging as a follow-up rather than fixing under this defect's data-only scope.

### DEF-011 · Partners, customers and vendors are shared across companies (needs a decision)
- **Module:** Partners, and every Customer/Vendor resource that extends it
- **Severity:** Medium if unintended, none if intentional
- **Status:** **Needs business decision.** `PartnerResource::getEloquentQuery()` only adds eager loads, even though `Partner` has `company_id`. Many ERPs share contacts across companies deliberately.

### DEF-012 · Full test suite aborts with a fatal error (Drive test double out of date)
- **Module:** Automated tests: Accounting → Documents / Drive
- **Severity:** High for CI/verification, because the whole `php artisan test` run stops. None for runtime, because the application code is unaffected.
- **Status:** **FIXED — PENDING RETEST.**
- **Reproduce:** Run `php artisan test`.
- **Expected:** The suite runs to completion and reports pass/fail counts.
- **Actual (before fix):** `Pest\Exceptions\FatalException: Class Webkul\Accounting\Contracts\DriveClient@anonymous contains 1 abstract method … (DriveClient::trashFile)`. The run stopped and printed no summary.
- **Root cause:** The anonymous class at `DriveHardenedScenariosTest.php:164` implements `DriveClient` but was not given the new `trashFile()` method (added to the interface for Drive folder organisation earlier this session).
- **Fix:** Added the missing `trashFile(string $fileId): void` method to the anonymous class, delegating to the wrapped `FakeDriveClient` (which already had its own `trashFile()`).
- **Verified:** `DriveHardenedScenariosTest.php` now runs to completion: 17 passed.
- **Files:** `plugins/webkul/accounting/tests/Feature/Documents/DriveHardenedScenariosTest.php`.

---

## Part B — Environment blockers (not application defects)

| ID | Blocker | Effect |
|---|---|---|
| ENV-001 | The tester will not type user passwords into login forms, and Claude-in-Chrome cannot reach `localhost:8000`. | Browser/UI click-through needs the user to drive it. Flows were tested server-side with `Auth::loginUsingId()` against real users instead. |
| ENV-002 | Only one active company exists in live data (the other 5 are soft-deleted). | Cross-company leakage cannot be demonstrated end-to-end on live data. The isolation defects above are code-verified. |
| ENV-003 | Test DB factory gap: creating a `Journal` via factory violates the `accounts_journals.suspense_account_id` FK. | Many accounting feature tests fail before reaching their assertions. Confirmed pre-existing with `git stash` A/B runs (same counts with and without today's changes). |
| ENV-004 | Test helper gap: `plugins/webkul/accounts/tests/Helpers/AccountHelper.php:70` expects a seeded user (`ModelNotFoundException`). `inventories/database/seeders/LocationSeeder.php:53` has a null `$user`. | Invoice, credit-note, refund, payment and inventory workflow tests fail in setup. Pre-existing. |
| ENV-005 | No draft journal entry exists in the live DB. | The period lock was verified at service level and on all three posting call sites, but not by confirming a real draft through the UI. |
| ENV-006 | The new `accounts_period_locks` migration had to be run manually on `aureuserp_testing`. The test DB is not auto-migrated. | Any future migration will show up as test failures until it is applied to the test DB. |
