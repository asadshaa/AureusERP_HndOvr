# Risks

Status: Documented
Related: [[Bugs]], [[Technical Debt]], [[Architecture Decisions]], [[Accounting Overview]]

This document outlines architectural, operational, and financial risks associated with the ongoing maintenance and deployment of Aureus ERP.

---

## 1. Accounting Integrity Risks

1. **Direct Database Manipulation**:
   - Risk: Manual modifications to `accounts_account_moves` or `accounts_account_move_lines` in production SQL can break the fundamental debit/credit balance invariant or bypass `parent_state` synchronization.
   - Mitigation: Strict database access controls; all mutations must pass through `AccountManager` or `AccountingReconciliationService`.
2. **Double-Posting via Reconciliation**:
   - Risk: Manually clearing payment accounts (`101403 Outstanding Receipts`) during bank transaction mapping without matching against pre-existing payments could artificially inflate cash balances.
   - Mitigation: Enforced double-posting guard implemented in `BankMappingService`.

---

## 2. Multi-Company Leakage Risks

1. **New Resource Form Scoping**:
   - Risk: When developers add new Filament resources, missing `company_id` filters in relationship pickers can allow users in Company A to view or assign entities from Company B.
   - Mitigation: Automated architecture rules and standard `HasPermissionScope` trait usage.
2. **Global Super-Admin Bypass**:
   - Risk: Users with unrestricted Spatie Shield permissions could unintentionally post moves across different company bank accounts.
   - Mitigation: System architecture enforces company isolation even for administrative accounts.

---

## 3. Operational & Cloud Infrastructure Risks

1. **Google Drive API Quotas & Authentication**:
   - Risk: Service account key rotation or daily Drive API quotas (1 billion requests/day, but tight per-minute burst limits) could stall background document backups.
   - Mitigation: Document storage architecture is decoupled; ERP continues operating normally even when Drive sync fails, queuing jobs with exponential backoff.
2. **Mobile Geofence Spoofing**:
   - Risk: Tech-savvy field workers spoofing GPS latitude/longitude to check in remotely.
   - Mitigation: Planned future integration of IP geocoding and cellular tower triangulation alongside GPS coordinates.
