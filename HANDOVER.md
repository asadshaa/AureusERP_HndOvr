# AureusERP — Handover Summary

**Repository:** `github.com/asadshaa/AureusERP_HndOvr`, branch `main`
**Stack:** Laravel 13 · Filament 5 · Livewire 4 · MySQL 8.4 · PHP 8.3
**Client:** Truck It In (Pvt) Ltd

This document summarizes what was delivered in this engagement and gives a
concise map of how the system as a whole is built, for anyone picking up the
codebase for the first time.

---

## 1. Platform architecture, in brief

AureusERP is a **plugin monorepo**: every business module (`plugins/webkul/*`)
is an independent Laravel package registered through a shared
`plugin-manager` framework, each contributing its own Filament panel
resources, migrations, and service classes. Two patterns recur across nearly
every module and are worth understanding before anything else:

- **Company scoping.** Nearly every table carries a `company_id`, and access
  is enforced per-record — either through Eloquent query scopes or, for
  users, a `resource_permission` column (`GLOBAL` / `GROUP` / `INDIVIDUAL`,
  see §2.14) that governs whether a user sees every company's records, their
  team's, or only their own.
- **One shared approval engine.** HR, Accounting, and other modules do not
  each implement their own approval workflow. They all route through
  `support`'s `ApprovalWorkflow` / `ApprovalStep` / `ApprovalRequest` /
  `ApprovalEngine`, matched by a plain string (`request_type`). A new module
  needing approvals creates an `ApprovalRequest` against its own record and
  a `request_type` string — no code coupling to the approval engine itself.
  This is the mechanism Claims, Sensitive Changes, Recruitment, and Time Off
  all use.

---

## 2. Module reference

### Accounting & Finance

| Plugin | What it is |
|---|---|
| **`accounts`** | The core double-entry ledger. `Move` (table `accounts_account_moves`) is a single unified model for customer invoices, vendor bills, credit notes, and journal entries — one `move_type` column tells them apart. Posting is a `draft → posted → cancel` state machine; once posted, a move is effectively immutable. |
| **`accounting`** | A separate, later-stage plugin — **do not confuse with `accounts` above**. Adds document management (`Document`, `DocumentAudit`, versioned file storage), bank statement import/reconciliation, FS-tag cost tracking, and the peer-to-peer document exchange features described in §3. |
| **`invoices`** | A thin, customer-facing Filament presentation layer over the accounting data — not a third ledger. |
| **`sales`** / **`purchases`** | Quotation-to-order and requisition-to-bill pipelines. Neither posts to the ledger directly; confirming an order creates the corresponding `Move` in `accounts`. |
| **`analytics`** | A lightweight cost-tagging plugin (one model, `Record`) that other modules' ledger lines attach to for cross-cutting reporting — not a BI dashboard layer. |

### HR

| Plugin | What it is |
|---|---|
| **`employees`** | Core HR: employees, departments, skills, and `EmployeeRequest` (the generic HR-request model that Claims and Sensitive Changes both extend). `HrHierarchyService` centralizes "who can see/approve whose records" so that logic isn't duplicated per resource. |
| **`recruitments`** | Hiring pipeline — candidates, applications, job positions, stages, interviewer panels — approved through the shared engine. |
| **`time-off`** | Leave types, accrual plans, and leave requests, likewise approved through the shared engine. |
| **`timesheets`** | Time entries against employees/projects. Note: `projects` also defines its own `Timesheet` model for task-level logging — two plugins model the same concept from different angles. |

### Operations

| Plugin | What it is |
|---|---|
| **`inventories`** | Warehouses, locations, stock moves, lots, packages, on-hand quantities. |
| **`manufacturing`** | Bills of materials, work orders, work centers. |
| **`products`** | The shared product/variant/pricing catalog used by Sales, Purchases, Inventory, and Manufacturing. |
| **`contacts`** | A thin extension of `partners`' `Partner` model — same underlying customer/vendor data, not a separate store. |
| **`projects`** | Projects, tasks, kanban stages, milestones. |

### Platform infrastructure

| Plugin | What it is |
|---|---|
| **`security`** | Auth and permissions (Spatie-backed roles/permissions), plus the `resource_permission` scoping layer (GLOBAL / GROUP / INDIVIDUAL) enforced via a global Eloquent scope. |
| **`support`** | The shared approval engine (§1), used by every module above that needs sign-off workflows. |
| **`chatter`** | Shared activity-log / comment-thread trait used across business models. |
| **`fields`** | Custom-field support without schema changes. |
| Others | `full-calendar`, `table-views`, `barcode`, `blogs`, `website`, `maintenance`, `plugin-manager` — supporting UI components and the plugin-registration framework itself. |

---

