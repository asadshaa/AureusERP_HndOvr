# Bill Tax

Status: Implemented / Verified
Related: [[Tax Overview]], [[Invoice Tax]], [[Tax Requirements]], [[Procurement Overview]], [[Purchase Orders]]

Bill Tax manages input taxes and withholding taxes applied to vendor bills (`MoveType::IN_INVOICE`) and vendor refunds (`MoveType::IN_REFUND`).

---

## 1. Input Tax vs Withholding Tax

Vendor bills encompass two distinct tax behaviors under Pakistani and corporate accounting standards:
1. **Input Sales Tax (Recoverable GST)**:
   - Tax charged by the vendor that can be claimed as input credit against output sales tax liabilities.
   - Debits `2141000 - Sales Tax Recoverable (Input Tax)`.
2. **Withholding Tax (WHT Deducted at Source)**:
   - Tax deducted by the company from vendor payments and remitted directly to tax authorities (FBR / PRA).
   - Credits `2143000 - Withholding Tax Payable`.
   - Reduces the net cash amount payable to the vendor (`2111000 - Accounts Payable`).

---

## 2. Ledger Impact (Example: Bill of 50,000 PKR + 16% Input Tax - 10% WHT)

| Account | Account Name | Debit (PKR) | Credit (PKR) |
|---|---|---|---|
| `5111000` | Operating Expense / Cost of Sales | 50,000 | - |
| `2141000` | Sales Tax Recoverable (Input Tax) | 8,000 | - |
| `2143000` | Withholding Tax Payable (FBR) | - | 5,000 |
| `2111000` | Accounts Payable (Net to Vendor) | - | 53,000 |

Total debits (58,000) equal total credits (58,000).

---

## 3. Purchasing Company Exemption Guard

Unlike customer invoices, **vendor bills are never blocked by the purchasing company's own sales tax registration status**:
- Input tax charged on a vendor bill is the vendor's legal obligation and registration (`TaxComplianceTest.php:155-177`).
- Even if the company operating the ERP has `is_sales_tax_registered = false`, it is legally obligated to record vendor bills exactly as billed by suppliers.
- `TaxManager` and `AccountManager` explicitly bypass the company registration check for `TypeTaxUse::PURCHASE` documents.

---

## 4. Verification

- **Status**: Implemented & Verified.
- **Test File**: `plugins/webkul/accounts/tests/Feature/TaxComplianceTest.php` (`it('does not block a taxed vendor bill regardless of the company sales-tax registration')`).
