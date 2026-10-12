# Accounting Tests

Status: Implemented / Verified
Related: [[Testing Overview]], [[Journal Entries]], [[Chart of Accounts]], [[Reconciliation]], [[Exchange Rates]], [[Partner Analytics]], [[Tax Requirements]]

Accounting tests verify strict mathematical consistency, immutable audit trails, multi-currency conversion, and statutory tax calculations across the general ledger.

---

## 1. Mathematical Invariant Verification

Direct database verification scripts execute SQL assertions across `accounts_account_move_lines` and `accounts_account_moves`:

1. **Global Ledger Balance**:
   - `SUM(debit) = SUM(credit) = 255,400.00 PKR` across all posted moves in the entire ledger.
   - Verified: **PASS**.
2. **Accounting Equation Parity**:
   $$\text{Assets} = \text{Liabilities} + \text{Equity} + \text{Net Profit}$$
   - Evaluated independently from raw ledger balances: `-5,600 == -5,600`.
   - Verified: **PASS**.
3. **Move Line State Consistency**:
   - Every `accounts_account_move_lines.parent_state` strictly mirrors its parent move's `state` (`draft` vs `posted`).
   - Verified: **PASS**.
4. **Account & Company Attachment**:
   - Zero move lines exist whose underlying `account_id` is not attached to the move's `company_id`.
   - Zero postings permitted to group or deprecated accounts.
   - Verified: **PASS**.
5. **Report Service Parity**:
   - `TrialBalanceService::compute()` and `BalanceSheet::balanceSheetData()` produce identical closing balances (141,550 closing debit/credit; -5,600 Balance Sheet total) matching raw ledger queries.
   - Verified: **PASS**.

---

## 2. Pest Feature Suites

Key feature test suites under `plugins/webkul/accounting/tests` and `plugins/webkul/accounts/tests`:

- **Tax Compliance (`TaxComplianceTest.php`)**:
  - Tests 8 statutory scenarios: company sales-tax registration requirements, mandatory STRN enforcement, vendor bill exemption, and NTN persistence.
- **Tax Picker Hardening (`TaxPickerHardeningTest.php`)**:
  - Verifies filtering of purchase vs sales taxes and prevents selection of inactive tax codes.
- **Reconciliation Engine (`AccountingReconciliationTest.php`)**:
  - Tests residual debt calculations, partial payments, cash discount handling, and cross-currency reconciliation.
- **Exchange Rates & FX (`ExchangeRateTest.php`, `FxRevaluationServiceTest.php`)**:
  - Verifies daily market rate conversion, approval thresholds, and automated month-end unrealized FX gain/loss journal creation.
- **Document Storage (`DocumentServiceTest.php`, `DocumentIntegrityCheckerTest.php`)**:
  - Verifies SHA-256 calculation, stream tampering rejection, and polymorphic attachment isolation.

---

## 3. Double-Posting Safeguard Testing

A critical test verifies the **bank reconciliation double-posting guard**:
- Reconciling a bank statement line against a payment clearing account (`101403 Outstanding Receipts`) verifies that the account balance is not driven past its expected limit.
- Refuses to post if the underlying invoice/bill payment was already cleared, preventing phantom cash creation.
