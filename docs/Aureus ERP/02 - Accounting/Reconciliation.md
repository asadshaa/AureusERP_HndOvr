# Bank & Ledger Reconciliation

**Status:** Implemented & Verified  
**Parent Note:** [[Accounting Overview]]  

---

## 1. Scope & Mechanism

Reconciliation in Aureus ERP addresses two operational workflows:
1. **Bank Reconciliation:** Matching imported bank statement lines against existing open payments or invoices.
2. **Account Reconciliation (AP / AR):** Matching invoice receivables against customer payment receipts, or vendor bills against supplier disbursements.

---

## 2. `AccountingReconciliationService`

Located in `plugins/webkul/accounting/src/Services/AccountingReconciliationService.php`.

### Responsibilities:
- **Candidate Matching:** Evaluates open `accounts_account_move_lines` on reconcilable accounts (`reconcile = true`) matching partner, currency, and residual amounts.
- **Partial Reconciliations:** Tracks partial payments via residual balance computation (`amount_residual`). A bill of PKR 10,000 paid PKR 4,000 leaves PKR 6,000 open with `payment_state = 'partial'`.
- **Payment State Updates:** When residual reaches zero, the move's `payment_state` transitions automatically to `paid`.
- **Reconciliation Integrity:** Reconciliation cannot match lines across different companies or mismatch currencies without calculating realized exchange rate gain/loss.

---

## 3. Source References

```text
plugins/webkul/accounting/src/Services/AccountingReconciliationService.php
plugins/webkul/accounting/src/Services/Bank/BankTransferMatchingService.php
tests/Feature/BankInvoiceReconciliationTest.php
```
