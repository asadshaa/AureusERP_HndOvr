# AureusERP — HR & Accounting Demo Walkthrough
### Truck It In (Pvt) Ltd — presenter script

This is a screen-by-screen guide to every menu item in the **Employees (HR)** and **Accounting** modules: what it's for, what a Truck It In user actually does there, and — the key thread running through this doc — **how HR and Accounting are one connected system**, not two separate apps bolted together. Use it as your talking-points script when demoing.

The one sentence to repeat throughout the demo: *"HR and Accounting share the same approval engine — an employee's expense claim and a finance team's invoice go through the identical approve/reject workflow, and an approved HR claim lands directly in the ledger without anyone re-typing it."*

---

## PART 1 — HR (Employees module)

### Top nav: Employees | Departments | Reportings | Configurations | Attendance | Performance Cycles | Performance Reviews
### Second row: Employee Requests | Employee Request Types | HR Analytics

---

### 1. Employees
**What it is:** The master record for every person at Truck It In — drivers, dispatchers, warehouse staff, office employees.

**What you do here:** Create/edit an employee — personal profile, department, line manager, work location, job title, and (behind a permission gate) sensitive private info: salary, bank account, SSN/passport, visa details.

**Demo line:** "This is our single source of truth for every employee. Notice the org chart is built right in — who reports to whom — because that reporting line is what automatically routes approvals later."

**Worth showing:** Try editing a salary or bank detail — it doesn't save instantly. It goes through an approval workflow (the "Sensitive Data Custodian" role has to sign off), so payroll-critical data can't be silently changed.

---

### 2. Departments
**What it is:** Org structure — Operations, Fleet/Maintenance, Dispatch, Finance, HR, etc.

**What you do here:** Define departments and assign a department head. Also manage which employees sit in each department.

**Demo line:** "The department head you assign here becomes an automatic approver for certain requests — for example, tech/equipment claims route to the department head instead of the line manager."

---

### 3. Reportings (cluster) → Employee Skills
**What it is:** A read-only skills matrix.

**What you do here:** View which employees have which skills, at what level — e.g., which drivers are certified for hazardous cargo or specific vehicle classes.

**Demo line:** "Useful for dispatch decisions — quickly filter who's qualified for a specialized job."

---

### 4. Configurations (cluster)
**What it is:** The backend setup screen — the picklists everything else draws from. Not day-to-day work, more a "set it up once" screen.

**Sub-items:**
- **Activity Plans** — onboarding/offboarding task templates
- **Departure Reasons** — resignation, termination, end-of-contract, etc.
- **Employee Categories** — tags/badges on employee records
- **Employment Types** — full-time/part-time/contractor labels
- **Job Positions** — "Truck Driver", "Fleet Supervisor", "Dispatcher"
- **Skill Types & Levels** — the taxonomy behind the Skills matrix
- **Work Locations** — "Depot – Lahore", "Warehouse – Karachi"

**Demo line:** "This is the plumbing — set it up once when onboarding the company, then HR staff never touch it again."

---

### 5. Attendance
**What it is:** Daily attendance records — check-in/check-out, worked hours, overtime, lateness — per employee.

**What you do here:** View attendance (scoped to who you manage), and use **"Request Time Change"** if a check-in/out was recorded wrong.

**Demo line:** "If a driver's clock-in looks wrong — maybe a biometric device glitched — they don't just edit it. They submit a correction request, which goes to their line manager for approval, and only then does the record actually change. Full audit trail, no silent edits."

**Connection point:** This uses the exact same generic request system as Employee Requests (see below) — just a non-financial request type.

---

### 6. Performance Cycles
**What it is:** Review periods — e.g. "H1 2026 Review."

**What you do here:** Create a cycle, then click **Launch** — this auto-generates a review task for every active employee. No manually creating dozens of individual reviews.

**Demo line:** "One click and every employee gets a review task created for this cycle."

---

### 7. Performance Reviews
**What it is:** The two-step appraisal itself — self-review, then manager review.

**What you do here:** Employee submits a self-rating and comments; their manager then completes the manager review — rating, competency scores, an improvement plan, and optionally a promotion recommendation.

**Demo line:** "This gives us a documented appraisal history — useful for raise/promotion decisions and for audit purposes."

---

### 8. Employee Requests ⭐ (the centerpiece — this is where HR meets Accounting)
**What it is:** A universal request inbox. Every kind of "employee needs something approved" flows through here — expense claims, attendance corrections, and more.

**What you do here, end to end:**
1. An employee picks a **request type** (e.g. "Travel & Entertainment Claim") and fills in the amount, expense details, and — for money requests — their bank details for payout.
2. They click **Submit**. The system automatically finds the right approval chain for that request type and routes it to the first approver.
3. Approvers act via **Approve/Reject** — this might be HR, then the line manager (or department head, depending on the type), then Finance.
4. Finance reviewers get an extra **"Review & Edit Tax"** step to adjust tax deductions before final sign-off.
5. **Once fully approved, if it's a financial claim, a draft accounting entry is automatically created in the Accounting module** — a proper debit/credit journal entry (expense account debited, accounts payable credited) — ready for Finance to review and post. Nobody re-types the claim into Accounting.
6. If it's an attendance correction instead, the approved request automatically updates the attendance record.

