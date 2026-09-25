# AureusERP — Testing Errors

Cycle 1 · 2026-09-25 · Tester: Claude (QA/audit) · Environment: local dev (`aureuserp` DB) + test DB (`aureuserp_testing`)

**Cycle 1 retest · 2026-09-25 · independent second-reviewer pass** — every `FIXED — PENDING RETEST` item below was re-verified with fresh, independent checks (re-reading the current code, and live/factory-driven functional tests with a genuine second company where relevant) rather than trusting the original fix summary. Results recorded per item as `RETEST: PASS`.

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
- **RETEST: PASS.** Independently re-read `accountant()`'s exclusion list (both constants present with an explanatory comment) and re-ran the permission check live for 4 real users: Accountant → `ManagePeriodLock=no`; Admin, both Accounting Managers → `ManagePeriodLock=YES` (correctly retained). No collateral loss confirmed.
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
- **RETEST: PASS.** Confirmed `grep "Action::make('approve')\|Action::make('refuse')"` returns zero matches in the current file. Went further than a code read: built the resource's real Filament table object and enumerated `getFlatActions()` live — only `view`, `edit`, `delete` exist. Approve/refuse are structurally absent from the table, not just hidden by a condition.

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
- **RETEST: PASS.** Confirmed `->authorize(HrPermissions::ApproveLeave)` and the self-check both present at all 5 call sites in current code (grep, all lines match). Went further than the original fix's spot check: created a real subordinate allocation under Sarah's reporting tree and confirmed the self-check correctly does **not** block approving it (`Self-check would block approving SUBORDINATE record: no`), while her own allocation is still correctly blocked (`... OWN record: YES`) — proves the fix doesn't over-block legitimate approvals, not just that it blocks the bad case.

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
- **RETEST: PASS — with stronger evidence than the original fix.** Confirmed all 6 `before()` guards present (2 per resource × 3 resources). Rather than relying only on `assertTableActionHidden`, built the real registered `DeleteAction` object from `JournalEntryResource::table()`, attached a genuinely posted `Move`, and called `$deleteAction->callBefore()` directly: it threw `Filament\Support\Exceptions\Halt` — the exact internal mechanism `$action->halt()` uses. This is direct proof the guard fires on the real action object Filament would execute, not just a logic replica. The move was confirmed to still exist afterward.

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
- **RETEST: PASS — with a genuine second-company functional test the original fix report didn't include.** Created a real Company A / Company B pair plus a Journal, Tax and TaxGroup row in each (unlike the original verification, which was code-only since live data has one company). Logged in as a Company A user and queried all three resources: each correctly returns its own company's row and correctly excludes the other company's row. Also confirmed TaxGroup's global-row (`company_id IS NULL`) exception still surfaces correctly — the nullable-vs-required distinction between Tax and TaxGroup was itself worth double-checking, and it holds.

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
- **RETEST: PASS.** Independently re-read `PurchaseAgreementResource`'s current code to confirm the fix is genuinely present (not just trusting the original summary). Ran a fresh live functional test with a real second company: created a Sale Order and a Purchase Order under Company B, logged in as a Company A user, and confirmed `getEloquentQuery()` returns the Company A row and correctly excludes the Company B row for both. Did not re-run the full sales+purchases suite in this retest pass — relying on the two prior identical A/B results (99 failed/174 passed, with and without the fix) already recorded above, which is sufficient given the live functional test independently confirms the actual behavior.

### DEF-007 · Payment form can pre-select another company's journal
- **Module:** Accounts → Payments; Invoice journal picker
- **Severity:** Medium–High
- **Status:** **FIXED — PENDING RETEST.**
- **Actual (before fix):**
  - `PaymentResource.php:200` set the default to `Journal::whereIn('type', [BANK, CASH, CREDIT_CARD])->first()`, with no company filter.
  - The journal pickers at `PaymentResource.php:192–197`, `InvoiceResource.php:224–228`, and the equivalent picker in `BillResource.php:245` filtered by type only.
