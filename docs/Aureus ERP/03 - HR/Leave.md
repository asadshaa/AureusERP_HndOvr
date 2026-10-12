# Leave Management & Accruals

**Status:** Implemented & Verified  
**Parent Note:** [[HR Overview]]  

---

## 1. Domain Models

Plugin: `plugins/webkul/time-off`
- **`LeaveType` (`time_off_leave_types`):** Annual, Sick, Casual, Maternity, Unpaid. Configures entitlement caps, attachment requirements, and validity periods.
- **`LeaveAllocation` (`time_off_leave_allocations`):** Granted day balances assigned to employees per cycle.
- **`Leave` (`time_off_leaves`):** Individual time-off requests containing `request_date_from`, `request_date_to`, `number_of_days`, and status.

---

## 2. Approval Routing

1. Submitting a leave request invokes `Webkul\TimeOff\Services\LeaveApprovalService`.
2. Triggers an `ApprovalRequest` (`request_type = 'leave_request'`).
3. Evaluates line manager hierarchy (`requester_manager`).
4. **Self-Approval Guard (Fixed DEF-002/003):** An employee or manager cannot approve their own leave request or allocation.
5. Upon final approval, days are debited from the employee's active allocation balance.

---

## 3. Source References

```text
plugins/webkul/time-off/src/Models/Leave.php
plugins/webkul/time-off/src/Models/LeaveAllocation.php
plugins/webkul/time-off/src/Services/LeaveApprovalService.php
```
