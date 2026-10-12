# Chart of Accounts

**Status:** Implemented & Verified  
**Parent Note:** [[Accounting Overview]]  

---

## 1. Structure & Model

The Chart of Accounts (CoA) is defined in `Webkul\Account\Models\Account` (`accounts_accounts`).

### Key Schema Fields:
- `code`: Unique account identifier within a company (e.g. `101000` for Cash, `211000` for Accounts Payable, `600000` for Expenses).
- `name`: Display name.
- `account_type`: Enum defining financial category:
  - `ASSET_CURRENT`, `ASSET_NON_CURRENT`, `ASSET_PREPAYMENTS`
  - `LIABILITY_CURRENT`, `LIABILITY_PAYABLE`, `LIABILITY_NON_CURRENT`
  - `EQUITY`
  - `INCOME`, `INCOME_OTHER`
  - `EXPENSE`, `EXPENSE_DEPRECIATION`, `EXPENSE_DIRECT_COST`
  - `OFF_BALANCE`
- `parent_id`: Self-referencing foreign key supporting arbitrary account hierarchies.
- `is_group`: Boolean flag. Group accounts serve as header summaries and cannot have journal lines posted to them.
- `reconcile`: Boolean flag indicating if lines on this account require matching (e.g. Bank, AR, AP).

---

## 2. Multi-Company Attachment

An account does **not** store a direct `company_id`. Instead, accounts attach to legal entities via the `accounts_account_companies` many-to-many pivot table:
```php
$account->companies()->attach($companyId);
```
This enables a unified chart of accounts catalog while ensuring company isolation.

---

## 3. Account Importer Pipeline

The automated importer parses client Chart of Accounts spreadsheets (CSV/XLSX) and creates both the account hierarchy and opening balances:
```text
Source Workbook (CSV/XLSX)
    ↓
CoaReader & CoaHeaderDetector
    ↓
CoaRowParser (Validates codes, descriptions, balance types)
    ↓
CoaAccountTypeMapper (Maps source categories to AccountType enums)
    ↓
CoaImportService (Creates/updates accounts, attaches company pivot)
    ↓
CoaMigrationJournalService (Generates balanced opening-balance journal entry)
```

### Safety Rules:
- Never overwrites accounts not created by the import batch (`import_batch_id IS NULL`).
- Balances are imported by creating balanced journal entries into a migration journal, never by modifying database account records directly.

---

## 4. Source References

```text
plugins/webkul/accounts/src/Models/Account.php
plugins/webkul/accounting/src/Services/Coa/CoaImportService.php
plugins/webkul/accounting/src/Services/Coa/CoaMigrationJournalService.php
tests/Feature/CoaImportAcceptanceTest.php
```
