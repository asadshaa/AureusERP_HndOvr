# Timesheets

**Status:** Implemented (Scaffolded; Deep Project Tracking Unverified)  
**Parent Note:** [[HR Overview]]  

---

## 1. Domain Models

- **`Timesheet` (`timesheets_timesheets`):** Logs hourly allocations against projects, tasks, and employees.
- Fields: `employee_id`, `project_id`, `task_id`, `date`, `unit_amount` (hours), `description`.

---

## 2. Implementation Status & Limitations

- **Implemented:** Basic CRUD resources in Filament, employee assignment, and company scoping.
- **Unverified:** Automated billing integration from timesheets into customer invoices has not been implemented or verified.

---

## 3. Source References

```text
plugins/webkul/timesheets/src/Models/Timesheet.php
plugins/webkul/timesheets/src/TimesheetPlugin.php
```
