# HR Tests

Status: Implemented / Verified
Related: [[Testing Overview]], [[HR Overview]], [[Onboarding]], [[Attendance]], [[Leave]], [[Expenses]], [[Approvals]]

HR tests validate employee hierarchy resolution, geofenced mobile attendance, leave allocation guardrails, and automated expense-to-accounting bridges.

---

## 1. Attendance & Geofencing Suites

Tested in `plugins/webkul/employees/tests` and verified through `GEOfenced_ATTENDANCE_IMPLEMENTATION_REVIEW.md`:
- **Haversine Distance Accuracy**:
  - Validates that check-ins within the designated office radius (e.g. 50 meters of corporate headquarters) are accepted with status `APPROVED`.
  - Check-ins outside the geofence perimeter are flagged with `GEOFENCE_VIOLATION` requiring supervisor sign-off.
- **Forgotten Shift Detection**:
  - Tests the scheduled cron command detecting open check-ins exceeding maximum shift length (14 hours) and auto-closing them with audit remarks.
- **Attendance Time Modification**:
  - Tests employee shift change requests and verifies that supervisor approval updates the original attendance record without creating orphan entries.

---

## 2. Leave & Allocation Guardrails

Verified in `APP_TESTING_STATUS.md` under defects **DEF-002** and **DEF-003**:
- **Self-Approval Prevention (DEF-002)**:
  - Tests on `My Allocations` screen verify that self-approval buttons are suppressed and server-side calls throw unauthorized exceptions.
- **Manager Subordinate Approval (DEF-003)**:
  - Verifies that while managers are blocked from approving their own leaves, they can successfully approve leaves and allocations submitted by legitimate direct reports.
- **Balance Exhaustion Guards**:
  - Submitting leave requests exceeding accrued allocation balances is rejected by `LeaveApprovalService`.

---

## 3. Expense Routing & Tech Head Verification

- **Hierarchy Routing**:
  - Feature tests verify that `HrHierarchyService` correctly maps employee department categories to functional heads.
  - Submissions under Category IT/Software/Cloud by employee Mehwish route directly to the queue of Tech Head **Raza Afzal**.
- **General Ledger Bridge**:
  - Approving a technical expense claim confirms creation of vendor bill `BILL/2026/xxx`.
  - Verifies move line generation debiting `6111000 - IT & Software Expenses` and crediting Accounts Payable without debit/credit imbalance.
