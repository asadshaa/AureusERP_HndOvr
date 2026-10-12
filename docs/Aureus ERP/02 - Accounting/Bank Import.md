# Bank Statement Import & Mapping Pipeline

**Status:** Implemented & Verified in Production Operations  
**Parent Note:** [[Accounting Overview]]  

---

## 1. End-to-End Pipeline Overview

```text
Source File (CSV / XLSX multi-tab workbook)
     │
     ▼
[ Parser Layer ]
CommonWorkbookBankStatementParser / HblBankStatementParser / MeezanBankStatementParser
     │ (Normalizes date, amount, description, parses opening/closing balances)
     ▼
[ Preview & Validation ]
ImportPreviewService (Validates dates, detects duplicate SHA-256 fingerprint, previews rows)
     │
     ▼
[ Statement Persisted ]
BankStatement & BankStatementLine created in database
     │
     ▼
[ Transaction Mapping ]
BankMappingService (Applies BankMappingRules, BusinessRules, and FS Tags)
     │
     ▼
[ Manual Review & Approval ]
BankTransactionMapping in Filament UI (accountant reviews suggested offset accounts & FS tags)
     │
     ▼
[ Journal Generation ]
BankJournalService (Generates balanced Draft Move in General Ledger)
     │
     ▼
[ Ledger Posting ]
Authorized Controller confirms Move → hits General Ledger & reports
```

---

## 2. Key Components & Responsibilities

### 1. `CommonWorkbookBankStatementParser`
- Inherits from `AbstractSpreadsheetBankStatementParser`.
- Parses complex multi-tab bank workbooks where statements, closing balances, and transaction logs reside across multiple sheets.
- Handles date format normalization (ISO, UK `d/m/Y`, US `m/d/Y`) and signed amounts (Debit/Credit columns vs single Amount column).

### 2. `ImportPreviewService` & `ImportExecutionService`
- **`ImportPreviewService`:** Dry-runs the upload without writing to permanent tables. Outputs row counts, debit/credit totals, unresolved accounts, and warnings.
- **`ImportExecutionService`:** Performs the actual persistent creation inside a database transaction. Guarantees idempotency — running the same file twice produces no duplicates.

### 3. `ImportEntityRegistry`
- Maintains registration of supported entities for tabular import (Bank Statements, Chart of Accounts, Journal Items).
- Enforces data transformers and validation schemas per entity type.

### 4. `BankMappingService`
- Analyzes `BankStatementLine` records to determine:
  1. **Counterparty (`partner_id`):** Matched against known vendors, customers, or employees.
  2. **Offset Account (`account_id`):** Resolved using priority hierarchy:
     - Exact match rule (`BankMappingRule`)
     - Configured [[FS Tags]] mapping
     - Pattern/keyword description match
     - Suspense / unclassified clearing account fallback

### 5. `BankJournalService`
- Converts approved mappings into real double-entry journal entries:
  - Bank GL line (debited or credited depending on cash flow direction).
  - Offset GL line (clearing expense, revenue, or AP/AR account).
  - Preserves statement line provenance on the move lines.

---

## 3. Source References

```text
plugins/webkul/accounting/src/Services/Bank/CommonWorkbookBankStatementParser.php
plugins/webkul/accounting/src/Services/Bank/BankStatementImportService.php
plugins/webkul/accounting/src/Services/Bank/BankMappingService.php
plugins/webkul/accounting/src/Services/Bank/BankJournalService.php
plugins/webkul/accounting/src/Services/Import/ImportEntityRegistry.php
tests/Feature/BankWorkflowEndToEndTest.php
```