**Demo line (the money line):** *"Watch this — I'll submit a fuel claim as a driver... [submit] ...it climbs the approval chain... [approve as manager, approve as finance] ...and now — without anyone touching the Accounting module — there's a draft journal entry sitting in Accounting, ready to be posted and paid. That's the same underlying invoice/bill engine Accounting uses for a supplier bill — HR isn't creating a parallel accounting system, it's plugging directly into the real one."*

**Real claim categories already set up** (good to have some seeded examples ready before the demo): People (payroll/insurance/team events), Real Estate (rent/utilities), Digital Marketing, Financial Provisions (bank charges), Tech (equipment/laptop repairs — routes to department head), Professional Services (legal/audit), **Travel & Entertainment (travel/fuel/food — the one most relevant to drivers)**, **Returns & Waivers (detention/demurrage charges — directly relevant to logistics)**, and a catch-all Others.

---

### 9. Employee Request Types
**What it is:** The admin configuration behind Employee Requests — defines what kinds of requests exist and how each behaves.

**What you do here:** For each request type, configure: is it a money claim or not, what approval chain it uses, does it need a receipt, and — critically — **which Accounting journal and which GL accounts (debit/credit) it posts to** once approved.

**Demo line:** "This is literally where HR and Finance agree on the wiring — 'when a Travel claim is approved, debit this expense account, credit Accounts Payable, post through this journal.' It's configured once, jointly, by HR and Finance."

---

### 10. HR Analytics
**What it is:** An executive dashboard — headcount, attendance trends, performance and claim volume metrics, filterable by date range.

**Demo line:** "This is the 'so what' screen — pull it up last, as the payoff: here's the whole HR picture from one dashboard."

---

## PART 2 — Accounting

### Top nav: Customers | Vendors | Accounting | Reporting | Configurations *(+ a gear-icon Settings cluster)*

**The overall flow to narrate as you move through these clusters:**
> Invoice/Bill (Customers/Vendors) → Journal Entry posted to the GL (Accounting) → Bank Statement imported & matched → Reviewed/reconciled (Transaction Mapping) → Everything rolls up into Reports (P&L, Balance Sheet, GL, Aging).

---

### CUSTOMERS cluster (the "money coming in" side)

- **Customers** — master list of shippers/consignees: GST info, payment terms, credit limits. *Feeds Invoices.*
- **Invoices** — raise a freight invoice to a customer, apply tax, send it, track paid/overdue. *This is where AR starts — posting creates the GL journal entry.*
- **Payments** — record a cash/bank receipt against a customer invoice, reconcile it.
- **Credit Notes** — issue a credit against an over-billed invoice (rate dispute, damaged cargo claim).
- **Products** — the billable service items: "Freight Charges", "Detention Charges", "Fuel Surcharge" — each mapped to a revenue account and tax rate.

**Demo line:** "This is the whole customer-billing lifecycle — quote to invoice to payment — in one place."

---

### VENDORS cluster (the "money going out" side)

- **Vendors** — master list of fuel suppliers, subcontracted fleet owners, maintenance/toll vendors.
- **Bills** — enter a fuel-supplier or repair-shop bill, approve it for payment. *This is where AP starts.*
- **Payments** — pay a vendor via bank transfer, track outstanding payables.
- **Refunds** — vendor credit for returned parts or overbilled maintenance.
- **Products** — purchasable items: "Diesel", "Tyre Replacement", "Toll Charges" — mapped to expense accounts.

**Demo line:** "Mirror image of Customers, but for what we owe — and notice: this is exactly the same engine an approved HR expense claim posts into."

---

### ACCOUNTING cluster (the ledger engine + bank operations)

- **Journal Entries** — every posted Invoice, Bill, and Payment lands here as a double-entry record. Finance can also post a manual entry directly.
- **Journal Items** — line-level drill-down: "show me every line that hit the Fuel Expense account this month."
- **Manual Non-Bank Adjustments** — for things that aren't a bill or a bank transaction: depreciation, accruals, write-offs. Carries a reviewer field for maker-checker control.
- **Bank Statements** — import the company's bank e-statement (CSV) to bring in raw transaction lines — diesel debits, freight receipts, bank charges.
- **Transaction Mapping** — each imported bank line gets matched to a GL account (auto-suggested via configurable rules, or manual), reviewed, and posted. This is also where foreign-currency lines get their exchange rate applied and where the FX approval gate kicks in.
- **Documents** — central repository for e-invoices, proof-of-delivery, LR/bilty copies, bank statement PDFs — with full version history and audit trail, attachable to any invoice/bill/transaction.
- **Inbound Invoices** — bills received electronically from a trusted transport partner via the peer-to-peer network, queued for review before becoming a posted Bill.

