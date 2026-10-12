# Database Architecture

**Status:** Implemented & Verified in MySQL 8.4  
**Parent Note:** [[System Architecture]]  

---

## 1. Schema Conventions & Table Namespaces

Aureus ERP enforces strict modular namespacing for database tables:

| Domain | Table Prefix | Primary Tables | Responsibility |
|---|---|---|---|
| **Core Ledger** | `accounts_*` | `accounts_account_moves`, `accounts_account_move_lines`, `accounts_accounts`, `accounts_journals`, `accounts_period_locks` | Canonical double-entry books, period closures |
| **Financial Ops** | `accounting_*` | `accounting_bank_transaction_mappings`, `accounting_documents`, `accounting_fs_tags`, `accounting_import_runs` | Import pipelines, statement mappings, FS tags |
| **Foundation** | `support_*` | `support_companies`, `support_currencies`, `support_approval_requests`, `support_approval_steps` | Universal workflow, company master, currencies |
| **Human Resources** | `employees_*` | `employees_employees`, `employees_departments`, `employees_attendance_records`, `employees_requests` | Staff records, hierarchy, attendance, claims |
| **Security & Auth** | `security_*` / `roles` | `users`, `roles`, `permissions`, `user_allowed_companies`, `user_team` | Identity, tenant links, Spatie RBAC |

---

## 2. Key Relational Models & Schemas

### 1. General Ledger Core (`accounts_account_moves` & `_lines`)
```sql
CREATE TABLE accounts_account_moves (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    journal_id BIGINT UNSIGNED NOT NULL,
    partner_id BIGINT UNSIGNED NULL,
    move_type ENUM('out_invoice', 'in_invoice', 'out_refund', 'in_refund', 'entry') NOT NULL,
    state ENUM('draft', 'posted', 'cancel') NOT NULL DEFAULT 'draft',
    name VARCHAR(255) NULL,
    reference VARCHAR(255) NULL,
    date DATE NOT NULL,
    amount_total DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
    ...
);

CREATE TABLE accounts_account_move_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    move_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    debit DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
    credit DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
    balance DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
    ...
);
```

### 2. Multi-Company User Architecture (`user_allowed_companies`)
**Critical Distinction:** The `companies` table (`support_companies`) does **not** have a `user_id` column.
- Users connect to their primary default entity via `users.default_company_id`.
- Users connect to multiple authorized entities via the many-to-many pivot table `user_allowed_companies` (`user_id`, `company_id`).

### 3. Chart of Accounts Scoping (`accounts_account_companies`)
- `accounts_accounts` does not have a `company_id` column.
- It links to legal entities many-to-many via `accounts_account_companies`. This allows a standardized Chart of Accounts structure while enabling fine-grained company enablement.

---

## 3. Data Integrity & Invariants

1. **Strict Balance Invariant:**
   $\sum \text{debit} = \sum \text{credit}$ across lines for any move where `state = 'posted'`.
2. **Period Lock Protection:**
   The `accounts_period_locks` table stores closed period cutoffs (`lock_date`). The `PeriodLockService` intercepts any insert/update/delete affecting a transaction dated on or prior to the locked date.
3. **No Direct Balances on Accounts:**
   The database never stores a pre-computed balance column on `accounts_accounts`. All reports query sum balances directly from `accounts_account_move_lines` filtered by date and company.

---

## 4. Source References

```text
plugins/webkul/accounts/database/migrations/
plugins/webkul/accounting/database/migrations/
plugins/webkul/support/database/migrations/
plugins/webkul/employees/database/migrations/
```
