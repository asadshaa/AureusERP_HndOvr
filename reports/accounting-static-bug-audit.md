# Accounting Static Bug Audit

**Method:** No manual testing. 8 parallel agents each read the actual source of one Accounting
sub-domain and reported candidate defects, calibrated against real bugs already found and fixed
in this exact codebase (the outstanding-account company preference, the non-existent
`internal_group` column, the bank-matching offset bug, the `MoveLine` hook-ordering bug, and the
two shared `ApprovalEngine` bugs from the HR audit). Every candidate was then independently
re-read by two adversarial reviewers instructed to refute it.

**Result:** 14 candidates found → 14 confirmed by the audit's own two-verifier process. A second,
manual re-verification pass against the current branch state (done after the `dc792ba`
cherry-pick, in response to the reasonable question "didn't we already fix some of these?")
found the audit's own process had let 6 of the 14 through incorrectly:

- **5 were duplicates** of bugs already fixed in an earlier accounting session (commit `dc792ba`)
  that had never been merged into `master` — the branch this audit ran against was missing that
  whole commit. One of the five (the CoA-import reconcile flag) was missed in the first triage
  pass and only caught on this closer re-check.
- **1 was a false positive that both adversarial verifiers missed** — the "duplicate GL code
  race" finding claimed no lock existed between the uniqueness check and the insert, but the
  actual code wraps both inside `Cache::lock(...)->block(...)`, which does serialize concurrent
  calls. Both verifier agents asserted the lock didn't exist despite it being in the file they
  reviewed. This is flagged here as a process failure, not swept under the rug.

**8 distinct, new, confirmed bugs remain — all 8 are now fixed.** P0 items 1–4: commit `be61a8d`.
P1 items 5–8: commits `87025c0`, `f054be0`, and `ce3e5f3`.

---

## Already fixed (found by the audit, turned out to be duplicates — noted for transparency)

- `MoveLine`'s saving hook computing the GL account before `display_type` was resolved
- `InvoiceResource` / `BillResource` missing company scoping entirely
- `PaymentResource` missing company scoping entirely
- `JournalEntryResource` scoped only by ownership, never by company
- Chart-of-Accounts import not setting `reconcile` on receivable/payable accounts
  (`CoaImportService.php` already sets `'reconcile' => $type->isReconcilable()` at both create and
  update sites)

All five are confirmed fixed on this branch as of commit `52b5d44`.

## Refuted (confirmed by both adversarial verifiers, but wrong on closer manual re-check)

- **"Two accounts with the same code can be created for the same company"**
  (`CanonicalAccountCreationService.php`) — the claim was that the uniqueness check and the
  insert race with no lock between them. In fact both the check
  (`companyAccountCodeExists()`) and the insert happen inside
  `Cache::lock("accounting-account-code:{company_id}", 10)->block(5, ...)`, which does serialize
  concurrent calls for the same company. Not a bug.

---

## P0 — Silent financial data corruption (all fixed, commit `be61a8d`)

### 1. A reversed journal entry doesn't actually cancel the original — FIXED
`plugins/webkul/accounts/src/AccountManager.php:2011`

`reverseMoves()` flips a reversal line's `balance` and `amount_currency` by directly calling
`MoveLine::update()` — but `MoveLine`'s `saving` hook never recomputes `debit`/`credit` from that
flipped balance. The persisted `debit`/`credit` columns stay identical to the original line, so
`balance == debit - credit` breaks, and any report or journal-items view that reads debit/credit
(not the internal `balance` field) sees the "reversal" as a duplicate of the original entry
rather than a cancellation. A posted entry (debit=100/credit=0) "reversed" still shows debit=100
afterward instead of credit=100.

### 2. An unbalanced journal entry can be posted — FIXED
`plugins/webkul/accounts/src/AccountManager.php:56` (`confirmMove()` / `isConfirmAllowedForMove()`)

`isConfirmAllowedForMove()` checks partner presence, bank archival, total sign, invoice date,
draft-state, non-empty lines, deprecated accounts, journal presence, and currency presence —
every reasonable precondition **except** whether the move's lines actually balance
(`sum(debit) == sum(credit)`). `confirmMove()` then unconditionally flips the state to `POSTED`.
A move with debit=100/credit=50 lines posts cleanly, silently putting the ledger out of balance
by 50.

**Fix note:** the balance check has to run *after* `computeAccountMove()` generates an invoice's
tax/payable lines, not before — checking earlier rejects every ordinary bill/invoice post, since
those balancing lines don't exist yet at precondition-check time. Adding the check (correctly
placed) immediately caught a second, previously-undetected bug of the exact class it exists to
prevent: registering a partial payment with "reconcile" difference handling built a write-off
line with only `balance` set, no `debit`/`credit` — and `computeAccountMove()` silently zeroed
that balance back out for the non-invoice payment move, posting it unbalanced. Fixed alongside
this item.

### 3. Zero-balance check uses the wrong currency's rounding threshold — FIXED
`plugins/webkul/accounts/src/Models/Payment.php:323` (`computeState()`)

