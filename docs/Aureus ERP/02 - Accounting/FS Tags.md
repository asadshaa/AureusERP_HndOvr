# Financial Statement (FS) Tags

**Status:** Implemented & Verified in Production Operations  
**Parent Note:** [[Accounting Overview]]  

---

## 1. Concept & Purpose

**FS Tags** (Financial Statement Tags) are normalized standardized tax and reporting classification codes mapped to accounts and transactions. They decouple low-level operational transaction descriptions from statutory financial reporting categories.

Model: `Webkul\Accounting\Models\FsTag` (`accounting_fs_tags`)

---

## 2. Key Attributes & Rules

- **`code`:** Unique string identifier (e.g. `OPEX_FUEL`, `SALARY_PAYROLL`, `RENT_OFFICE`).
- **`name`:** Human-readable title.
- **`company_id`:** FS Tags are strictly company-scoped.
- **Code vs Name Distinction:** The import engine matches against the **FS Tag code**, never assuming code and name are interchangeable.
- **Account Association:** FS Tags link to specific Chart of Accounts entities to suggest default debit/credit accounts during bank import and expense claim processing.

---

## 3. Workflow & Propagation

```text
Source Bank Statement / Claim
       │ (Contains tag code in payload or metadata)
       ▼
BankMappingService / FsTagService
       │ (Matches active company FS Tag by code)
       ▼
Suggested Offset Account & Tax Treatment Resolved
       │
       ▼
Propagated to Journal Line (`accounts_account_move_lines.fs_tag_id`)
       │
       ▼
Financial Reporting Engine groups lines by FS Tag for custom P&L / Cash Flow
```

---

## 4. Source References

```text
plugins/webkul/accounting/src/Models/FsTag.php
plugins/webkul/accounting/src/Services/FsTagService.php
tests/Feature/FsTagJournalLinePropagationTest.php
tests/Feature/InvoiceFsTagAccountingImpactTest.php
```