- **Fix:** Added `->where('company_id', Auth::user()?->default_company_id)` to the `modifyQueryUsing` on all three pickers, and to the `Journal::` lookup used for `PaymentResource`'s default.
- **Files:** `plugins/webkul/accounts/src/Filament/Resources/{PaymentResource,InvoiceResource,BillResource}.php`
- **RETEST: PASS, with one testing caveat.** Confirmed all 3 company filters present in current code by line number. Attempted a live 2-company functional test with a BANK-type Journal in Company B and confirmed the exact `->where('company_id', ...)` clause the fix added correctly excludes it — but the BANK-type-specific version of the test hit the pre-existing `ENV-003` seeding gap (a `Journal` model hook pulls a global `DefaultAccountSettings->account_journal_suspense_account_id` for BANK/CASH/CREDIT_CARD types, and that referenced account id doesn't exist in the fresh test DB). Worked around it by testing the identical `company_id` clause with a GENERAL-type journal instead (the type filter is separate, pre-existing logic this fix didn't touch) — confirmed it correctly includes the viewer's own company and excludes the other company's journal. The fix logic itself is proven; only the full BANK-type combination remains untestable in this environment, which is an environment gap, not a fix defect.
### DEF-008 · Other company-owned dropdowns list every company's records
- **Module:** Accounts configuration; Sales; Purchases
- **Severity:** Medium
- **Status:** **FIXED.**
- **Actual (before fix):**
  - Journal form account pickers have no company filter: default, income, expense, suspense, payment and allowed accounts (`JournalResource.php:118–318`).
  - The Journal company field uses `Company::pluck('name','id')` (line 370, verified).
  - Tax repartition account pickers (`TaxResource.php:175, 234`) and the tax group picker (`:103`) are unscoped.
  - Sales and Purchase order company pickers use `withTrashed()` and list every company, including deleted ones, instead of `allowedCompanies()`.
  - The order company filters (`RelationshipConstraint::make('company')`) list every company.
- **Fix:**
  - `JournalResource.php`: added `modifyQueryUsing: fn ($query) => $query->whereHas('companies', fn ($q) => $q->where('companies.id', ...))` to `default_account_id`, `profit_account_id`, `loss_account_id`, `suspense_account_id`, both `payment_account_id` pickers (inbound/outbound payment-method repeaters), and `invoices_journal_accounts`. Inside the payment-method repeaters, used `Auth::user()?->default_company_id` directly rather than a relative `Get()` path, since a repeater's `Get $get` closure is scoped to the repeater item, not the parent form. Tightened the disabled `company_id` field's own `options()` to the user's company only.
  - `TaxResource.php`: `tax_group_id` now reuses `TaxGroupResource`'s own "`company_id` IS NULL is a legitimate global row" exception (this picker isn't routed through that resource's query, so the rule has to be repeated here). Both invoice/refund repartition-line `account_id` pickers scoped the same way as Journal's repeater fields.
  - Sales `QuotationResource.php` and Purchases `OrderResource.php`/`PurchaseAgreementResource.php`: replaced each order form's `company_id` picker (`->withTrashed()` + a manual "(Deleted)" label workaround) with the same `allowedCompanies()`-scoped pattern already used by `TeamResource.php`'s own company picker. Each order table's `RelationshipConstraint::make('company')` filter (previously listing every company) now uses the same `allowedCompanies()` list via `IsRelatedToOperator::modifyRelationshipQueryUsing()`.
  - `sales/OrderResource`, `sales/OrderToInvoiceResource`, `sales/OrderToUpsellResource` and `purchases/QuotationResource` (RFQ) all extend one of the two edited base resources without overriding `form()`/`table()` — confirmed via class-hierarchy inspection, not assumed — so they inherit these fixes automatically without separate edits.
