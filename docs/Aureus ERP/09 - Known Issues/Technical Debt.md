# Technical Debt

Status: Documented
Related: [[Bugs]], [[Risks]], [[Architecture Decisions]], [[System Architecture]]

Technical Debt identifies architectural compromises, legacy code burdens, and optimization opportunities across the Aureus ERP codebase.

---

## 1. Upstream Package Inheritance

Aureus ERP is built on top of modular Webkul packages (`plugins/webkul/*`). Several architectural liabilities stem from this foundation:
1. **Unscoped Eloquent Queries**:
   - Upstream Webkul code frequently implemented `getEloquentQuery()` without appending `where('company_id', ...)` or checking `user_allowed_companies`.
   - While major accounting and operational modules were patched during DEF-005/DEF-006/DEF-008, third-party plugin updates could overwrite these fixes if not carefully protected by merge discipline.
2. **Heavy Legacy Fixtures in Inventories & Manufacturing**:
   - `inventories` (732 failing test fixtures) and `manufacturing` (33 failing fixtures) contain unmaintained legacy test seeds that expect deprecated database structures.
   - Core accounting and HR systems operate independently, but running the global root `php artisan test` requires filtering out these plugins.

---

## 2. Document & Drive Asynchrony

- **One-Way Sync Queue Accumulation**:
  - `accounting_document_drive_syncs` relies on standard Laravel queue workers.
  - In environments running `sync` queue driver rather than Redis/database workers, synchronous HTTP requests to Google Drive during heavy invoice batch posting could cause UI request latency.
  - Recommendation: Ensure queue worker daemon (`php artisan queue:work`) is active in production.

---

## 3. Period Lock Enforcement Redundancy

- `PeriodLockService` was introduced to enforce hard accounting lock dates (closing prior fiscal years).
- Several older controllers still use inline `if ($move->date < $lockDate)` checks alongside `PeriodLockService::validateDate()`.
- Refactoring target: Centralize all lock checks exclusively through `PeriodLockService`.
