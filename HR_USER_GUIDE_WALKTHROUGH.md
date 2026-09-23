# HR User Guide — Real-World Walkthrough
### Scenario: Onboarding a new office employee and processing his first expense claim

This is a click-by-click guide, written from the perspective of Zainab (HR Manager). It follows one realistic scenario start to finish so you can see exactly what to do and what happens next at every step. Log in as your HR user to follow along.

**Scope note:** this ERP manages **Truck It In's own company employees** — office and operations staff (Operations, Fleet Coordination, Finance, HR, etc.) — **not the truck drivers**, who aren't set up or tracked as Employee records in this system. Every example below uses an office-based employee for that reason.

---

## Scenario

Truck It In has hired a new **Operations Coordinator**, **Ahmed Raza**, who will report to **Sarah Jenkins (Operations Manager)**. A few weeks in, Ahmed travels to Lahore for a client meeting and pays for his travel and meals out of pocket — he needs to be reimbursed. You (HR) onboard him, he submits the claim, it gets approved, and Finance posts it to the books.

---

## Part A — Onboard the new employee

**1. Go to Employees → Employees → click "New employee" (top right).**

**2. Fill in the basics:**
- Name: `Ahmed Raza`
- Job Position: pick `Operations Coordinator` (already configured under Configurations → Job Positions — if it isn't there yet, create it first)
- Department: `Operations`
- Work Location: pick the relevant office (e.g. `Head Office – Karachi`)
- Work Phone / Work Email: fill in what you have

**3. Set the reporting line — this is the important part:**
- **Manager**: select `Sarah Jenkins`. This single field controls who approves his requests going forward (line-manager-routed request types use whoever is set here).

**4. Employment details:**
- Employment Type: `Employee` (or `Contractor` if he's hired as one)
- Joining Date: today's date

**5. Click "Create".** Ahmed now exists as an employee record.

**6. Optional but recommended — give Ahmed a login:**
If he needs to log into the ERP himself (to submit his own claims), go to the employee record's **Settings** tab and link/create a related user account. If you'd rather HR submits requests on his behalf initially, skip this — you can do that too (see Part B, note at the end).

**What just happened technically:** you created a master `Employee` record. Nothing has touched Accounting yet — this is pure HR data. The "Manager" field you set is what makes step-by-step approval routing work automatically later.

---

## Part B — Ahmed submits his expense claim

*(If Ahmed has his own login, he does this himself. If not, HR can submit on his behalf from the same screen — just pick him as the employee.)*

**1. Go to Employee Requests → click "New request" (or "+" / Create).**

**2. Fill in the form:**
- Employee: `Ahmed Raza` (auto-filled if he's logged in himself)
- Request Type: select **`Travel & Entertainment Claim`** — this is the category that covers business travel, accommodation, and food expenses, exactly the one relevant here
- Amount: enter the total, e.g. `12,500`
- Currency: `PKR`
- Expense details / nature of expense: `Client visit — Karachi to Lahore, travel + meals, [date]`
- Attach receipt: upload a photo/scan of the receipts if the request type requires a document
- Bank details: if not already saved on his employee profile, confirm his account title/IBAN so the eventual payout goes to the right account

**3. Click "Submit".**

**What just happened:** the system looked at the request type (`Travel & Entertainment Claim`), found its configured approval chain (HR Review → Line Manager → Finance → VP Finance if the amount crosses the threshold), and created the first approval step automatically. The request status is now **"Pending Approval"** — Ahmed (or HR) can see it sitting in the queue but can no longer edit the amount.

---

## Part C — The approval chain

Each approver sees this the same way: they go to their own **Employee Requests** list (or the shared **Approval Queue**, reachable from the bell/notifications or the Support module's Approval Requests screen), find the pending request, open it, and click **Approve** or **Reject**.

**Step 1 — HR Review.** As HR, you'll see Ahmed's claim in your queue first. Open it, check the receipt and details look legitimate, click **Approve**. It automatically moves to the next step.

**Step 2 — Line Manager (Sarah Jenkins).** Sarah logs in, sees the claim waiting in her queue (because she's set as Ahmed's Manager), opens it, clicks **Approve**.

**Step 3 — Finance.** A Finance user (Accountant/Controller/VP Finance) sees it next. Before approving, they have an extra option: **"Review & Edit Tax"** — they can adjust tax deductions on the claim if needed (e.g. income tax withholding). Once satisfied, they click **Approve**.

*(If the claim amount is large enough to cross the configured VP Finance threshold, there's one more step — VP Finance signs off the same way.)*

**If anyone rejects instead:** the request stops immediately, its status becomes "Rejected", and nothing is created in Accounting. Ahmed/HR can see the rejection reason.

**What just happened:** every approval is logged with who approved, when, and any comments — this is your audit trail if anyone ever asks "who signed off on this expense?"

---

## Part D — It lands in Accounting automatically

Once the **final** approval happens, you don't need to do anything else in HR — the system automatically creates a **draft journal entry** in Accounting:
- Debit: the Expense account configured for "Travel & Entertainment Claim" (set up once under Employee Request Types)
- Credit: Accounts Payable

**To see this as a Finance user:**

**1. Go to Accounting → Journal Entries.**

**2. Find the new entry** — it'll reference Ahmed's claim (search by amount, date, or partner if it's tagged to him). It's sitting in **Draft** status.

**3. Review it, then click "Post"** (or "Confirm") to move it from draft into the actual General Ledger.

**What just happened:** the exact same amount Ahmed claimed is now a real accounting entry — no one had to manually type "12,500, Travel Expense, Accounts Payable" into Accounting. It flowed straight from the approved HR claim.

---

## Part E — Paying Ahmed back

**1. Go to Accounting → Payments** (or from the posted journal entry, there's usually a "Register Payment" action).

**2. Record the payment** — bank/cash, amount `12,500`, against the payable created in Part D.

**3. Once recorded, Ahmed's claim is fully closed** — from his side, it shows as paid; from Accounting's side, it's reconciled against the bank once that transaction shows up in the next bank statement import (see Accounting → Bank Statements / Transaction Mapping if you want to trace it that far).

---

## The whole scenario, one paragraph

*Onboard Ahmed with Sarah as his manager → Ahmed submits a Travel & Entertainment claim for PKR 12,500 → HR approves → Sarah (his manager) approves → Finance reviews tax and approves → a draft journal entry appears automatically in Accounting → Finance posts it to the ledger → Finance pays Ahmed → done. At no point did anyone re-type the claim amount into Accounting — it moved through the system as one connected record.*

---

## Quick reference — where things live

| You want to... | Go to |
|---|---|
| Add/edit an employee | Employees → Employees |
| Set who someone reports to | Employees → Employees → edit → "Manager" field |
| Submit or view a claim/request | Employees → Employee Requests |
| Configure what claim types exist and their accounting mapping | Employees → Employee Request Types |
| Approve something waiting on you | Employees → Employee Requests (your pending items), or the shared Approval Queue |
| See the resulting accounting entry | Accounting → Accounting → Journal Entries |
| Pay a claim/bill | Accounting → Vendors → Payments (or the "Register Payment" action on the entry) |
| Check attendance or request a time correction | Employees → Attendance |
| Run a performance review cycle | Employees → Performance Cycles → Launch |

---

## A note on permissions

Not everyone sees everything — this is by design, not a bug:
- Only your own reporting-tree employees show up in your Attendance/Requests views, unless you have the HR Manager role (which sees everything).
- Only users with a Finance-side role (Accountant, Controller, VP Finance, CFO) see the Finance approval step or can post journal entries — an HR Manager without that role won't see "Post" buttons in Accounting.
- If someone can't approve a request they should be able to, it's usually a missing role/permission — check Configurations → Roles (or ask whoever manages roles) rather than assuming the request is stuck.
