# Purchase Order Tax

Status: Implemented / Verified
Related: [[Tax Overview]], [[Bill Tax]], [[Tax Requirements]], [[Procurement Overview]], [[Purchase Orders]]

Purchase Order Tax governs tax computation on purchase requisitions and purchase orders prior to vendor bill generation.

---

## 1. Purchase Order Line Taxes

In `plugins/webkul/purchases`:
- Each `OrderLine` links to applicable taxes via pivot table `purchases_order_line_taxes`.
- Taxes are scoped to `type_tax_use = 'purchase'`.
- Supports tax estimation for commercial purchase order commitments.

---

## 2. Line Calculation & Total Commitment

- In `plugins/webkul/purchases/src/Models/OrderLine.php`:
  - When an item is added, unit cost and vendor quotes determine `price_subtotal`.
  - Linked purchase taxes compute the expected vendor invoice tax (`price_tax`).
  - Total PO commitment (`amount_total`) represents:
    $$\text{Total Commitment} = \text{Subtotal} + \text{Taxes}$$
- This ensures budget authorization thresholds in [[Approvals]] accurately reflect the total gross cash outflow required.

---

## 3. Propagation to Vendor Bills

When an order transitions to `purchase` and the **Create Bill** action is triggered:
- `ComputePurchaseOrderFromMoveListener` creates an `AccountMove` (`in_invoice`).
- Every `OrderLine` maps to an `AccountMoveLine` copying over:
  - Exact `product_id`, description, quantity, and unit price.
  - Linked purchase tax definitions from `purchases_order_line_taxes` to `accounts_account_move_line_taxes`.
- Guarantees full consistency between contracted purchase amounts and accounts payable records.

---

## 4. Verification

- Verified via `plugins/webkul/purchases/tests/Feature/OrderResourceTest.php` and `ComputePurchaseOrderTest.php`.