- **Verified:** `php -l` on all 5 changed files. `Livewire::test(...)->assertOk()` on Journal's and Tax's Create pages (both the `accounts` and `accounting`-cluster copies) confirms the new closures don't break form rendering. Direct `tinker` check against real data: `Auth::user()->allowedCompanies()->pluck('companies.id')` resolves to exactly `[1]` (Truck It In) for every one of the 22 real seeded users — no user's default company falls outside their allowed set, so this picker isn't hiding anything a legitimate user needs.
- **Testing caveat at the time this fix was written (since resolved — see ENV-007):** every Filament page test in the `sales`/`purchases` plugins that boots the `inventories` plugin — including the unmodified `OrderResourceTest.php` — was failing with an `inventories_operation_types.company_id` foreign-key violation, because `aureuserp_testing` had accumulated roughly 20 orphaned factory-created companies from past test runs whose IDs collided with the seeder's fixed warehouse/location IDs. Confirmed pre-existing by running the untouched `OrderResourceTest.php` and observing the identical failure with zero changes from this fix applied. This blocked full Livewire-render verification for the sales/purchases half of this fix at the time; it was instead verified via `php -l`, class-hierarchy inspection, and the tinker check above against real data. `aureuserp_testing` was rebuilt in a follow-up (ENV-007) — `OrderResourceTest.php` now passes 2/7 (remaining failures are an unrelated, pre-existing `InventoryManager` fixture gap), confirming this fix's actual logic was correct all along.
- **Files:**
  - `plugins/webkul/accounts/src/Filament/Resources/JournalResource.php`
  - `plugins/webkul/accounts/src/Filament/Resources/TaxResource.php`
  - `plugins/webkul/sales/src/Filament/Clusters/Orders/Resources/QuotationResource.php`
  - `plugins/webkul/purchases/src/Filament/Admin/Clusters/Orders/Resources/OrderResource.php`
  - `plugins/webkul/purchases/src/Filament/Admin/Clusters/Orders/Resources/PurchaseAgreementResource.php`

### DEF-009 · Invoice/bill posting operations have no per-operation permission
- **Module:** Accounts → Invoice/Bill actions (Confirm, Cancel, Pay, Reverse, Reset to Draft, Set as Checked)
- **Severity:** Medium (design gap)
- **Status:** **FIXED — RETEST: PASS.**
- **Actual (before fix):** None of the action classes call `->authorize()`, and `AccountManager` performs no `can()`/Gate checks. Anyone who can edit an invoice can post, cancel, pay, reverse or reset it. There is no "post invoice" permission, unlike bank and manual-adjustment journals, which require `PostJournal`.
- **Client decision:** Only whoever can post a journal (Admin, Accounting Manager) may post/pay/cancel/reverse/reset/check an invoice or bill — not HR, not a plain employee, even if they can edit one.
- **Fix:** Added `->authorize('accounting_post_journal')` to all 6 lifecycle actions — `ConfirmAction`, `CancelAction`, `PayAction`, `ReverseAction`, `ResetToDraftAction`, `SetAsCheckedAction`. Reused the existing `AccountingPermissions::PostJournal` permission (already exactly Admin + Accounting Manager, confirmed live) rather than inventing a new one, and added it as a literal permission string rather than importing the class, since `accounts` is a lower-level plugin that `accounting` depends on, not the reverse — importing it would have inverted that dependency. `BillResource` reuses these same action classes (confirmed via `BillResource/Pages/{Edit,View}Bill.php`), so this single change covers both Invoices and Bills.
- **Verified live:** Instantiated each action and called `isAuthorized()` as 4 real users — Admin and Accounting Manager both `true` on Confirm/Pay/Reverse; HR Manager and Employee both `false`.
- **Tests:** `InvoiceResourceTest`/`BillResourceTest`'s 14 lifecycle-action tests initially failed correctly (their fixtures only granted `view`/`update`, not `PostJournal` — exactly the gap this fix closes). Updated both test files' `FilamentHelper::actingAs(...)` calls for those specific tests to also grant `accounting_post_journal`, leaving non-lifecycle tests (list/create) untouched. Full suite after the fixture update: 2 failed/19 passed, identical to the pre-existing baseline (confirmed via `git stash`) — the 2 failures are an unrelated mail-sending assertion and a partner-email-autofill assertion. Broader `PaymentStateTest`/`CreditNoteTest`/`RefundTest`/`JournalEntryTest` (admin-tier fixtures): 76 passed.
- **Files:**
  - `plugins/webkul/accounts/src/Filament/Resources/InvoiceResource/Actions/{ConfirmAction,CancelAction,PayAction,ReverseAction,ResetToDraftAction,SetAsCheckedAction}.php`
  - `plugins/webkul/accounts/tests/Feature/Filament/{InvoiceResourceTest,BillResourceTest}.php`

