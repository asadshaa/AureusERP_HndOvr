# Authorization & Company Scoping

**Status:** Implemented & Verified  
**Parent Note:** [[System Architecture]]  

---

## 1. Multi-Company Tenant Boundary

Aureus ERP uses strict row-level company isolation. Every operational record belongs to a specific legal entity (`company_id`).

### Enforcement Mechanisms:
1. **Tenant Middleware & Session Context:**
   The active company is resolved from `Auth::user()->default_company_id`.
2. **Resource Scoping via `getEloquentQuery()`:**
   Every Filament Resource overrides `getEloquentQuery()` to scope results to the active company:
   ```php
   public static function getEloquentQuery(): Builder
   {
       return parent::getEloquentQuery()
           ->where('company_id', Auth::user()?->default_company_id);
   }
   ```
3. **Form Picker Scoping:**
   Select dropdowns and relationship fields (such as Accounts, Journals, Taxes, Vendors) must always filter options by `company_id`. An unscoped picker is treated as a high-severity security bug.

---

## 2. Row-Level Permission Scopes (`HasPermissionScope`)

Models using the `Webkul\Security\Traits\HasPermissionScope` trait automatically enforce fine-grained record visibility based on the user's `resource_permission` setting:

- **`GLOBAL`:** The user can access all records belonging to their active company.
- **`GROUP`:** The user can access only records created by members of their assigned team(s) (`user_team` pivot).
- **`INDIVIDUAL`:** The user can access only records where `creator_id == Auth::id()`.

---

## 3. Role-Based Access Control (RBAC) & Segregation of Duties

The application defines distinct financial and administrative roles:

| Role | Permissions & Scope | Restricted Operations |
|---|---|---|
| **Admin** | Full system administration, company setup, user roles | Gated by ApprovalEngine (cannot bypass approval steps) |
| **Accounting Manager / Controller** | Approves journals, posts moves to GL, toggles period locks | None within accounting |
| **Accountant** | Prepares entries, maps bank transactions, uploads statements | Cannot post to GL, cannot lock periods |
| **Internal / External Auditor** | Read-only inspection across all ledger moves, audits, and chatter | Strictly zero write permissions |
| **HR Manager** | Manages employees, approves leave, reviews level-1 expense claims | Cannot post accounting moves directly |
| **Manager** | Views team subordinates, approves level-2 line/dept claims | Scoped strictly to team/department hierarchy |
| **Employee** | Self-service attendance, submits expense claims and leaves | Sees only own profile and personal submissions |

---

## 4. Source References

```text
plugins/webkul/security/src/Models/Scopes/UserPermissionScope.php
plugins/webkul/security/src/Traits/HasPermissionScope.php
plugins/webkul/accounting/src/Support/AccountingPermissions.php
plugins/webkul/support/src/Policies/ApprovalRequestPolicy.php
```
