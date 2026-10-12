# Manual Testing

Status: Partially Implemented / Documented
Related: [[Testing Overview]], [[Accounting Tests]], [[Regression Tests]], [[Bugs]], [[Current Status]]

Manual testing procedures provide end-to-end verification through the actual browser UI, exercising Filament forms, live Livewire reactivity, modals, and report displays.

---

## 1. Manual Walkthrough Protocols

Documented in `REMAINING_MANUAL_TESTS.md` and `MANUAL_WALKTHROUGH_STATUS.md`:

### Test A1: Multi-Currency Invoice UI Auto-Fill
- **Navigation**: **Accounting → Customers → Invoices → New**
- **Inputs**:
  - Customer: `QA-Gulf Logistics FZE`
  - Currency: `USD`
  - Product: `Freight Transport - Karachi to Lahore (40ft Container)`, Qty: 1
- **Expected Verification**:
  - Unit price automatically converts using active exchange rate to ~$305.21 USD (not base 85,000 PKR).
  - Calculated Tax ≈ $54.94, Total ≈ $360.15.
  - Upon posting, ledger lines debit/credit ~84,780 PKR base currency.

### Test A2: Bank Reconciliation Double-Posting Guard
- **Navigation**: **Accounting → Accounting → Transaction Mapping**
- **Inputs**:
  - Unmapped line; manually set Offset GL: `101403 Outstanding Receipts`.
  - Cash flow category: `Operating - Receipts`.
- **Expected Verification**:
  - Post action **must fail with an error**: *"This posting would drive a payment-clearing account past its expected balance... Check whether the underlying invoice/bill payment has already been reconciled."*
  - Verifies that phantom cash creation is blocked at the UI boundary.

### Test B1 & B2: Tax Picker Edge Cases
- **B1 (Cross-Type Tax Filtering)**: Customer invoice line tax picker must not allow selection of purchase-type taxes (e.g. 18% Input Tax).
- **B2 (Inactive Tax Suppression)**: Deactivating a tax in **Accounting → Configurations → Taxes** removes it from invoice line dropdowns.

---

## 2. Blocked / Deferred UI Scenarios

- **Google Drive Real Ingestion (Blocked - ENV-001)**:
  - Requires live Google Cloud credentials (`GOOGLE_DRIVE_*`).
  - Automated tests run against `FakeDriveClient`, but end-to-end cloud browser upload requires production API setup.
- **Physical Mobile Geofencing Check-In**:
  - Requires physical mobile device GPS sensor emitting latitude/longitude.
  - Tested in dev environment via simulated coordinates.
