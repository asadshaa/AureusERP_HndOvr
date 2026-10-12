# Drive Integration

Status: Implemented (One-Way Mirror) / Verified
Related: [[Document Architecture]], [[Upload Workflow]], [[Sync Workflow]], [[Retry & Failure Handling]], [[Architecture Decisions]]

Aureus ERP integrates with Google Drive to provide external document browsing, automated receipt/invoice ingestion, and off-site document synchronization while maintaining ERP database primacy.

---

## 1. Architectural Scope: Option 1 vs Option 2

As documented in [[Architecture Decisions]] and `ACCOUNTING_DOCUMENT_STORAGE_ARCHITECTURE.md`:

### Implemented: Option 1 (One-Way ERP → Drive Mirror)
- **Primary Model**: ERP acts as the sole author and authoritative ledger.
- **Workflow**: Documents uploaded or generated in Aureus ERP (Customer Invoices, Vendor Bills, Bank Statements) are asynchronously pushed to designated Google Drive folder hierarchies.
- **Drive Failure Immunity**: If Google Drive is down or API limits are exhausted, ERP core accounting operations proceed without delay. Failed exports queue in `accounting_document_drive_syncs` for retry.

### Rejected / Future: Option 2 (Bidirectional Drive ↔ ERP Synchronization)
- Bidirectional editing was evaluated and explicitly deferred/rejected due to high audit and data integrity risks:
  - Conflicts with ERP immutable audit trails (`DocumentAudit`).
  - Risk of untracked overwrites or deletions of statutory financial evidence.
  - Multi-company authorization mismatches between Google Drive permissions and ERP RBAC.

---

## 2. Drive Ingestion Services

For incoming invoices and receipts deposited by staff into monitored Drive folders:
- **`GoogleDriveClient`**: Wrapper handling OAuth2 service account authentication, token renewal, folder traversal, and file stream downloading.
- **`DriveFolderPathResolver`**: Resolves structured folder paths according to company naming conventions:
  - Format: `[Company Name] / [Fiscal Year] / [Category] / [Document Type]`
  - Example: `Truck It In / 2026 / Invoices / INV-2026-0012`
- **`DriveClassificationService`**: Inspects file MIME types, filenames, and contents to classify incoming files into `DriveDocumentType` (`INVOICE`, `BILL`, `RECEIPT`, `STATEMENT`).
- **`PdfInvoiceTextExtractor`**: Local text and OCR parser extracting vendor NTN, invoice numbers, dates, line items, and totals from PDF files.
- **`DriveInvoicePostingService`**: Validates extracted payload and creates draft `AccountMove` entries ready for accountant review.

---

## 3. Asynchronous Export Engine

When a document is uploaded, updated, or finalized:
1. `DocumentContentChanged` event is fired.
2. `DispatchDriveSyncOnDocumentChanged` listener intercepts the event.
3. `SyncDocumentToDriveJob` is pushed to the queue.
4. Job creates or updates a record in `accounting_document_drive_syncs` (`status = 'pending'`).
5. Uploads the file stream to Google Drive and stores the Google Drive `file_id` and web link.
6. Updates `status = 'synced'` and logs completion.

---

## 4. Verification & Testing

- **Status**: Implemented & Verified.
- **Test Coverage**:
  - `DriveClassificationTest.php`
  - `DriveFolderPathResolverTest.php`
  - `DriveSyncExportTest.php`
  - `DriveIngestionEndToEndTest.php`
  - `FakeDriveClient.php` (Mocked test driver ensuring zero live Google API dependencies during automated Pest runs).
