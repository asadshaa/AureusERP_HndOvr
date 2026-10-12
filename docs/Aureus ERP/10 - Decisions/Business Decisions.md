# Business Decisions

Status: Documented / Active
Related: [[Architecture Decisions]], [[Project Overview]], [[Expenses]], [[Vendors]], [[Tax Requirements]]

This record captures key commercial and operational business policy decisions adopted for Aureus ERP deployments.

---

## BDR-001: Single Active Operational Entity (Truck It In Pvt Ltd)
- **Context**: The underlying Webkul ERP architecture supports multi-company deployments. During initial setup, 5 demo companies were generated alongside the live operating company.
- **Decision**: Client explicitly determined that the active system deployment is dedicated solely to **Truck It In (Pvt) Ltd**. Multi-company infrastructure is retained in codebase for future holding company expansion, but all 5 demo companies were permanently expunged from the database.
- **Impact**: All accounts, partners, journals, and transactions belong strictly to Truck It In. Partner records are isolated rather than shared globally.

---

## BDR-002: Technical Requisition Routing to Tech Head (Raza Afzal)
- **Context**: IT, software licenses, AWS/cloud hosting, and technical equipment requisitions previously followed general managerial approval lines, risking unvetted technical purchases.
- **Decision**: All technical expense claims and procurement requisitions must explicitly route to the **Tech Head** (Raza Afzal) as Tier 1 approver.
- **Impact**: HR hierarchy and expense category resolvers map technical items directly to the Tech Head queue. Approval by the Tech Head is a mandatory prerequisite for invoice generation and financial disbursement.

---

## BDR-003: Mandatory FBR / STRN Compliance on Sales Invoices
- **Context**: Unregistered business units or incomplete tax setups previously allowed drafting invoices without formal sales tax IDs.
- **Decision**: Invoices carrying sales tax (GST/SST) cannot be posted without an active sales tax registration flag and valid STRN on file for the issuing company.
- **Impact**: Prevents statutory non-compliance and legal liability under federal and provincial tax authorities (FBR, PRA, SRB).

---

## BDR-004: Prohibition of Silent Ledger Changes
- **Context**: In some legacy accounting packages, adjustments to posted periods can occur via background recalculation scripts.
- **Decision**: No silent or retroactive modifications to posted moves are permitted under any circumstances.
- **Impact**: Financial records maintain full legal admissibility, regulatory audit readiness, and cryptographic reproducibility.
