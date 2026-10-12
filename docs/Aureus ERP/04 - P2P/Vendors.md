# Vendors

Status: Implemented / Verified
Related: [[Procurement Overview]], [[Purchase Orders]], [[Partner Analytics]], [[Chart of Accounts]], [[Tax Requirements]]

Vendors represent third-party suppliers, contractors, service providers, and payees in Aureus ERP. They are modeled via the unified partner architecture (`Partner` in `plugins/webkul/partners/src/Models/Partner.php`) flagged with supplier capabilities.

---

## 1. Unified Partner Concept

Aureus ERP uses a single `Partner` entity for customers, vendors, employees, and counterparties to maintain a single identity for tax, banking, and ledger reporting:
- `is_company`: Boolean indicating whether the partner is an organization or an individual.
- `supplier_rank`: Integer counter; any partner with `supplier_rank > 0` is classified as a Vendor.
- `customer_rank`: Integer counter; partners with `customer_rank > 0` are classified as Customers.
- If a counterparty is both a supplier and customer (e.g. barter or cross-services), single ledger tracking enables cross-reconciliation.

---

## 2. Tax & Compliance Identification

In accordance with Pakistan corporate and tax regulations (FBR / PRA / SRB):
- **NTN (National Tax Number)**: 7-8 digit tax registration number for companies/individuals (`partners_partners.tax_id` / `tax_registration_number`).
- **STRN (Sales Tax Registration Number)**: 13-digit sales tax registration number.
- **Withholding Category**: Classifies the vendor as Active Taxpayer (ATL) or Non-ATL, dictating withholding tax (WHT) rates on services (e.g. 10% vs 20%) and goods (e.g. 5% vs 10%).
- **Migration `2026_09_09_000001_add_tax_identity_to_partners_partners_table.php`**: Standardized formal tax identity attributes across all partner records.

---

## 3. Financial & Accounting Defaults

Every vendor record can define default accounting properties:
1. **Property Account Payable**:
   - Default GL account for vendor balances (typically `2111000 - Accounts Payable`).
   - Customizable per vendor if specialized subsidiary control accounts are used.
2. **Payment Terms**:
   - Standard credit terms (e.g. Immediate, Net 15, Net 30, Net 60).
   - Determines the default `invoice_date_due` on generated vendor bills.
3. **Default Currency**:
   - Billing currency (e.g. PKR, USD, AED). If foreign currency is assigned, exchange gain/loss calculations occur at payment settlement.
4. **Bank Account Details**:
   - IBAN, bank name, branch code, and account title for EFT and electronic transfers.

---

## 4. Partner Analytics & Aged Payables

Vendors integrate directly into [[Partner Analytics]] via `PartnerAnalyticsService`:
- **Aged Payables Report (Aged AP)**: Categorizes outstanding vendor balances into aging buckets:
  - 0-30 Days
  - 31-60 Days
  - 61-90 Days
  - 90+ Days
- **Days Payable Outstanding (DPO)**: Average duration from vendor invoice posting to bank payment execution:
  $$\text{DPO} = \frac{\text{Average Accounts Payable}}{\text{Cost of Goods Sold / Operating Expenses}} \times 365$$
- **Vendor Balance Invariant**: Total open balances across all vendor subledgers must exactly equal the balance on `2111000 - Accounts Payable` in the Trial Balance.

---

## 5. Security & Scoping

- **Company Scoping**: Partners can be shared across companies or scoped to a specific company via `company_id`.
- **RBAC**: Protected by `PartnerPolicy`. Access to vendor financial tabs (bank accounts, credit ratings, AP balance) is restricted to users with `Accounting` or `Purchases` manager roles.
