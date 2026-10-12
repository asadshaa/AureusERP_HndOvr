# Performance Reviews

**Status:** Implemented (Basic Model & Form Scaffolded; End-to-End Execution Unverified)  
**Parent Note:** [[HR Overview]]  

---

## 1. Domain Models

- **`PerformanceCycle` (`employees_performance_cycles`):** Defines evaluation periods (e.g. Q1, Annual Review).
- **`PerformanceReview` (`employees_performance_reviews`):** Individual appraisal forms linking employee, reviewer (manager), objectives, ratings, and feedback.

---

## 2. Current Implementation State

- **Implemented:** Models, migrations, Filament admin resource (`PerformanceReviewResource`), status lifecycle (`draft`, `in_progress`, `submitted`, `completed`).
- **Unverified / Limitations:** Live end-to-end multi-reviewer appraisal rounds have not undergone comprehensive QA cycles. It remains categorized as unverified in live operations.

---

## 3. Source References

```text
plugins/webkul/employees/src/Models/PerformanceReview.php
plugins/webkul/employees/src/Filament/Resources/PerformanceReviewResource.php
```
