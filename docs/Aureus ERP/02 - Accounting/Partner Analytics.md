# Partner Analytics

**Status:** Implemented & Verified  
**Parent Note:** [[Accounting Overview]]  

---

## 1. Scope & Objective

**Partner Analytics** aggregates historical financial interactions, balances, aging schedules, and cash exposure for both customers and vendors across company operations.

---

## 2. `PartnerAnalyticsService`

Located in `plugins/webkul/accounting/src/Services/PartnerAnalyticsService.php`.

### Metrics Computed:
- **Total Invoiced / Billed:** Cumulative historical invoiced or billed volume.
- **Current Outstanding Balance:** Net open debit/credit balance derived from posted, unreconciled `accounts_account_move_lines`.
- **Aged Receivables / Payables Breakdown:**
  - 0–30 Days
  - 31–60 Days
  - 61–90 Days
  - 90+ Days Past Due
- **Average Payment Turnaround (DSO / DPO):** Average days between invoice posting and payment reconciliation.

### Data Isolation Rule:
Partner analytics are calculated strictly within the boundary of the active `company_id`. Even if a vendor or customer exists across multiple entities, their financial metrics never blend across company borders.

---

## 3. Source References

```text
plugins/webkul/accounting/src/Services/PartnerAnalyticsService.php
plugins/webkul/partners/src/Models/Partner.php
```