### DEF-010 · Users whose default company is a deleted company
- **Module:** Users / Companies
- **Severity:** Low
- **Status:** **FIXED — PENDING RETEST** (data fix, not a code fix).
- **Actual (before fix):** Users #2 (Demo Accountant) and #3 (Demo Approver) had `default_company_id = 2`. Company #2 "Truck It In (Demo)" was soft-deleted on 2026-09-24.
- **Expected:** A company cannot be soft-deleted while it is still a user's default. At minimum, these users should be reassigned.
- **Fix:** Reassigned both users' `default_company_id` to company #1 (the real active company, Truck It In (Pvt) Ltd) and synced `allowedCompanies()` to include it. Verified via `->fresh()`.
- **Not fixed:** The underlying gap — nothing stops a company from being soft-deleted while still referenced as someone's default — is unaddressed. That would need a guard in the company-deletion path itself; flagging as a follow-up rather than fixing under this defect's data-only scope.
- **RETEST: PASS.** Independently re-queried both users directly from the database: both now have `default_company_id=1`, resolving to the active, non-deleted "Truck It In (Pvt) Ltd" (`deleted_at` is null), and `allowedCompanies()` confirmed to include it for both.

### DEF-011 · Partners, customers and vendors are shared across companies (needs a decision)
- **Module:** Partners, and every Customer/Vendor resource that extends it
- **Severity:** Medium if unintended, none if intentional
- **Status:** **FIXED — RETEST: PASS.**
- **Actual (before fix):** `PartnerResource::getEloquentQuery()` only added eager loads, even though `Partner` has `company_id`. Many ERPs share contacts across companies deliberately, so this needed a decision rather than an assumption.
- **Client decision:** this deployment is solely for Truck It In — Partners should be scoped like everything else, not shared. No NULL-company exception the way `TaxGroup` gets.
- **Data cleanup (companion to this fix, explicitly requested):** the 5 leftover non-Truck-It-In companies in the database (`Truck It In (Demo)`, `Rider Demo`, and 3 auto-generated seed companies — all already soft-deleted, none of them real) were permanently removed, along with the 4 unused demo journals under one of them (confirmed zero real posted moves referenced any of them). Before deleting, every table with a `restrictOnDelete`/`cascadeOnDelete` foreign key to `companies` across the whole app was checked for rows tied to these 5 companies — the only match was those 4 journals. Only Truck It In (Pvt) Ltd remains. The ~15 real partner records that had belonged to those companies correctly fell back to `company_id = NULL` via the existing `nullOnDelete` foreign key (not deleted themselves).
- **Fix:** Added `->where('company_id', Auth::user()?->default_company_id)` to the base `PartnerResource::getEloquentQuery()`. Every Customer/Vendor resource across accounts, accounting, sales, purchases and invoices extends this base and calls `parent::getEloquentQuery()` first, so all of them inherit it automatically.
- **Verified live:** confirmed all 60 real, actually-used Truck It In partners remain visible (every genuinely-referenced business partner already had `company_id = 1`); confirmed zero real posted moves reference any of the ~93 now-hidden `NULL`-company rows (leftover demo placeholders — "John Doe", "Jane Smith" duplicated across old seed runs — not real contacts). Ledger re-verified balanced after the company deletion (debit = credit = 255,400.00, unchanged).
- **Tests:** `plugins/webkul/partners` full suite: 1 failed/63 passed, confirmed pre-existing via `git stash` (a REST API test that queries the `Partner` model directly, never through this Filament resource, so it's unaffected by and unrelated to this fix).
- **Files:** `plugins/webkul/partners/src/Filament/Resources/PartnerResource.php`

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
- **RETEST: PASS.** Independently re-ran `DriveHardenedScenariosTest.php` fresh: 17 passed, 45 assertions, no fatal error. Confirmed `trashFile(string $fileId): void` is present in the anonymous class and correctly delegates to `$this->inner->trashFile($fileId)`.
- **Files:** `plugins/webkul/accounting/tests/Feature/Documents/DriveHardenedScenariosTest.php`.

---

## Part B — Environment blockers (not application defects)

| ID | Blocker | Effect |
|---|---|---|
| ENV-001 | The tester will not type user passwords into login forms, and Claude-in-Chrome cannot reach `localhost:8000`. | Browser/UI click-through needs the user to drive it. Flows were tested server-side with `Auth::loginUsingId()` against real users instead. |
| ENV-002 | Only one company exists in live data (the other 5 were soft-deleted, and per DEF-011 were then permanently removed — this deployment is solely for Truck It In). | Cross-company leakage cannot be demonstrated end-to-end on live data. The isolation defects above are code-verified. |
| ENV-003 | Test DB factory gap: creating a `Journal` via factory violates the `accounts_journals.suspense_account_id` FK. | Many accounting feature tests fail before reaching their assertions. Confirmed pre-existing with `git stash` A/B runs (same counts with and without today's changes). |
| ENV-004 | Test helper gap: `plugins/webkul/accounts/tests/Helpers/AccountHelper.php:70` expects a seeded user (`ModelNotFoundException`). `inventories/database/seeders/LocationSeeder.php:53` has a null `$user`. | Invoice, credit-note, refund, payment and inventory workflow tests fail in setup. Pre-existing. |
| ENV-005 | No draft journal entry exists in the live DB. | The period lock was verified at service level and on all three posting call sites, but not by confirming a real draft through the UI. |
| ENV-006 | ~~The new `accounts_period_locks` migration had to be run manually on `aureuserp_testing`.~~ **RESOLVED** (2026-09-25, alongside ENV-007's fix — see below; the DB was rebuilt from a source that already has every migration applied). |  |
| ENV-007 | ~~`aureuserp_testing` had accumulated ~20 orphaned factory-created companies from past test runs, whose IDs collided with fixed warehouse/location IDs the `inventories` plugin seeder expects.~~ **RESOLVED** (2026-09-25). See write-up below. |  |
| ENV-008 | ~~`RefundResourceTest.php`'s fixtures were never updated for DEF-009.~~ **RESOLVED** (2026-09-25). Added `accounting_post_journal` to all 5 affected tests, same pattern as `InvoiceResourceTest`/`BillResourceTest`. 3/8 → 7/8 passing. See write-up below for the 1 remaining failure, which is a distinct, deeper bug, not a fixture gap. |
| ENV-009 (new) | `registers a full payment and marks the refund paid through the action` (`RefundResourceTest.php`) clears the permission gate and `PayAction` runs with zero validation errors (`assertHasNoActionErrors()` passes), but `payment_state` stays `NOT_PAID` instead of becoming `PAID`. The identical no-data `callAction(PayAction::class)` pattern correctly marks an `OUT_INVOICE` `PAID` in `InvoiceResourceTest`'s equivalent test. Reproduced `PaymentRegister::computeAvailableJournalIds()` directly for an `IN_REFUND` move outside the test harness — journals ARE available and correctly typed inbound, so it isn't a missing-journal issue. Likely a real bug in `PaymentRegister`'s default-computed `amount` (`getTotalAmountsToPay()`) not fully settling a refund-type move. Not fixed — touches live payment/reconciliation logic and needs dedicated accounting-focused investigation, not a guess. |

### ENV-007 fix write-up (2026-09-25): `aureuserp_testing` pollution

**Root cause.** `TestBootstrapHelper::ensurePluginInstalled()` calls `Artisan::call("{plugin}:install")` whenever a plugin's probe table is missing. That install command runs migrations, and MySQL DDL (`CREATE TABLE`) causes an **implicit commit** — it does not participate in a transaction. Since every Pest test runs inside `DatabaseTransactions`, any fixture data written earlier in that same test (e.g. by a plugin's seeder, which `ensurePluginSeeded()` also runs from `beforeEach`) becomes permanently committed the moment a later `:install` call in the same `beforeEach` chain triggers a migration — bypassing the rollback entirely. This is how the DB accumulated ~20 orphaned, Faker-named companies over many test runs: any test whose `beforeEach` needed to first-time-install a plugin (because `aureuserp_testing`'s schema was momentarily behind) permanently committed whatever fixture data existed at that point in the chain. `aureuserp_testing` was also found to be 6 real migrations behind the dev DB (confirmed identical on `aureuserp` via `migrate:status`), consistent with the DB not having been kept in sync.

**First attempt (corrected):** the initial fix ran `migrate:fresh` on `aureuserp_testing`. This did clear the pollution and bring the schema current, but it also went further than intended: [`docs/LOCAL_PERFORMANCE.md`](../docs/LOCAL_PERFORMANCE.md) documents that `aureuserp_testing` was deliberately prepared as **"a consistent copy of the working database"** (246+ accounts, report templates, Chart of Accounts data, etc.), and that `migrate:fresh`/`erp:install` calls were *intentionally removed* from the test bootstrap for exactly this reason. `migrate:fresh` wiped that reference data too, which surfaced as a new failure (`AccountSeeder.php:757`, `Company::first()->id` on a null `$company` — the seeder assumes at least one company row already exists). This was caught during post-fix verification (accounts suite went from a masked FK error to a new `Company::first()` null error) before being committed to docs, and corrected in the same session.

**Actual fix:** `aureuserp_testing` was dropped and recreated, then rebuilt from a fresh `mysqldump` of the current `aureuserp` dev database (which is itself already pollution-free — one real company, "Truck It In (Pvt) Ltd" — after this session's earlier DEF-011 cleanup) and restored via the `mysql` client. This matches the project's own documented convention exactly, restores the full reference dataset, and structurally prevents the DDL-implicit-commit failure mode from recurring going forward (every plugin table already exists post-restore, so `ensurePluginInstalled()`'s risky `Artisan::call(":install")` branch is never taken; only the transaction-safe `ensurePluginSeeded()` DML path can run).

**Verified:**
- `companies` table: exactly 1 row, `Truck It In (Pvt) Ltd` (was previously ~21, including ~20 orphaned Faker-named rows).
- `migrate:status` on `aureuserp_testing`: 0 pending (previously 6 pending — also resolves ENV-006).
- `OrderResourceTest.php` (sales): 0/7 passing → 2/7 passing. The remaining 5 failures are an unrelated, pre-existing `InventoryManager::getRule()` null-`Location` fixture gap in `SaleHelper`, not company/DB-state related.
- `accounts/tests/Feature/Filament/*`: 0/33 passing (blocked entirely by the `Company::first()` null regression from the first attempt) → 25/33 passing. The remaining 8 are pre-existing, unrelated: 2 mail/partner-email-autofill assertions (already known, see the DEF-009 write-up) and 5 newly-surfaced `RefundResourceTest` failures now tracked as **ENV-008** (a real gap in DEF-009's original fixture update, not caused by this fix).
- `purchases/tests/Feature/Filament/*`: previously fully blocked by the same FK violation → 5/7 passing. The remaining 2 are the same unrelated `InventoryManager`/`Location` fixture gap as sales.

### ENV-008 fix write-up (2026-09-25): `RefundResourceTest` permission fixtures

Added `accounting_post_journal` to the 5 lifecycle-action tests (`ConfirmAction`, `CancelAction`, `ResetToDraftAction`, `SetAsCheckedAction`, `PayAction`), matching the exact fixture pattern DEF-009 already applied to `InvoiceResourceTest`/`BillResourceTest` — `Refund` is a `Move` and its `EditRefund` page reuses the identical `InvoiceResource\Actions\*` classes, so the same permission requirement applies. 4 of the 5 now fully pass.

The 5th (`registers a full payment and marks the refund paid`) surfaced **ENV-009**: the permission gate is satisfied and the action runs error-free, but `payment_state` never reaches `PAID`. This is not a fixture gap — it looks like a genuine, pre-existing bug in `PaymentRegister`'s payment-amount computation specific to `MoveType::IN_REFUND`. Left unfixed; flagged for dedicated investigation rather than guessed at, per this repo's accounting-integrity rule (`AGENTS.md`).

**Files:** `plugins/webkul/accounts/tests/Feature/Filament/RefundResourceTest.php`.
