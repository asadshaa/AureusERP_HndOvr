# Bank Statements

**Status:** Implemented & Verified  
**Parent Note:** [[Accounting Overview]]  

---

## 1. Domain Models

- **`BankStatement` (`accounts_bank_statements`):** Represents an imported statement period for a specific bank journal.
  - `journal_id`: The linked bank journal.
  - `company_id`: Company scope.
  - `date`: Statement closing date.
  - `balance_start` / `balance_end_real`: Opening and ending statement balances.
- **`BankStatementLine` (`accounts_bank_statement_lines`):** Individual transaction rows within the statement.
  - `date`: Transaction posting date.
  - `amount`: Inflow (+ve) or Outflow (-ve).
  - `payment_ref`: Transaction description from bank feed.
  - `partner_id`: Optional counterparty.
  - `is_reconciled`: Boolean flag indicating completed matching.

---

## 2. Invariant & Checksum Guarantees

1. **Balance Mathematical Integrity:**
   $$\text{balance\_start} + \sum \text{line amounts} = \text{balance\_end\_real}$$
2. **Deduplication:**
   Statements calculate a SHA-256 fingerprint over line dates, amounts, and descriptions. Re-uploading the same statement detects duplication and rejects the redundant import.

---

## 3. Source References

```text
plugins/webkul/accounts/src/Models/BankStatement.php
plugins/webkul/accounts/src/Models/BankStatementLine.php
plugins/webkul/accounting/src/Services/Bank/BankStatementValidationService.php
```
