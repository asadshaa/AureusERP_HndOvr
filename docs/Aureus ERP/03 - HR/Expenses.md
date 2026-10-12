# Expense Claims & Reimbursements

**Status:** Implemented & Verified in Production Operations  
**Parent Note:** [[HR Overview]]  
**Related Notes:** [[Approval Workflow]], [[Journal Entries]]  

---

## 1. Domain Models & Architecture

Expense claims utilize the `Webkul\Employee\Models\EmployeeRequest` model (`employees_requests`) configured by `EmployeeRequestType` (`employees_request_types`).

### Key Features:
- **`is_financial` Flag:** Financial request types trigger automatic accounting generation upon final approval.
- **Predefined Expense Natures:** Admin-configurable expense natures per category (e.g. `Tech tools, Laptop repairs` for Tech; `Travel, Accommodation, Fuel, Food` for Travel & Entertainment).
- **Mandatory Amounts & Attachments:** Requires a positive decimal amount and uploaded supporting invoices/receipts before submission.

---

## 2. Universal 9-Category Approval Matrix

Implemented via `ClaimsWorkflowSeeder` and verified against client org charts:

| Category | Level 1 (HR Review) | Level 2 | Level 3 | Level 4 (Final Sign-off) |
|---|---|---|---|---|
| 💻 **Tech** | Mehwish | **Tech Head** *(M. Rehan Mehdi)* | **Haider Navid** | **Raza Afzal** *(CEO)* |
| ✈️ **Travel & Entertainment** | Mehwish | Requester's **Line Manager** | **Raza Afzal** | **Khurram** *(Finance)* |
| 💼 **Professional Services** | Mehwish | Requester's **Line Manager** | **Raza Afzal** | **Khurram** *(Finance)* |
| 📱 **Digital Marketing** | Mehwish | Requester's **Line Manager** | **Raza Afzal** | **Khurram** *(Finance)* |
| 👥 **People** | Mehwish | Requester's **Line Manager** | **Raza Afzal** | **Khurram** *(Finance)* |
| 💳 **Financial Provisions** | Mehwish | Requester's **Line Manager** | **Raza Afzal** | **Khurram** *(Finance)* |
| 📦 **Returns and Waivers** | Mehwish | Requester's **Line Manager** | **Raza Afzal** | **Khurram** *(Finance)* |
| 📁 **Others** | Mehwish | Requester's **Line Manager** | **Raza Afzal** | **Khurram** *(Finance)* |
| 🏢 **Real Estate** | Mehwish | **Khurram** *(2 levels only)* | — | — |

---

## 3. Automated Accounting Hand-off & General Ledger Posting

When Level 4 is approved:
1. `EmployeeRequest::synchronizeApprovalState()` triggers `EmployeeRequestService::createAccountingDraft()`.
2. Automatically generates a **Vendor Bill** (`move_type = in_invoice`) in Journal `Vendor Bills` (`type = purchase`).
3. **Partner:** Linked to the requesting employee's partner record.
4. **General Ledger Entries:**
   - **Debit:** Expense Account (`600000 - Expenses`).
   - **Credit:** Accounts Payable (`211000 - Account Payable`).
5. Calls `AccountFacade::confirmMove($move)` $\rightarrow$ Locks move into `posted` status and dispatches `MoveConfirmed`.
6. Bill reference is stored on `$employeeRequest->accounting_move_id`.

---

## 4. Source References

```text
plugins/webkul/employees/src/Services/EmployeeRequestService.php
plugins/webkul/employees/database/seeders/ClaimsWorkflowSeeder.php
plugins/webkul/support/src/Services/ApprovalEngine.php
tests/Feature/ClaimsWorkflowTest.php
```
