# Tax Requirements

Status: Implemented / Verified
Related: [[Tax Overview]], [[Invoice Tax]], [[Bill Tax]], [[Current Status]], [[Architecture Decisions]]

Tax Requirements formalizes statutory compliance, database structures, business validation invariants, and regulatory reporting requirements governing Aureus ERP.

---

## 1. Statutory Compliance Context (Pakistan Regulatory Environment)

Aureus ERP is configured to satisfy corporate compliance under:
- **FBR (Federal Board of Revenue)**: Federal General Sales Tax (GST) on goods (standard 18%), and Federal Withholding Tax (Income Tax Ordinance 2001).
- **Provincial Revenue Authorities**:
  - **PRA (Punjab Revenue Authority)**: Sales tax on services in Punjab (standard 16%).
  - **SRB (Sindh Revenue Board)**: Sales tax on services in Sindh (standard 13% / 15%).
  - **KPRA / BRA**: Khyber Pakhtunkhwa & Balochistan revenue boards.

---

## 2. Core Validation Invariants

The ERP enforces three statutory invariants in application code (`Webkul\Account\TaxManager` and `AccountManager`):

1. **Tax Registration Gate (`is_sales_tax_registered`)**:
   - A company cannot issue taxed sales invoices (`out_invoice`) unless `companies.is_sales_tax_registered` is explicitly set to `true`.
   - Violation throws: `sales-tax-not-registered`.
2. **Sales Tax Registration Number (`strn`) Mandatory**:
   - A registered company cannot post a taxed sales invoice if its `strn` field is `null` or empty.
   - Violation throws: `strn-required`.
3. **Vendor Bill Independence**:
   - Vendor bills with input sales tax cannot be blocked by the purchasing entity's registration status. Input tax reflects the vendor's legal billing.
4. **Debit / Credit Balanced Journal Invariant**:
   - Every invoice or bill must generate debit and credit move lines that balance to zero difference in base company currency.
5. **Two-Decimal Currency Rounding**:
   - Currency precision enforces rounding per line or globally per document depending on `TaxesSettings::get('tax_calculation_rounding_method')`.

---

## 3. Database Schema Foundations

Implemented via migrations:
- `2026_09_09_000001_add_tax_identity_to_companies_table.php`: Adds `is_sales_tax_registered` (boolean, default false), `strn` (string, nullable), and `tax_id` (NTN string) to `companies`.
- `2026_09_09_000001_add_tax_identity_to_partners_partners_table.php`: Adds identical tax identity attributes to all `partners_partners` records.
- `accounts_taxes`: Defines rate percentages, amount types, and GL debit/credit partition accounts.

---

## 4. Verification

- All invariants are actively tested and verified in `plugins/webkul/accounts/tests/Feature/TaxComplianceTest.php` (8 passing test cases).
