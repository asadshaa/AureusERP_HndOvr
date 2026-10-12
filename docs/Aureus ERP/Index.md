# Aureus ERP Knowledge Base

Welcome to the comprehensive technical and operational documentation vault for **Aureus ERP** (Truck It In Pvt Ltd).

This Obsidian knowledge base provides an authoritative, code-verified reference across all architectural systems, business modules, statutory tax requirements, accounting invariants, and test registries.

---

## 🗂️ Knowledge Base Directory

### [[Project Overview|00 - Project Context]]
Foundational orientation, project origins, current implementation status, and technology stack.
- [[Project Overview]] — Purpose, business scope, and modular layout.
- [[AI Context]] — Fast 5-minute context for developers and AI agents.
- [[Tech Stack]] — PHP 8.3, Laravel 13, Filament 5, Livewire, MySQL 8.4.
- [[Current Status]] — Verification matrix, defect status, and active operations.

### [[System Architecture|01 - Architecture]]
System blueprint, framework topology, UI administration, and security boundaries.
- [[System Architecture]] — Modular plugin design and ledger invariants.
- [[Laravel Architecture]] — Provider registration, composer merge-plugin, and service layer.
- [[Filament Architecture]] — Filament v5 clusters, resources, actions, and server-side authorization.
- [[Database Architecture]] — Table prefixing, foreign key policies, and multi-tenant schema.
- [[Authorization & Company Scoping]] — Spatie Shield RBAC, company query scoping, and tenant guards.

### [[Accounting Overview|02 - Accounting]]
Double-entry general ledger, bank processing, statement imports, and currency revaluations.
- [[Accounting Overview]] — General ledger topology and journal entry lifecycles.
- [[Chart of Accounts]] — Multi-tier account hierarchy and spreadsheet migration.
- [[Journal Entries]] — Move states, debit/credit balance invariants, and soft-delete guards.
- [[Bank Statements]] — Statement models, checksum deduplication, and line structures.
- [[Bank Import]] — Automated workbook parser, mapping engine, and journal generation.
- [[Reconciliation]] — Payment matching, residual balances, and double-posting guards.
- [[FS Tags]] — Financial statement tagging, matching rules, and journal line propagation.
- [[Exchange Rates]] — Multi-currency daily rates, rate approvals, and unrealized FX revaluation.
- [[Partner Analytics]] — Aged receivables/payables, customer DSO, and vendor DPO.

### [[HR Overview|03 - HR]]
Workforce operations, organizational hierarchy, mobile geofencing, and employee claims.
- [[HR Overview]] — People operations, organizational tree, and accounting bridge.
- [[Onboarding]] — Candidate recruitment, verification, and employee profile creation.
- [[Attendance]] — Haversine GPS geofencing, forgotten shift cron, and time corrections.
- [[Leave]] — Leave allocations, balance enforcement, and self-approval guards.
- [[Reviews]] — Performance cycles and appraisal reviews *(Unverified)*.
- [[Timesheets]] — Project task tracking and billable hour logs *(Unverified)*.
- [[Expenses]] — 9-category expense approval matrix, Tech Head routing, and auto-posted vendor bills.

### [[Procurement Overview|04 - P2P]]
Procure-to-Pay lifecycle, vendor contracts, purchase orders, and expenditure controls.
- [[Procurement Overview]] — Requisitioning, purchase order lifecycle, and AP bill generation.
- [[Purchase Orders]] — RFQ lifecycle, order states, line structures, and bill creation.
- [[Vendors]] — Master partner registry, NTN/STRN tax identities, and credit terms.
- [[Approvals]] — Multi-tiered threshold approvals and departmental routing.

### [[Document Architecture|05 - Documents & Drive]]
Cryptographic document storage, single-door architecture, and external cloud mirroring.
- [[Document Architecture]] — Tamper-evident storage, SHA-256 validation, and single-door pattern.
- [[Drive Integration]] — Google Drive client, classification engine, and one-way mirror model.
- [[Upload Workflow]] — Stream hashing, versioning, and polymorphic attachments.
- [[Sync Workflow]] — Asynchronous background queueing, path resolution, and status tracking.
- [[Retry & Failure Handling]] — Exponential backoff, network fault isolation, and integrity checks.

### [[Tax Overview|06 - Tax]]
Statutory Pakistani tax compliance, GST/SST calculations, and withholding tax regimes.
- [[Tax Overview]] — TaxManager engine, inclusive/exclusive modes, and partitions.
- [[Invoice Tax]] — Sales invoice GST calculation and statutory registration gates.
- [[Bill Tax]] — Input tax recovery and withholding tax (WHT) deductions.
- [[Sales Order Tax]] — Quotation tax estimation and line carryover.
- [[Purchase Order Tax]] — Commercial commitment calculations and gross budget controls.
- [[Tax Requirements]] — FBR / PRA / SRB statutory rules, mandatory STRN, and NTN tracking.

### [[Invoice Workflow|07 - Workflows]]
End-to-end execution maps connecting user interactions to ledger postings.
- [[Invoice Workflow]] — Sales order to customer invoice, payment, and reconciliation.
- [[Purchase Workflow]] — Requisition to purchase order, goods inward, and bill settlement.
- [[Approval Workflow]] — Tiered routing matrix, Tech Head path, and authorization rules.
- [[Document Workflow]] — Ingestion, verification, entity linking, and cloud sync.

### [[Testing Overview|08 - Testing]]
Automated Pest feature suites, mathematical ledger audits, and regression verification.
- [[Testing Overview]] — Testing architecture, environments, and quality baseline.
- [[Accounting Tests]] — Mathematical invariants ($Assets = Liabilities + Equity$), report parity, and double-posting checks.
- [[HR Tests]] — Geofencing accuracy, leave guardrails, and hierarchy tests.
- [[Regression Tests]] — Audit of defects DEF-001 through DEF-012 (all RETEST: PASS).
- [[Manual Testing]] — Step-by-step browser walkthroughs and UI verification protocols.

### [[Bugs|09 - Known Issues]]
Active tracking of remediated defects, technical debt, and architectural risks.
- [[Bugs]] — Registry of resolved critical defects and minor package warnings.
- [[Technical Debt]] — Upstream package fixture debt and queue processing recommendations.
- [[Risks]] — Financial integrity, multi-company leakage, and API rate limit risks.

### [[Architecture Decisions|10 - Decisions]]
Architectural and business decision records establishing system invariants.
- [[Architecture Decisions]] — ADR-001 through ADR-005: ERP database primacy, single-door storage, and soft-delete immunity.
- [[Business Decisions]] — BDR-001 through BDR-004: Single active tenant (Truck It In), Tech Head approval routing, and FBR tax rules.

---

## 🔒 Verification & Compliance Summary

- **Total Vault Notes**: 55 interconnected markdown notes.
- **Wikilink Integrity**: 100% interconnected bidirectional Obsidian links (`[[...]]`).
- **Codebase References**: Fully grounded in repository paths (`plugins/webkul/...`, `app/...`, `config/...`).
- **Source of Truth Priority**: Tested code and live database state take absolute precedence over assumptions.
