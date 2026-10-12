# Invoice Tax

Status: Implemented / Verified
Related: [[Tax Overview]], [[Bill Tax]], [[Tax Requirements]], [[Journal Entries]], [[Chart of Accounts]]

Invoice Tax governs how output sales taxes (GST / SST) are applied to customer sales invoices (`MoveType::OUT_INVOICE`) and credit notes (`MoveType::OUT_REFUND`), and how corresponding general ledger entries are generated.

---

## 1. Accounting Impact & Ledger Entries

When an invoice with tax is computed and posted:
- **Base Line**: Debits Accounts Receivable (`1121000 - Accounts Receivable`) for the full customer amount (Base + Tax).
- **Revenue Line**: Credits Revenue account (e.g. `4111000 - Freight Revenue`) for the pre-tax base amount.
- **Tax Line**: Credits Output Sales Tax Payable (e.g. `2142000 - Sales Tax Payable`) for the computed tax amount.

### Example Journal Entry (Invoice: 100,000 PKR + 18% GST)
| Account | Account Name | Debit (PKR) | Credit (PKR) |
|---|---|---|---|
| `1121000` | Accounts Receivable | 118,000 | - |
| `4111000` | Freight Revenue | - | 100,000 |
| `2142000` | Sales Tax Payable | - | 18,000 |

---

## 2. Tax Computation Modes

Configured on `accounts_taxes`:
1. **Tax Excluded from Price** (Default):
   $$\text{Tax Amount} = \text{Base Amount} \times \frac{\text{Rate}}{100}$$
   $$\text{Total Invoice Amount} = \text{Base Amount} + \text{Tax Amount}$$
2. **Tax Included in Price**:
   $$\text{Base Amount} = \frac{\text{Price Unit}}{1 + (\text{Rate} / 100)}$$
   $$\text{Tax Amount} = \text{Price Unit} - \text{Base Amount}$$
   - Evaluated using `TaxManager::fixTaxIncludedPrice()` to ensure precision to two decimal places.

---

## 3. Server-Side Posting Validation

Before transitioning an invoice from `draft` to `posted` via `AccountHelper::post()`:
1. `TaxManager` validates that all tax lines balance to the penny against line item calculations.
2. If any line carries a sales tax (`TypeTaxUse::SALE`), the engine verifies:
   - `company->is_sales_tax_registered == true`
   - `!empty($company->strn)`
3. If customer is tax-exempt or holds an exemption certificate, a fiscal position (`FiscalPositionTax`) maps the tax rate to 0% with exemption annotation.

---

## 4. Verification

- Verified in `plugins/webkul/accounts/tests/Feature/TaxComplianceTest.php`.
- Test cases verify rejection when company is unregistered or missing STRN, and successful posting with correct tax debit/credit splits once valid.
