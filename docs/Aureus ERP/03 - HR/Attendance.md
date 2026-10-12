# Geofenced Attendance & Time Management

**Status:** Implemented & Verified in Production Operations  
**Parent Note:** [[HR Overview]]  

---

## 1. Geofenced Mobile Tracking

Aureus ERP features GPS-verified attendance logging tailored for field logistics and office staff:

### Key Services:
- **`GeofencedAttendanceService`:** Coordinates check-in and check-out requests.
- **`GeofenceEvaluator` & `GeoDistanceCalculator`:** Computes Haversine distance between device coordinates (latitude, longitude) and configured work location geofences.
- **Verification Thresholds:** Validates accuracy radius. If a check-in occurs outside permitted geofences, it is marked as `unverified` and flagged for manager review.

---

## 2. Shift Completion & Forgotten Shift Background Jobs

Configured in `routes/console.php`:
1. **`hr:notify-shift-completion`:**
   - Runs every 15 minutes.
   - Evaluates employees whose elapsed working hours reach 8.0 hours or whose scheduled shift window has closed.
   - Dispatches desktop and database notifications reminding the employee to check out.
2. **Forgotten Shift Detection:**
   - Detects active check-ins that span across midnight without a check-out.
   - Automatically marks the record as forgotten/incomplete and invites the employee to file an attendance time-change request.

---

## 3. Attendance Time Change Requests

If an employee forgets to check in or out, or requires adjustment:
1. Submits an **Attendance Time Change Request** via self-service.
2. Captures original times and requested changes in `employees_requests.payload`.
3. Routes to the employee's Line Manager (`requester_manager`).
4. Upon approval, the `AttendanceRecord` is automatically updated and marked as manager-verified.

---

## 4. Source References

```text
plugins/webkul/employees/src/Services/Attendance/GeofencedAttendanceService.php
plugins/webkul/employees/src/Services/Attendance/GeofenceEvaluator.php
app/Console/Commands/NotifyShiftCompletionCommand.php
tests/Feature/AttendanceWorkflowFixesTest.php
```