## 3. What was delivered this engagement

### HR workflows (Recruitment, Claims, Sensitive Changes)

Built three end-to-end approval-backed workflows, all on top of the existing
`EmployeeRequestService` and shared `ApprovalWorkflow` engine — no parallel
approval system was introduced:

- **Recruitment**: hiring requirement → job description → publish → candidate →
  screening → interview → assessment → offer → hire → convert to employee,
  with duplicate-conversion protection and full audit-trail linkage.
- **Claims & Reimbursements**: category-routed multi-level approval (HR
  review → line manager or department head → Finance → VP Finance above a
  configurable threshold), with tax-consistency validation and bank details
  restricted to users who need them.
- **Sensitive Employee Data Changes**: identification, passport, bank
  account, and salary fields can no longer be edited directly — every change
  requires a routed approval and is fully audited (original/proposed value,
  requester, approver, reason, decision, timestamp).

Real defects found and fixed along the way: a rejected job application could
still be converted to an employee; an audit trail that silently logged
nothing; a database default that mis-classified newly created applications;
a PII leak on the employee record's national-ID fields; and two Approval
Queue permission gaps that left legitimate approvers unable to act on
requests routed to them.

### Peer-to-peer document transfer (WebRTC)

Finished and hardened a prototype for direct, encrypted browser-to-browser
invoice/document transfer — no file ever touches the server between sender
and recipient. This sits alongside, not instead of, the existing
instance-to-instance peer exchange and email/claim-link channels; a user
now picks whichever fits ("a paired AureusERP instance," "someone without
AureusERP," or "someone online right now").

The prototype had real security gaps before this work: any logged-in user
of any company could read another company's file through the byte-serving
endpoint; a session code carried too little entropy and leaked full invoice
data with no login; there was no integrity check at all, so a truncated
transfer would silently produce a corrupted file while reporting success;
and no denial was ever written to the audit trail. All of these were fixed,
each verified with a live end-to-end transfer — including an independent,
tool-level SHA-256 check outside the application itself — and confirmed
working across two different networks, not just one LAN.

### Platform fixes

Closed a set of HR authorization gaps found during a module audit — attendance,
performance review, and timesheet views that leaked personal data
company-wide instead of respecting the reporting hierarchy — and merged this
engagement's ~50 commits of work into `main` alongside a second, independently
developed set of accounting import/reconciliation fixes that had landed there
in parallel, reconciling eight real merge conflicts by hand rather than by tool
default.

---

## 4. Known gaps and follow-ups

Flagged honestly rather than left silent:

- **`AccountingPermissions::TransferDocuments`** is referenced by the
  intra-instance document-transfer feature but was never defined — that
  feature currently cannot function. Deciding which roles should hold it is
  a business decision, not a code fix, and is intentionally left open.
- **No TURN server** is configured for the WebRTC feature. STUN alone
  (what's configured today) works for most home/office networks but can
  fail behind stricter corporate NAT. Not blocking for launch; worth
  planning for if user reports of failed transfers appear.
- **macOS/Safari was not manually tested** in this engagement — WebRTC was
  verified across two networks on Windows/Chromium browsers only.
- **Real SMTP is not configured.** Email-based claim links currently write
  to a log file rather than sending anywhere; production needs a real
  provider (SES, Postmark, etc.) before that channel is usable.
- **The `external_auditor` role work** (visible as uncommitted changes in
  the working tree at handover) is a separate, unreviewed stream from a
  different session — intentionally excluded from every commit and push in
  this engagement.
- The merge into `main` was verified through careful manual review of each
  conflict plus a partial automated test run, not a full clean-database
  test suite pass — recommended as a first step before this branch is relied
  on in production.

---

## 5. Deployment

A working Docker Compose production stack already exists in this repo
(`docker-compose.yml`, `docker/production/`) — PHP-FPM app container, nginx,
a dedicated queue-worker container, a scheduler container, and MySQL. It is
documented, not theoretical: see `docker/production/README.md` for the
setup, backup, and safe-upgrade procedures already written for it.

Recommended path: a single VPS (DigitalOcean, Hetzner, or AWS EC2 —
functionally interchangeable for this stack) running that Compose file
as-is, with TLS via Caddy or a load balancer on a real domain. The same
stack runs unmodified on any of these; there is no code reason to prefer
one provider over another.

Two things need attention specifically for the WebRTC feature to work in
production: a real, publicly trusted TLS certificate (any of the above
options provides this for free via Let's Encrypt), and — separately — the
scheduler must be given the two existing cleanup commands
(`accounting:webrtc:expire`, `accounting:transfers:expire`), since
`bootstrap/app.php` currently registers no scheduled tasks at all despite
the scheduler container already running.