**Demo line for Transaction Mapping specifically:** "This is the reconciliation step — the bank says money left the account, and this screen is where we confirm *why*: which invoice it paid, which expense account it hit."

---

### CONFIGURATIONS cluster (setup, once per company)

- **Chart of Accounts** — the GL account tree: Assets, Liabilities, Income, Expense (e.g. "Freight Revenue", "Fuel Expense", "Bank – Current A/c").
- **Journals** — the books entries post into (Sales Journal, Purchase Journal, a Bank Journal per bank account).
- **FS Tags** — tags on GL accounts that drive which report line an account rolls up into (e.g. tag an account "Operating Expenses") — this is what makes the Balance Sheet/P&L reports work without hardcoding account numbers.
- **Taxes / Tax Groups** — GST rates.
- **Payment Terms** — "Net 30", "Due on Receipt."
- **Fiscal Positions** — tax-mapping rules for interstate vs. intrastate scenarios.
- **Currencies / Exchange Rates** — for cross-border freight billed in USD; rates are entered and go through an approval step before use (same maker-checker pattern used elsewhere).
- **Bank Mapping Rules** — "if bank narration contains 'PSO' or 'Shell', auto-suggest Fuel Expense" — the automation behind Transaction Mapping's suggestions.
- **Import Profiles / Import Runs / Import History** — bulk-load a Chart of Accounts (e.g. migrating from a legacy system) as a batch, with a full success/failure history per run — this is the screen you already saw in the screenshot.
- **Peer Instances** — registers other companies/branches this company can directly exchange invoices with electronically.
- **Drive Ingestion Review** ⭐ — the Google Drive integration we just finished building. A file dropped into a shared Drive folder is automatically scanned, matched to a vendor/customer and GL account, and queued here for one-click confirmation — then it's posted as a real Bill/Invoice through the normal posting path. **Also gated by the same shared approval engine HR uses.**

**Demo line for Drive Ingestion Review:** "Drop a scanned fuel bill into the shared Drive folder — it shows up here pre-filled: vendor identified, GL account suggested, amount extracted. The accountant just confirms it instead of typing it in from scratch."

---

### REPORTING cluster (the payoff — everything rolls up here)

- **Balance Sheet, Profit & Loss, General Ledger, Financial Reports** — the standard statutory reports, built from FS-Tag-mapped GL data.
- **Trial Balance, Partner Ledger, Aged Receivable, Aged Payable** — Trial Balance for a quick debit=credit sanity check; Aged Receivable/Payable to chase overdue customer invoices or plan vendor payments (30/60/90-day buckets).
- **Customer & Vendor Analytics** — top customers by revenue, top vendors by spend — useful for negotiating fuel-supplier contracts.
- **Accounting Checks** — a pre-close checklist that flags gaps before month-end close.
- **Cash Flow Statement** — direct-method cash flow — critical for watching fuel-cost cash burn against freight collections.
- **Report Templates** — the underlying editor behind the reports above; finance can adjust which accounts roll into which report line, or build a custom internal report.

**Demo line:** "Everything we just entered — the invoice, the bill, the bank reconciliation, even the HR expense claim — flows automatically into these reports. Nobody re-enters anything to produce the P&L."

---

### SETTINGS (gear icon)
Global defaults: default accounts for new products, default invoice numbering/terms, default tax setup. One-time setup, not day-to-day.

---

## PART 3 — The HR ↔ Accounting Connection (say this explicitly in the demo)

This is the one governance idea worth stating out loud, because it's easy to miss just clicking through screens:

1. **One shared approval engine.** The exact same approval workflow system that routes an employee's attendance correction or sensitive-data change is what routes a Google Drive-ingested invoice, or an HR expense claim, before it hits the ledger. It's not two systems that happen to look similar — it's literally the same engine, used by both modules.

2. **HR claims become real accounting entries, automatically.** When an Employee Request Type is marked "financial," it's configured with a real GL journal, debit account, and credit account (from the Accounting module's own Chart of Accounts). Once a claim clears its approval chain, a proper draft journal entry appears in Accounting — same shape, same tables, same posting path as a vendor bill. No double-entry, no re-typing.

3. **Finance staff act on HR requests through their existing role.** A Finance/Controller/VP Finance user doesn't need a special HR permission — their normal Accounting-side role is recognized inside the HR approval screen, so they review and approve claims without switching mental models.

**Suggested closing demo sequence:** submit a driver's fuel claim (HR) → approve it up the chain, ending with Finance → jump to Accounting → show the draft journal entry sitting there, ready to post → post it → show it landed in the General Ledger and will flow into next month's P&L. That one sequence proves the whole "connected system, not parallel systems" story in under two minutes.
