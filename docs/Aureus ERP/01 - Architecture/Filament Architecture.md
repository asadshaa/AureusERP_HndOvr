# Filament Architecture

**Status:** Implemented & Verified  
**Parent Note:** [[System Architecture]]  

---

## 1. Panel & Cluster Scaffolding

Aureus ERP operates through **Filament v5** admin panels. The primary admin entry point is configured in [AdminPanelProvider.php](file:///c:/Intern/Handover/Erp/app/Providers/Filament/AdminPanelProvider.php) at route prefix `/admin`.

### Clusters & Grouping
To maintain an uncluttered navigation tree, resources are grouped into **Filament Clusters**:
- **Accounting:** `Reporting` cluster (Trial Balance, Balance Sheet, P&L, Cash Flow) and `Configuration` cluster (FS Tags, Drive Ingestion Classifications, Bank Mapping Rules).
- **Invoices:** `Customers` cluster (Invoices, Credit Notes) and `Vendors` cluster (Bills, Refunds).
- **Settings:** Company settings, Currency management, Approval Queue, User access.

---

## 2. Server-Side Enforcement vs UI Visibility

A core security standard across the ERP is that **Filament UI visibility is never treated as authorization**:
- **UI Visibility:** Using `->visible(fn () => ...)` or `->hidden(fn () => ...)` simply hides buttons from the user interface.
- **Server-Side Enforcement:** Actions implement:
  1. `->authorize(...)` using Spatie / Policy gates.
  2. Guard callbacks in `before(function (Action $action) { if (!...) { $action->halt(); } })`.
  3. Domain-level assertions in the underlying Service class (e.g. `ApprovalEngine::canAct()`).

---

## 3. Dynamic Livewire Components & Summaries

Filament tables utilize reactive Livewire components for real-time calculations:
- `plugins/webkul/accounts/src/Livewire/InvoiceSummary.php`: Dynamically computes subtotal, active tax lines, withholding deductions, and total amount payable as line items are modified in the form.
- **Table Column Managers & Reordering:** Custom components in `plugins/webkul/support` allow users to reorder and toggle visible columns without losing state.

---

## 4. Notifications & Action Standards

In Filament v3/v5:
- All action triggers inside notifications must import **`Filament\Actions\Action`**, not the legacy `Filament\Notifications\Actions\Action`.
- Notifications can be broadcast, flashed to session, or stored persistently in the database via `$notification->sendToDatabase($recipients)`.

---

## 5. Source References

```text
app/Providers/Filament/AdminPanelProvider.php
plugins/webkul/support/src/Filament/Resources/ApprovalRequestResource.php
plugins/webkul/accounts/src/Filament/Resources/BillResource.php
plugins/webkul/employees/src/Filament/Resources/EmployeeResource.php
```
