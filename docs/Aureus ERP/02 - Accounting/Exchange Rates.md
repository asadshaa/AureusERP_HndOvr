# Exchange Rates & Multi-Currency Accounting

**Status:** Implemented & Verified  
**Parent Note:** [[Accounting Overview]]  

---

## 1. Multi-Currency Domain

Aureus ERP supports multi-currency transaction entry, foreign exchange (FX) gain/loss recognition, and currency translation across financial statements.

### Models:
- `Currency` (`support_currencies`): ISO currency master (`PKR`, `USD`, `AED`, `EUR`, `GBP`).
- `ExchangeRate` (`accounting_exchange_rates`): Time-stamped exchange rate entries between a foreign currency and the company base currency.
- `Company`: Maintains `currency_id` (company operating currency) and enabled currencies via `accounting_company_currencies`.

---

## 2. Key Services

### 1. `ExchangeRateService`
- Locates the active exchange rate for a given currency pair on a specific transaction date.
- Applies fallback logic if configured (`allow_previous_rate_fallback = true`), retrieving the most recent prior rate.

### 2. `ExchangeRateApprovalService`
- Intercepts manual exchange rate submissions.
- Ensures rate modifications pass authorization and do not retroactively alter finalized ledger transactions without an explicit audit trail.

### 3. `FxRevaluationService`
- Performs month-end unrealized foreign currency revaluations on open monetary balance sheet accounts (foreign bank accounts, open foreign AR, open foreign AP).
- Generates balanced adjustment journal entries posting to:
  - FX Gain Account (`fx_gain_account_id` on Company)
  - FX Loss Account (`fx_loss_account_id` on Company)

---

## 3. Source References

```text
plugins/webkul/accounting/src/Services/Currency/ExchangeRateService.php
plugins/webkul/accounting/src/Services/Currency/ExchangeRateApprovalService.php
plugins/webkul/accounting/src/Services/Currency/FxRevaluationService.php
tests/Feature/MultiCurrencyAccountingTest.php
```