`amount_residual` is always computed and rounded in the **company** currency (confirmed in
`MoveLine.php`). But `computeState()` tests it for zero using `$this->move->currency->isZero(...)`
— the payment's **transaction** currency, not the company currency — and `Currency::isZero()`
uses that specific currency's own `rounding` precision as the threshold. A company on BHD
(rounding 0.001) with a JPY-denominated payment (rounding 1) can have a genuinely-unpaid 0.5 BHD
residual wrongly read as "zero" under JPY's coarser threshold, flipping the payment to `PAID`
while money is still owed. The sibling method `computeReconciliationStatus()` a few lines below
gets this right by matching the residual field to the currency; `computeState()` doesn't.

### 4. A within-file duplicate transaction crashes the entire bank statement import — FIXED
`plugins/webkul/accounting/src/Services/Bank/BankStatementImportService.php:162`

The validator correctly flags a duplicate transaction as a soft, reviewable error (same tier as
a missing date or zero amount) and the whole design intends the import to still complete, tagged
`ReconciliationFailed`, so the user can review it. But the import loop inserts every transaction
unconditionally with no check against those errors, and the schema has a **hard unique
constraint** on the transaction fingerprint. The second duplicate row's insert throws an uncaught
`QueryException`, which rolls back the entire database transaction — discarding the whole
statement, including every valid, non-duplicate row — and surfaces a raw SQL error instead of the
intended "imported for review" outcome.

---

## P1 — Wrong money moved / matched (all fixed)

### 5. An approved exchange rate can be finalized from a value nobody actually approved — FIXED
`plugins/webkul/accounting/src/Services/Currency/ExchangeRateApprovalService.php:48` (commit `87025c0`)

The record stays editable through the entire approval process (its `Edit` action is only hidden
once `approval_status` is literally `Approved`, and nothing moves it out of `Draft` while a
request is pending — there's no `Pending` value in the status enum at all). `approve()` only
checks that *some* approved `ApprovalRequest` row exists for the subject — no ordering, no
comparison against what was actually captured at submission time. So: submit a rate → an approver
approves the *original* value → before anyone clicks the resource's own "approve" button, the
submitter edits the rate to something else entirely → clicking approve finalizes the **edited**
value using the stale approval, and it immediately feeds live bank-statement currency conversion.

### 6. The same open invoice/bill can be auto-suggested as the match for two different bank lines — FIXED
`plugins/webkul/accounting/src/Services/Bank/BankMatchingPriorityService.php:40` (commit `f054be0`)

Each bank statement line's candidate match is found via a fresh, independent query with no
tracking of which open documents earlier lines in the *same batch* have already claimed (contrast
with the sibling `BankTransferMatchingService`, which does track consumed candidates). Two
statement lines referencing the same invoice both independently see it as their unique match and
both get marked `Suggested` with `confidence = 1` — silently double-claiming a single obligation.

### 7. Matching ignores whether money is coming in or going out — FIXED
`plugins/webkul/accounting/src/Services/Bank/BankMatchingPriorityService.php:40` (commit `f054be0`)

The candidate-move query never checks that a bank **credit** (money in) is being matched to a
receivable or that a bank **debit** (money out) is matched to a payable — only amount and
reference text. `BankMappingService::matches()` already does this direction check for rule-based
matching a few files over; the priority-suggestion path doesn't. An incoming customer payment can
get suggested against an outstanding vendor bill.

### 8. A missing exchange rate silently becomes a 1:1 conversion — FIXED
`plugins/webkul/support/src/Models/Currency.php:100` (commit `ce3e5f3`)

`getConversionRate()` falls back to `1.0` with no error, warning, or flag whenever no rate record
exists for the currency/date/company — unlike the newer `ExchangeRateService::resolve()`, which
throws for exactly this case. A EUR 1000 invoice with no configured rate gets booked as if 1 EUR
= 1 USD, silently corrupting the foreign-currency figures and any FX-based reporting drawn from
them.

**Fix note:** `getConversionRate()`/`convert()` are used by 13 call sites across accounts,
purchases, products and inventories, several with zero exception handling anywhere in their call
chain (Eloquent `saving` hooks, unattended procurement flows) — flipping the default everywhere
would have turned routine saves into fatal errors. Added an opt-in `strict` parameter instead
(default `false`, every existing call site unchanged) plus a `Log::warning()` on every non-strict
fallback so the "no warning" part is fixed unconditionally. Flipped only
`PaymentRegister::getTotalAmountsToPay()` — the actual amount-to-pay calculation for money being
registered as paid, whose callers already have proper exception handling — to `strict: true`. The
other unprotected hot paths (`Payment.php`, `Move.php`, `AccountManager.php`'s rounding sync, the
product/procurement chain) are left lenient for now; hardening those needs try/catch added at each
call site first, which is separate, broader work.

---

## Fix order

1. ~~**P0 items 1–4**~~ — done, commit `be61a8d`.
2. ~~**P1 items 5–8**~~ — done, commits `87025c0` (item 5), `f054be0` (items 6–7), `ce3e5f3`
   (item 8).

All 8 confirmed bugs from this audit are now fixed. Every fix got the same treatment as the HR
audit fixes: explain, fix, add a regression test, verify fail-then-pass by isolating the fix.
